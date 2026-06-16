# Design: pos-end-of-day-bookkeeping-post

## Architecture Overview

The POS End-of-day Bookkeeping Post Pipeline implements a two-schema ledger integration layer:

1. **`posZReport`** — daily settlement aggregation, created by background job at configurable time
2. **`posJournalEntryOutbound`** — staging record for Shillinq submission, with idempotency key
   and retry state machine

A thin `PosBookkeepingService` orchestrates the transformation and submission flow.
All CRUD operations use `ObjectService` from the OpenRegister pattern. No custom database
tables. Shillinq communication is stateless (CloudEvent transport).

---

## Data Model (OpenRegister Schemas)

### posZReport (`schema:Invoice`)

Represents a daily end-of-day (Z-report) settlement, aggregating all confirmed/settled
transactions for a given date, terminal, and optionally payment method. Created daily
by the scheduled background job.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reference | string | Yes | Human-readable Z-report reference, e.g. "Z-2026-05-20-KAS01" (date-based) |
| reportDate | string (date) | Yes | The settlement date (not the creation date) in YYYY-MM-DD format |
| terminalId | string | No | POS terminal / register identifier, e.g. "kassa-01"; if null, report spans all terminals |
| store | string (uuid) | No | Optional reference to store/location entity (future use; may reference client) |
| transactionIds | array (string) | No | List of UUID references to aggregated `posTransaction` objects (for audit trail) |
| transactionCount | integer | No | Number of transactions in the report. Default: 0 |
| subtotal | number | No | Sum of all transaction subtotals (excl. tax). Default: 0 |
| discountTotal | number | No | Aggregate discount amount across all transactions. Default: 0 |
| taxBreakdown | array | No | Per-rate tax summary: `[{ "rate": 9, "base": 100.00, "tax": 9.00 }, { "rate": 21, "base": 200.00, "tax": 42.00 }]` |
| totalTax | number | No | Aggregate tax (sum of taxBreakdown[*].tax). Default: 0 |
| total | number | No | Grand total incl. tax. Default: 0 |
| paymentMethodBreakdown | array | No | Per-payment method totals: `[{ "method": "cash", "amount": 350.00 }, { "method": "card", "amount": 150.00 }]` |
| createdAt | string (date-time) | Yes | ISO 8601 timestamp when Z-report was auto-generated |
| settledAt | string (date-time) | No | ISO 8601 timestamp when transactions were settled (usually same as reportDate + settlement time) |
| status | string | Yes | Lifecycle: `draft` \| `ready` \| `submitted` \| `posted` \| `failed` \| `reconciled`. Default: `draft` |
| notes | string | No | Optional notes, e.g. "Manual adjustment +5,50 EUR for overage" |

**Status transitions:**

```
draft ──► ready         (auto-transition after generation)
ready ──► submitted ──► posted ──► reconciled
ready ──► submitted ──► failed ──► ready (manual resubmit)
```

---

### posJournalEntryOutbound (`schema:Message`)

Staging entity for a Shillinq journal entry submission. One-to-one link to `posZReport`.
Tracks submission status, idempotency key, retry attempts, and Shillinq response metadata.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| zReport | string (uuid) | Yes | Reference to the parent `posZReport` |
| idempotencyKey | string | Yes | SHA256 hash of `posZReport.uuid + reportDate` for duplicate detection at Shillinq |
| payloadVersion | integer | No | Payload schema version for backward compatibility. Default: 1 |
| ledgerLineItems | array | No | Transformed GL entries: `[{ "account": "1000", "debit": 350.00, "credit": 0, "description": "POS Revenue - 21% VAT", "taxRate": 21 }]` |
| postingDate | string (date) | No | GL posting date (usually same as Z-report reportDate) |
| glReference | string | No | External GL batch reference from Shillinq response |
| status | string | Yes | Submission state: `draft` \| `pending` \| `posted` \| `failed`. Default: `draft` |
| submissionAttempts | array | No | Log of submission attempts: `[{ "timestamp": "2026-05-20T23:59:00Z", "status": 202, "message": "Accepted", "eventId": "evt-123" }, { "timestamp": "2026-05-21T00:04:00Z", "status": 500, "message": "Service unavailable" }]` |
| attemptCount | integer | No | Number of submission attempts. Default: 0 |
| lastAttemptAt | string (date-time) | No | Timestamp of the most recent submission attempt |
| nextRetryAt | string (date-time) | No | Scheduled time for next retry (if status=failed). Null if success. |
| cloudEventId | string | No | ID of the emitted `pipelinq.PosJournalEntry.posted` CloudEvent (if posted) |
| shillinqEventId | string | No | CloudEvent ID returned by Shillinq in the 202/201 response |
| shillinqJournalEntryId | string | No | UUID reference to the created JournalEntry object in Shillinq (if reconciled) |
| lastErrorMessage | string | No | Most recent error message from Shillinq or transport layer |
| lastErrorCode | string | No | HTTP status or error code (e.g., "422", "NETWORK_TIMEOUT") |

**Status transitions:**

```
draft ──► pending ──► posted
draft ──► pending ──► failed ──► pending (scheduled retry)
posted ──► reconciled          (after Shillinq confirms balance check)
```

---

## GL Account Mapping Configuration

GL account mapping is stored in `pipelinq_register.json` under a new `glAccountMapping` schema.
Optionally, mapping can be configured via the admin settings UI.

### glAccountMapping schema (configuration)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Mapping profile name, e.g. "Standard VAT Mapping - 2026" |
| isDefault | boolean | No | Whether this is the default mapping. Default: false |
| taxRateMappings | array | Yes | Per-VAT-rate GL account pairs: `[{ "taxRate": 0, "debitAccount": "1200", "creditAccount": "5100" }, { "taxRate": 9, "debitAccount": "1200", "creditAccount": "5010" }, { "taxRate": 21, "debitAccount": "1200", "creditAccount": "5000" }]` |
| discountAccount | string | No | GL account for line-level / transaction-level discounts, e.g. "5900" |
| refundAccount | string | No | GL account for refund / void transactions |
| bankAccount | string | No | GL bank/cash clearing account, e.g. "1000" |

---

## Seed Data

Seed objects are added to `lib/Settings/pipelinq_register.json` under `components.objects[]`.

> **Note (superseded syntax):** the `<<zrep-…>>` reference tokens shown below were the original proposal. The implemented OpenRegister import resolver uses **`@ref:<slug>`** (with `@ref:<schema>:<slug>` for disambiguation) — see `project-task-hierarchy`. Seed files and the resolver use `@ref:`; the `<<…>>` form is not processed. Read the examples below with that substitution.

### posZReport seeds (4 objects)

```json
[
  {
    "@self": { "register": "pipelinq", "schema": "posZReport", "slug": "zrep-2026-05-20-kas01" },
    "reference": "Z-2026-05-20-KAS01",
    "reportDate": "2026-05-20",
    "terminalId": "kassa-01",
    "transactionCount": 42,
    "subtotal": 450.00,
    "discountTotal": 15.00,
    "taxBreakdown": [
      { "rate": 9, "base": 50.00, "tax": 4.50 },
      { "rate": 21, "base": 385.00, "tax": 80.85 }
    ],
    "totalTax": 85.35,
    "total": 535.35,
    "paymentMethodBreakdown": [
      { "method": "cash", "amount": 300.00 },
      { "method": "card", "amount": 235.35 }
    ],
    "status": "posted",
    "notes": "Normale werkdag"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posZReport", "slug": "zrep-2026-05-21-kas01" },
    "reference": "Z-2026-05-21-KAS01",
    "reportDate": "2026-05-21",
    "terminalId": "kassa-01",
    "transactionCount": 38,
    "subtotal": 380.00,
    "discountTotal": 10.00,
    "taxBreakdown": [
      { "rate": 9, "base": 40.00, "tax": 3.60 },
      { "rate": 21, "base": 330.00, "tax": 69.30 }
    ],
    "totalTax": 72.90,
    "total": 452.90,
    "paymentMethodBreakdown": [
      { "method": "cash", "amount": 250.00 },
      { "method": "card", "amount": 202.90 }
    ],
    "status": "pending",
    "notes": "Wachten op Shillinq posting"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posZReport", "slug": "zrep-2026-05-22-kas02" },
    "reference": "Z-2026-05-22-KAS02",
    "reportDate": "2026-05-22",
    "terminalId": "kassa-02",
    "transactionCount": 25,
    "subtotal": 200.00,
    "discountTotal": 5.00,
    "taxBreakdown": [
      { "rate": 21, "base": 195.00, "tax": 40.95 }
    ],
    "totalTax": 40.95,
    "total": 240.95,
    "paymentMethodBreakdown": [
      { "method": "card", "amount": 240.95 }
    ],
    "status": "failed",
    "notes": "Submission error - retry scheduled"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posZReport", "slug": "zrep-2026-05-23-kas01" },
    "reference": "Z-2026-05-23-KAS01",
    "reportDate": "2026-05-23",
    "terminalId": "kassa-01",
    "transactionCount": 0,
    "subtotal": 0,
    "discountTotal": 0,
    "taxBreakdown": [],
    "totalTax": 0,
    "total": 0,
    "paymentMethodBreakdown": [],
    "status": "draft",
    "notes": "Winkel gesloten"
  }
]
```

### posJournalEntryOutbound seeds (3 objects)

```json
[
  {
    "@self": { "register": "pipelinq", "schema": "posJournalEntryOutbound", "slug": "je-out-2026-05-20-kas01" },
    "zReport": "<<zrep-2026-05-20-kas01>>",
    "idempotencyKey": "sha256:a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0",
    "payloadVersion": 1,
    "ledgerLineItems": [
      { "account": "1200", "debit": 535.35, "credit": 0, "description": "POS Revenue - Cash & Card", "taxRate": null },
      { "account": "1000", "debit": 0, "credit": 535.35, "description": "Bank/Cash Clearing", "taxRate": null }
    ],
    "postingDate": "2026-05-20",
    "status": "posted",
    "submissionAttempts": [
      { "timestamp": "2026-05-20T23:59:30Z", "status": 202, "message": "Accepted", "eventId": "evt-2026-05-20-001" }
    ],
    "attemptCount": 1,
    "lastAttemptAt": "2026-05-20T23:59:30Z",
    "cloudEventId": "evt-pipelinq-2026-05-20-001",
    "shillinqEventId": "evt-shillinq-2026-05-20-001",
    "shillinqJournalEntryId": "je-shillinq-2026-05-20-001"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posJournalEntryOutbound", "slug": "je-out-2026-05-21-kas01" },
    "zReport": "<<zrep-2026-05-21-kas01>>",
    "idempotencyKey": "sha256:b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1",
    "payloadVersion": 1,
    "ledgerLineItems": [
      { "account": "1200", "debit": 452.90, "credit": 0, "description": "POS Revenue - Cash & Card", "taxRate": null },
      { "account": "1000", "debit": 0, "credit": 452.90, "description": "Bank/Cash Clearing", "taxRate": null }
    ],
    "postingDate": "2026-05-21",
    "status": "pending",
    "submissionAttempts": [
      { "timestamp": "2026-05-21T23:59:45Z", "status": 202, "message": "Accepted", "eventId": "evt-2026-05-21-001" }
    ],
    "attemptCount": 1,
    "lastAttemptAt": "2026-05-21T23:59:45Z"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posJournalEntryOutbound", "slug": "je-out-2026-05-22-kas02" },
    "zReport": "<<zrep-2026-05-22-kas02>>",
    "idempotencyKey": "sha256:c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2",
    "payloadVersion": 1,
    "ledgerLineItems": [
      { "account": "1200", "debit": 240.95, "credit": 0, "description": "POS Revenue - Card", "taxRate": null },
      { "account": "1000", "debit": 0, "credit": 240.95, "description": "Bank/Cash Clearing", "taxRate": null }
    ],
    "postingDate": "2026-05-22",
    "status": "failed",
    "submissionAttempts": [
      { "timestamp": "2026-05-22T23:59:15Z", "status": 503, "message": "Service Unavailable" },
      { "timestamp": "2026-05-23T00:04:20Z", "status": 503, "message": "Service Unavailable" }
    ],
    "attemptCount": 2,
    "lastAttemptAt": "2026-05-23T00:04:20Z",
    "nextRetryAt": "2026-05-23T00:19:20Z",
    "lastErrorMessage": "Service Unavailable",
    "lastErrorCode": "503"
  }
]
```

---

## API Contracts

### POST /index.php/apps/pipelinq/api/pos-bookkeeping/post

**Purpose**: Manually trigger or resubmit a Z-report / journal entry to Shillinq.

**Request**:
```json
{
  "outboundMessageId": "uuid",
  "forceResubmit": false
}
```

**Response** (202 Accepted if submitted; 422 if preconditions fail):
```json
{
  "status": "pending",
  "idempotencyKey": "sha256:...",
  "nextRetryAt": "2026-05-23T00:19:20Z"
}
```

**Error responses**:
- `422 Unprocessable Entity`: Outbound message not in draft or failed state
- `403 Forbidden`: User lacks accounting role
- `404 Not Found`: Outbound message ID not found

---

## CloudEvent Emission

### pipelinq.PosJournalEntry.posted

Emitted when a journal entry submission succeeds (Shillinq returns 202/201).

```json
{
  "specversion": "1.0",
  "type": "pipelinq.PosJournalEntry.posted",
  "source": "https://nextcloud.example.org/apps/pipelinq",
  "id": "evt-pipelinq-2026-05-20-001",
  "time": "2026-05-20T23:59:30Z",
  "datacontenttype": "application/json",
  "dataschema": "https://pipelinq.local/schemas/PosJournalEntry",
  "subject": "zrep-2026-05-20-kas01",
  "data": {
    "zReportId": "uuid",
    "outboundMessageId": "uuid",
    "idempotencyKey": "sha256:...",
    "shillinqJournalEntryId": "uuid",
    "shillinqEventId": "evt-shillinq-2026-05-20-001",
    "total": 535.35,
    "currency": "EUR"
  }
}
```

### pipelinq.PosZReport.submitted

Emitted on successful Z-report generation and initial submission trigger.

```json
{
  "specversion": "1.0",
  "type": "pipelinq.PosZReport.submitted",
  "source": "https://nextcloud.example.org/apps/pipelinq",
  "id": "evt-pipelinq-zrep-2026-05-20-001",
  "time": "2026-05-20T23:59:00Z",
  "datacontenttype": "application/json",
  "subject": "zrep-2026-05-20-kas01",
  "data": {
    "zReportId": "uuid",
    "reportDate": "2026-05-20",
    "terminalId": "kassa-01",
    "transactionCount": 42,
    "total": 535.35,
    "currency": "EUR"
  }
}
```

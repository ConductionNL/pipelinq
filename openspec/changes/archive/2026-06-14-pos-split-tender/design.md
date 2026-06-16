# Design: pos-split-tender

## Architecture Overview

Two new OpenRegister schemas (`posTender`, `posTenderType`) are added to `pipelinq_register.json`.
A new `PosPaymentService` validates tender combinations and computes change. The existing
`posTransaction` settlement logic is extended to verify tender sum before confirming/settling.
GL posting is handled by existing CloudEvent + shillinq integration on transaction settlement.

All CRUD and list operations use `ObjectService` via the existing `createObjectStore` pattern.

---

## Data Model (OpenRegister Schemas)

### posTenderType

Defines an available tender method (payment type) that can be configured per register/location.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Display name (e.g., "Contant", "Betaalpas", "Cadeaubon", "Rekening") |
| code | string | Yes | Constant identifier: CASH, CARD, VOUCHER, ACCOUNT, MOLLIE, STRIPE, SUM_UP |
| description | string | No | Description of this tender type |
| glAccount | string | Yes | GL account number in shillinq (e.g., "1100" for kas/cash, "1200" for bank) |
| requiresReference | boolean | No | Whether this tender type requires external reference (card receipt, voucher code). Default: false |
| requiresPin | boolean | No | Whether PIN verification is required (CARD only). Default: false |
| allowsChange | boolean | No | Whether change is calculated for overpayment (CASH only). Default: false |
| isActive | boolean | No | Whether this tender type is available for new sales. Default: true |
| sortOrder | integer | No | Display order in tender selection UI. Default: 0 |

### posTender

Represents a single payment method instance on a transaction.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| transaction | string (uuid) | Yes | UUID reference to parent `posTransaction` |
| tenderType | string (uuid) | Yes | UUID reference to the `posTenderType` |
| amount | number | Yes | Amount paid with this tender in EUR (≥ 0.01) |
| reference | string | No | External reference (card auth code, voucher serial, etc.) |
| glAccount | string | No | GL account posted to (copied from `tenderType.glAccount` at tender add time) |
| notes | string | No | Optional notes (e.g., "payment declined, retry", "partial voucher") |
| sortOrder | integer | No | Display order in tender list. Default: 0 |

**Invariant**: sum of all `posTender.amount` on a transaction MUST equal `posTransaction.total` before settlement.

---

## Backend Services

### PosPaymentService (`lib/Service/PosPaymentService.php`)

- `getTenderTypeByCode(string $code): array` — Look up tender type by code
- `validateTenderSum(string $transactionId): array` — Verify tender sum equals transaction total, return variance and change owed
- `calculateChange(float $cashTenderedAmount, float $transactionTotal): float` — Return change amount if overpaid
- `addTender(string $transactionId, array $tender): array` — Add tender to transaction; validate amount ≥ 0.01
- `removeTender(string $transactionId, string $tenderId): void` — Remove tender; throw if transaction already settled
- `getTendersForTransaction(string $transactionId): array` — List all tenders for transaction, sorted by sortOrder

### Updated PosTransactionService (`lib/Service/PosTransactionService.php`)

Before settlement (confirm → settled), verify:
```php
$tenderSum = $this->paymentService->validateTenderSum($transactionId);
if ($tenderSum['variance'] !== 0.0) {
  throw new InvalidTenderException(
    sprintf("Tender sum does not equal total. Difference: %s", $tenderSum['variance'])
  );
}
```

---

## Backend API Routes

### TenderType Endpoints

| Method | URL | Auth | Action |
|--------|-----|------|--------|
| GET | `/api/pos/tender-types` | `#[NoAdminRequired]` | List all tender types |
| GET | `/api/pos/tender-types/{id}` | `#[NoAdminRequired]` | Get tender type detail |
| POST | `/api/pos/tender-types` | admin | Create tender type |
| PUT | `/api/pos/tender-types/{id}` | admin | Update tender type |
| DELETE | `/api/pos/tender-types/{id}` | admin | Delete tender type; throw if any active tenders reference it |

### Tender Endpoints

| Method | URL | Auth | Action |
|--------|-----|------|--------|
| GET | `/api/pos/transactions/{id}/tenders` | `#[NoAdminRequired]` | List tenders on transaction |
| POST | `/api/pos/transactions/{id}/tenders` | `#[NoAdminRequired]` | Add tender to transaction (validates against tenderType) |
| DELETE | `/api/pos/transactions/{id}/tenders/{tenderId}` | `#[NoAdminRequired]` | Remove tender (only if transaction not settled) |

---

## Frontend

### Changes to Transaction Detail

- New "Tenders" section below the line items table, showing list of tenders with type, amount, and GL account
- "Add Tender" button opens modal:
  - Dropdown to select `posTenderType`
  - Amount input field
  - Conditional "Reference" field if `tenderType.requiresReference = true`
  - Submit posts to `POST /api/pos/transactions/{id}/tenders`
- Display of running total of tendered amounts
- Change calculation display if CASH tender overpays (shown as green hint)
- "Remove" button per tender line (disabled if transaction settled)

### Tender Selection UI

At payment/checkout screen, tender selection filtered to show only `isActive = true` tender types, sorted by `sortOrder`.

---

## GL Posting Integration

When `posTransaction` status transitions to `settled`:

1. For each `posTender` on the transaction:
   - Emit CloudEvent with `type: "nl.pipelinq.pos.tender.posted"`
   - Payload includes transaction reference, tender type, amount, GL account
2. shillinq app subscribes to event and posts debit/credit entry to GL account
3. Failure to post (shillinq unavailable) does NOT block settlement; event is retried via background job

---

## Seed Data

3 `posTenderType` seed objects added to `lib/Settings/pipelinq_register.json`:

```json
[
  {
    "@self": { "register": "pipelinq", "schema": "posTenderType", "slug": "tender-cash" },
    "name": "Contant",
    "code": "CASH",
    "glAccount": "1100",
    "allowsChange": true,
    "sortOrder": 1
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTenderType", "slug": "tender-card" },
    "name": "Betaalpas",
    "code": "CARD",
    "glAccount": "1200",
    "requiresPin": true,
    "requiresReference": true,
    "sortOrder": 2
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTenderType", "slug": "tender-voucher" },
    "name": "Cadeaubon",
    "code": "VOUCHER",
    "glAccount": "2100",
    "requiresReference": true,
    "sortOrder": 3
  }
]
```

5 `posTender` seed objects linked to existing `posTransaction` seeds:

```json
[
  {
    "@self": { "register": "pipelinq", "schema": "posTender", "slug": "tender-txn-0001-cash" },
    "transaction": "txn-2026-0001",
    "tenderType": "tender-cash",
    "amount": 21.53,
    "glAccount": "1100"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTender", "slug": "tender-txn-0003-partial-cash" },
    "transaction": "txn-2026-0003",
    "tenderType": "tender-cash",
    "amount": 40.00,
    "glAccount": "1100",
    "notes": "Contant voorbetaling"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTender", "slug": "tender-txn-0003-card" },
    "transaction": "txn-2026-0003",
    "tenderType": "tender-card",
    "amount": 57.97,
    "reference": "AUTH123456",
    "glAccount": "1200"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTender", "slug": "tender-txn-0004-cash-with-change" },
    "transaction": "txn-2026-0004",
    "tenderType": "tender-cash",
    "amount": 50.00,
    "glAccount": "1100",
    "notes": "Betaald met 50 euro, wisselgeld €22.80"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTender", "slug": "tender-txn-0005-voucher" },
    "transaction": "txn-2026-0005",
    "tenderType": "tender-voucher",
    "amount": 703.30,
    "reference": "VOC-2024-987654",
    "glAccount": "2100"
  }
]
```

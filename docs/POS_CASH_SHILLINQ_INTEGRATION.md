<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->

# POS Cash Management — Shillinq Integration

This document describes how the Pipelinq POS cash-drawer reconciliation flow
posts approved cash variances to Shillinq for accounting (GL) adjustment.

## Overview

When a shift manager approves a cash variance (`cashDiff`), `CashShiftService`
emits a CloudEvent of type `pipelinq.CashDiff.confirmed` through OpenRegister's
`WebhookService`. Shillinq subscribes to this event and posts a general-ledger
adjustment entry against the Cash account. This closes the accounting loop:

- **Sales** are recorded as `posTransaction` objects (pos-transaction-core).
- **Cash** is reconciled per shift (pos-cash-management): opening float, drops,
  blind count and the derived variance.
- **Variances** are posted to the GL by Shillinq on `pipelinq.CashDiff.confirmed`.

The event is fire-and-forget: a missing subscriber never blocks the approval.
All monetary figures in the payload are server-authoritative — they are derived
by `CashShiftService` from persisted data, never taken from the client.

## CloudEvent: `pipelinq.CashDiff.confirmed`

Emitted on `CashShiftService::approveDiff()`. CloudEvents v1.0 envelope:

```json
{
  "specversion": "1.0",
  "type": "pipelinq.CashDiff.confirmed",
  "source": "pipelinq/cashShift",
  "id": "<uuid>",
  "time": "<ISO 8601>",
  "subject": "<shift reference>",
  "datacontenttype": "application/json",
  "data": {
    "shift_id": "<uuid>",
    "drawer": "kassa-01",
    "diff_amount": 3.25,
    "diff_percentage": 0.52,
    "expected_amount": 620.00,
    "actual_amount": 623.25,
    "approved_by": "manager1",
    "approved_at": "2026-05-21T22:00:00Z"
  }
}
```

### `data` fields

| Field             | Type           | Description                                                        |
| ----------------- | -------------- | ------------------------------------------------------------------ |
| `shift_id`        | string (uuid)  | The reconciled `cashShift` id.                                     |
| `drawer`          | string         | The drawer / register identifier.                                 |
| `diff_amount`     | number         | Actual minus expected, in EUR. Positive = overage, negative = shortage. |
| `diff_percentage` | number \| null | `diff_amount / expected_amount * 100`; `null` when expected is €0. |
| `expected_amount` | number         | `floatAmount + confirmedSales − drops`.                           |
| `actual_amount`   | number         | The blind-counted cash total.                                     |
| `approved_by`     | string         | UID of the approving manager (server-set from the session).       |
| `approved_at`     | string         | ISO 8601 approval timestamp (server-set).                         |

## Webhook Subscription (Shillinq)

Shillinq registers an OpenRegister webhook subscription for the event type
`pipelinq.CashDiff.confirmed`. Example subscription configuration:

```json
{
  "name": "Shillinq cash-variance GL posting",
  "events": ["pipelinq.CashDiff.confirmed"],
  "endpoint": "https://shillinq.example.org/webhooks/pipelinq/cash-diff",
  "active": true
}
```

The endpoint must respond `2xx` to acknowledge receipt. Delivery is independent
of the approval transaction; retries follow the OpenRegister webhook delivery
policy.

## Expected GL Adjustment Posting (Shillinq side)

On receiving `pipelinq.CashDiff.confirmed`, Shillinq:

1. Reads `diff_amount` and `diff_percentage` from `data`.
2. Posts a GL adjustment journal entry against the **Cash** account:
   - A positive `diff_amount` (overage) → debit Cash / credit Cash-Over-Short.
   - A negative `diff_amount` (shortage) → credit Cash / debit Cash-Over-Short.
   - The line amount is `|diff_amount|`.
3. Dates and time-stamps the entry from `approved_at`.
4. Sets the reference to `subject` (the shift reference) plus a "cash diff"
   marker and links the entry back to `shift_id` for traceability.

When `diff_amount` is `0.00`, Shillinq may post a zero-value reconciliation
marker or skip the entry, per its own accounting policy.

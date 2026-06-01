# POS Cash Management — Shillinq Integration

## Overview

When a shift manager approves a cash variance (diff), Pipelinq emits a
`pipelinq.CashDiff.confirmed` CloudEvent. Shillinq subscribes to this event
and posts a GL adjustment entry for the variance amount.

---

## CloudEvent Schema

**Type:** `pipelinq.CashDiff.confirmed`  
**Source:** `pipelinq/cashShift`  
**Spec version:** `1.0`

### Envelope

```json
{
  "specversion": "1.0",
  "type": "pipelinq.CashDiff.confirmed",
  "source": "pipelinq/cashShift",
  "id": "<uuid-v4>",
  "time": "2026-05-21T22:00:00+02:00",
  "subject": "SHIFT-2026-0521-01",
  "datacontenttype": "application/json",
  "data": {
    "shift_id": "uuid-of-the-cashShift",
    "drawer": "kassa-01",
    "diff_amount": 3.25,
    "diff_percentage": 0.52,
    "expected_amount": 620.00,
    "actual_amount": 623.25,
    "approved_by": "manager1",
    "approved_at": "2026-05-21T22:00:00+02:00"
  }
}
```

### Field Reference

| Field            | Type           | Description                                                     |
|------------------|----------------|-----------------------------------------------------------------|
| `shift_id`       | string (uuid)  | UUID of the approved `cashShift` object                         |
| `drawer`         | string         | Cash register / drawer identifier, e.g. `kassa-01`             |
| `diff_amount`    | number         | Variance in EUR: positive = overage, negative = shortage        |
| `diff_percentage`| number or null | `(diff / expected) × 100`; null when expected amount is zero   |
| `expected_amount`| number         | Calculated: `floatAmount + salesTotal − dropsTotal`             |
| `actual_amount`  | number         | Physical count recorded by the cashier                          |
| `approved_by`    | string         | Nextcloud user UID of the approving manager                     |
| `approved_at`    | string         | ISO 8601 timestamp of approval                                  |

---

## Shillinq Webhook Subscription

Configure a webhook in Shillinq (or via OpenRegister's WebhookService) to
subscribe to the event type `pipelinq.CashDiff.confirmed`.

### Example subscription payload

```json
{
  "name": "Pipelinq Cash Diff → GL Adjustment",
  "url": "https://shillinq.example.nl/webhooks/cash-diff",
  "event": "pipelinq.CashDiff.confirmed",
  "method": "POST",
  "headers": {
    "Authorization": "Bearer <shillinq-api-token>"
  }
}
```

---

## Expected GL Adjustment Posting Flow

When Shillinq receives the `pipelinq.CashDiff.confirmed` event:

1. **Read** `data.diff_amount` (the variance in EUR).
2. **Determine account direction:**
   - If `diff_amount > 0` (overage): credit the Cash account, debit an
     Over/Short account.
   - If `diff_amount < 0` (shortage): debit the Cash account, credit the
     Over/Short account.
3. **Create a GL adjustment entry:**
   - Account: Cash (debit/credit based on sign)
   - Amount: `|diff_amount|` EUR
   - Date: `data.approved_at`
   - Reference: `data.shift_id` + `SHIFT-{reference}`
   - Description: `Kassaverschil goedgekeurd — shift {shift_id}, lade {drawer}`
4. **Link** the GL entry back to the shift UUID (`data.shift_id`) for
   traceability and audit.
5. **Mark** the event as processed (idempotency check on `id` field).

---

## Diff Calculation Formula

The variance is computed server-side in `CashShiftService::calculateDiff()`:

```
salesTotal    = SUM(posTransaction.total) WHERE status IN (confirmed, settled)
                AND confirmedAt BETWEEN shift.floatAt AND shift.closedAt
dropsTotal    = SUM(cashDrop.amount) WHERE shift = shiftId
expectedAmount = floatAmount + salesTotal − dropsTotal
diffAmount     = actualAmount − expectedAmount       (from cashCount.amount)
diffPercentage = (diffAmount / expectedAmount) × 100 (null when expected = 0)
withinTolerance = |diffPercentage| ≤ 2.0             (default tolerance)
```

A diff requires explicit manager approval via `POST /api/pos-shifts/{diffId}/diff/approve`
before the CloudEvent is emitted.

---

## Notes

- The event is **fire-and-forget**: if Shillinq is unavailable, the approval
  still succeeds and the eventId is not stored on the `cashDiff` object.
- The `cloudEventId` field on the `cashDiff` schema stores the emitted event's
  UUID for traceability once emission succeeds.
- Shillinq should implement idempotency on the `id` (CloudEvent UUID) to
  prevent duplicate GL entries if the webhook is retried.

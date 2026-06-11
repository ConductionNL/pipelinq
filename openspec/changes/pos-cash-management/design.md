# Design: pos-cash-management

## Architecture Overview

Four new OpenRegister schemas (`cashShift`, `cashDrop`, `cashCount`, `cashDiff`) are added to `pipelinq_register.json`. A backend `CashShiftService` handles shift lifecycle transitions, diff calculation, and CloudEvent emission. All CRUD and list operations use `ObjectService` directly from the existing `createObjectStore` pattern. No custom database tables are introduced.

The shift opening captures a declared float. Mid-shift drops reduce the expected cash. At close, a physical count is recorded. The diff is calculated as: `expected = float + salesTotal − dropsTotal`, then `diff = actual − expected`. A tolerance rule (e.g., ±2%) determines if the count auto-confirms or requires manager approval.

---

## Data Model (OpenRegister Schemas)

### cashShift

Represents a drawer's shift period — from open (with declared float) to close. Tracks the currency denomination (EUR), open/close status, and reconciliation outcome.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reference | string | No | Shift reference, e.g. "SHIFT-2026-0521-01" |
| drawer | string | No | Drawer / register identifier, e.g. "kassa-01" |
| operator | string | Yes | Nextcloud user UID of the cashier opening the shift |
| managedBy | string | No | Nextcloud user UID of the manager reviewing the close |
| currency | string | No | Currency code. Default: "EUR" |
| floatAmount | number | Yes | Declared opening float in EUR |
| floatAt | string (date-time) | Yes | ISO 8601 timestamp when float was declared |
| status | string | Yes | Shift status: `open` \| `closed` \| `reconciled`. Default: `open` |
| closedAt | string (date-time) | No | ISO 8601 timestamp of shift close (when count was recorded) |
| reconciliationStatus | string | No | Variance status: `pending` \| `approved` \| `rejected`. Default: `pending` |
| notes | string | No | Shift notes, e.g. opening remarks |

**Status transitions:**
```
open → closed (when count is recorded)
closed → reconciled (when diff is confirmed)
```

---

### cashDrop

Records a mid-shift removal of cash from the drawer — e.g. to deposit with a manager or send to a safe.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| shift | string (uuid) | Yes | UUID reference to the parent `cashShift` |
| amount | number | Yes | Drop amount in EUR |
| reason | string | No | Reason code or description, e.g. "manager-deposit", "bank-run" |
| droppedAt | string (date-time) | Yes | ISO 8601 timestamp of the drop |
| droppedBy | string | Yes | Nextcloud user UID of who performed the drop |

---

### cashCount

Records a physical count of cash in the drawer at a point in time (usually shift close). For blind count, the count is entered without prior knowledge of expected amount.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| shift | string (uuid) | Yes | UUID reference to the parent `cashShift` |
| amount | number | Yes | Total cash amount counted, in EUR |
| countedAt | string (date-time) | Yes | ISO 8601 timestamp of the count |
| countedBy | string | Yes | Nextcloud user UID of the counter |
| notes | string | No | Counter notes, e.g. "includeert €50 vreemd geld" |
| denominationBreakdown | array | No | Optional breakdown by denomination: `[{ "denomination": "EUR_50", "quantity": 2, "subtotal": 100.00 }]` |

---

### cashDiff

Variance report calculated at shift close. Compares expected cash (float + transaction sales − drops) to actual (count amount). Stores the difference, tolerance assessment, and reconciliation decision.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| shift | string (uuid) | Yes | UUID reference to the parent `cashShift` |
| count | string (uuid) | Yes | UUID reference to the physical count recorded |
| expectedAmount | number | Yes | Expected cash: `floatAmount + salesTotal − dropsTotal` |
| actualAmount | number | Yes | Actual amount from the count |
| diffAmount | number | No | Computed: `actualAmount − expectedAmount` (negative = shortage, positive = overage) |
| diffPercentage | number | No | Computed: `(diffAmount / expectedAmount) × 100` |
| tolerancePercentage | number | No | Tolerance threshold for auto-approval. Default: 2 |
| withinTolerance | boolean | No | `true` if `|diffPercentage| ≤ tolerancePercentage` |
| status | string | Yes | Reconciliation status: `pending` \| `approved` \| `rejected`. Default: `pending` |
| approvedBy | string | No | Nextcloud user UID of the approver (if manager-approved) |
| approvedAt | string (date-time) | No | ISO 8601 timestamp of approval |
| cloudEventId | string | No | CloudEvents `id` of the emitted diff event |

---

## Seed Data

Seed objects are added to `lib/Settings/pipelinq_register.json` under `components.objects[]` using the `@self` envelope.

> **Note (superseded syntax):** the `<<schema:slug>>` reference tokens shown below were the original proposal. The implemented OpenRegister import resolver uses **`@ref:<slug>`** (with `@ref:<schema>:<slug>` for disambiguation) — see `project-task-hierarchy`. Seed files and the resolver use `@ref:`; the `<<…>>` form is not processed. Read the examples below with that substitution.

### cashShift seeds (3 objects)

```json
[
  {
    "@self": { "register": "pipelinq", "schema": "cashShift", "slug": "shift-2026-0520-01" },
    "reference": "SHIFT-2026-0520-01",
    "drawer": "kassa-01",
    "operator": "admin",
    "managedBy": "manager1",
    "currency": "EUR",
    "floatAmount": 100.00,
    "floatAt": "2026-05-20T06:00:00+02:00",
    "status": "reconciled",
    "closedAt": "2026-05-20T21:00:00+02:00",
    "reconciliationStatus": "approved"
  },
  {
    "@self": { "register": "pipelinq", "schema": "cashShift", "slug": "shift-2026-0521-01" },
    "reference": "SHIFT-2026-0521-01",
    "drawer": "kassa-02",
    "operator": "cashier1",
    "currency": "EUR",
    "floatAmount": 75.00,
    "floatAt": "2026-05-21T06:00:00+02:00",
    "status": "open",
    "notes": "Opening float verified by manager"
  },
  {
    "@self": { "register": "pipelinq", "schema": "cashShift", "slug": "shift-2026-0521-02" },
    "reference": "SHIFT-2026-0521-02",
    "drawer": "kassa-01",
    "operator": "cashier2",
    "currency": "EUR",
    "floatAmount": 100.00,
    "floatAt": "2026-05-21T14:00:00+02:00",
    "status": "closed",
    "closedAt": "2026-05-21T22:00:00+02:00"
  }
]
```

### cashDrop seeds (3 objects)

```json
[
  {
    "@self": { "register": "pipelinq", "schema": "cashDrop", "slug": "drop-2026-0521-01" },
    "shift": "<<cashShift:shift-2026-0520-01>>",
    "amount": 250.00,
    "reason": "manager-deposit",
    "droppedAt": "2026-05-20T13:00:00+02:00",
    "droppedBy": "admin"
  },
  {
    "@self": { "register": "pipelinq", "schema": "cashDrop", "slug": "drop-2026-0521-02" },
    "shift": "<<cashShift:shift-2026-0521-01>>",
    "amount": 150.00,
    "reason": "bank-run",
    "droppedAt": "2026-05-21T11:00:00+02:00",
    "droppedBy": "manager1"
  },
  {
    "@self": { "register": "pipelinq", "schema": "cashDrop", "slug": "drop-2026-0521-03" },
    "shift": "<<cashShift:shift-2026-0521-01>>",
    "amount": 200.00,
    "reason": "security-removal",
    "droppedAt": "2026-05-21T18:00:00+02:00",
    "droppedBy": "manager1"
  }
]
```

### cashCount seeds (3 objects)

```json
[
  {
    "@self": { "register": "pipelinq", "schema": "cashCount", "slug": "count-2026-0520-01" },
    "shift": "<<cashShift:shift-2026-0520-01>>",
    "amount": 487.50,
    "countedAt": "2026-05-20T21:00:00+02:00",
    "countedBy": "admin",
    "notes": "Blind count performed by manager"
  },
  {
    "@self": { "register": "pipelinq", "schema": "cashCount", "slug": "count-2026-0521-01" },
    "shift": "<<cashShift:shift-2026-0521-01>>",
    "amount": 425.75,
    "countedAt": "2026-05-21T22:00:00+02:00",
    "countedBy": "cashier1",
    "notes": "Blind count includes €50 foreign currency"
  },
  {
    "@self": { "register": "pipelinq", "schema": "cashCount", "slug": "count-2026-0521-02" },
    "shift": "<<cashShift:shift-2026-0521-02>>",
    "amount": 623.25,
    "countedAt": "2026-05-21T22:30:00+02:00",
    "countedBy": "cashier2"
  }
]
```

### cashDiff seeds (3 objects)

```json
[
  {
    "@self": { "register": "pipelinq", "schema": "cashDiff", "slug": "diff-2026-0520-01" },
    "shift": "<<cashShift:shift-2026-0520-01>>",
    "count": "<<cashCount:count-2026-0520-01>>",
    "expectedAmount": 487.25,
    "actualAmount": 487.50,
    "diffAmount": 0.25,
    "diffPercentage": 0.05,
    "tolerancePercentage": 2.0,
    "withinTolerance": true,
    "status": "approved",
    "approvedBy": "manager1",
    "approvedAt": "2026-05-20T21:15:00+02:00"
  },
  {
    "@self": { "register": "pipelinq", "schema": "cashDiff", "slug": "diff-2026-0521-01" },
    "shift": "<<cashShift:shift-2026-0521-01>>",
    "count": "<<cashCount:count-2026-0521-01>>",
    "expectedAmount": 425.50,
    "actualAmount": 425.75,
    "diffAmount": 0.25,
    "diffPercentage": 0.06,
    "tolerancePercentage": 2.0,
    "withinTolerance": true,
    "status": "pending"
  },
  {
    "@self": { "register": "pipelinq", "schema": "cashDiff", "slug": "diff-2026-0521-02" },
    "shift": "<<cashShift:shift-2026-0521-02>>",
    "count": "<<cashCount:count-2026-0521-02>>",
    "expectedAmount": 620.00,
    "actualAmount": 623.25,
    "diffAmount": 3.25,
    "diffPercentage": 0.52,
    "tolerancePercentage": 2.0,
    "withinTolerance": true,
    "status": "pending"
  }
]
```

---

## Backend

### CashShiftService

A new service in `lib/Service/CashShiftService.php` handles lifecycle transitions and diff calculation:

- **`openShift(drawer, operator, floatAmount)`** — creates a new `cashShift` with status `open`
- **`recordCount(shift, amount, countedBy, notes)`** — creates a `cashCount`, calculates diff, creates a `cashDiff` with status `pending`
- **`approveDiff(diff, approver)`** — sets diff status to `approved`, emits `pipelinq.CashDiff.confirmed` CloudEvent to Shillinq
- **`rejectDiff(diff, approver, reason)`** — sets diff status to `rejected`, creates a task for shift reopening

Diff calculation:
- Query `posTransaction` objects with `status: confirmed` or `settled` where `confirmedAt` is within shift window
- Sum the transaction totals = `salesTotal`
- Sum `cashDrop.amount` = `dropsTotal`
- `expectedAmount = floatAmount + salesTotal − dropsTotal`
- `actualAmount = cashCount.amount`
- `diffAmount = actualAmount − expectedAmount`
- `diffPercentage = (diffAmount / expectedAmount) × 100` (handle division by zero: if expected is 0, percentage is infinite or marked as N/A)
- `withinTolerance = |diffPercentage| ≤ 2` (hardcoded for now; configurable in V2)

### CloudEvent Emission

On diff approval, emit:
```
{
  "specversion": "1.0",
  "type": "pipelinq.CashDiff.confirmed",
  "source": "pipelinq/cashShift",
  "id": "<uuid>",
  "time": "<ISO8601>",
  "subject": "shift-reference",
  "datacontenttype": "application/json",
  "data": {
    "shift_id": "uuid",
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

---

## Frontend

### CashShift List View

- Filter by status (open / closed / reconciled)
- Filter by date range
- Filter by drawer
- Search by reference
- Columns: Reference, Drawer, Operator, Float, Status, Opened, Closed

### CashShift Detail View

Renders a multi-step panel for managing the shift lifecycle:

1. **Float Declaration** (open status) — displays `floatAmount`, `floatAt`, `operator`, `managedBy`
2. **Drops Panel** — lists all `cashDrop` objects for this shift; button to add a new drop (modal with amount, reason, timestamp)
3. **Count Entry** (when closing shift) — form to enter the physical count; button "Inklokken en afsluiten" (blind-count style, no prior hint)
4. **Diff Panel** — displays calculated `expectedAmount`, `actualAmount`, `diffAmount`, `diffPercentage`, `withinTolerance` flag, status, and approval buttons (manager-only)
5. **Notes** — editable text field

Button state:
- "Close shift & count" — visible when status `open`, creates count and calculates diff
- "Approve variance" — visible when status `closed` and user is manager, calls `approveDiff`
- "Reject variance" — visible when status `closed` and user is manager, calls `rejectDiff`

---

## Integration with Shillinq

When a `pipelinq.CashDiff.confirmed` CloudEvent is published, Shillinq listens and:

1. Reads the `diff_amount` and `diff_percentage`
2. Posts a GL adjustment entry (debit/credit Cash account) with the diff as the line amount
3. Links the entry to the original shift for traceability

This closes the accounting loop: sales are recorded as transactions (pos-transaction-core), cash is reconciled (pos-cash-management), and variances are posted to GL (Shillinq).

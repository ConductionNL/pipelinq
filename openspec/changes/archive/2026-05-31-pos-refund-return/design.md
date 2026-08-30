# Design: pos-refund-return

## Architecture Overview

Two new OpenRegister schemas (`posRefund`, `posRefundLine`) are added to `pipelinq_register.json`.
A thin backend controller + service handles lifecycle transitions (confirm / reject) and CloudEvent
emission for both refund reversal and stock movement. Configuration data for refund reasons is
seeded as a `refundReason` collection.

All CRUD and list operations use `ObjectService` directly from the existing `createObjectStore` pattern.
No custom database tables are introduced.

---

## Data Model (OpenRegister Schemas)

### refundReason (configuration)

A lookup/enumeration of standard refund reasons. Seeded at app install; managers can add custom reasons.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| code | string | Yes | Machine-readable code (e.g., "DAMAGED", "UNWANTED") |
| label | string | Yes | Display label (e.g., "Artikel beschadigd") |
| description | string | No | Help text shown in UI |
| isActive | boolean | No | Whether this reason is available for new refunds. Default: true |
| icon | string | No | Icon identifier for the reason |

---

### posRefund (`schema:Order` with type=refund)

Represents a refund or return transaction for a previously settled or confirmed transaction.
The `refundAmount` and `totalTax` are computed from refundLines on save by `PosRefundService`.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reference | string | No | Human-readable refund reference, e.g. "RET-2026-0001" |
| originalTransaction | string (uuid) | Yes | UUID reference to the original `posTransaction` being refunded |
| cashier | string | Yes | Nextcloud user UID of the logged-in cashier processing the return |
| status | string | Yes | Lifecycle: `pending` \| `completed` \| `rejected`. Default: `pending` |
| refundReason | string | Yes | UUID reference to a `refundReason` object describing the overall reason |
| paymentMethod | string | No | Original tender method (cash, card_visa, card_mastercard, digital_wallet) |
| paymentReference | string | No | Transaction ID from original payment for reversal reference |
| refundAmount | number | No | Total refund amount excl. tax (computed from lines). Default: 0 |
| totalTax | number | No | Aggregate tax being refunded (computed from lines). Default: 0 |
| notes | string | No | Cashier notes on the return |
| confirmedAt | string (date-time) | No | ISO 8601 timestamp of completion |
| rejectedAt | string (date-time) | No | ISO 8601 timestamp of rejection |
| rejectionReason | string | No | Text reason if refund was rejected |
| cloudEventId | string | No | CloudEvents `id` of the emitted refund event |

**Status transitions:**

```
pending ──► completed     (manager confirm)
pending ──► rejected      (manager reject)
completed ──► (no further transitions; completed refunds are immutable)
```

---

### posRefundLine (`schema:OrderItem` with type=refund-line)

One returnable item on a posRefund. Links back to the original line item.
`taxAmount` and `lineTotal` are computed on save based on the original line and returned quantity.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| refund | string (uuid) | Yes | UUID reference to the parent `posRefund` |
| originalLine | string (uuid) | Yes | UUID reference to the original `posTransactionLine` being refunded |
| returnedQuantity | number | Yes | Number of units being refunded (minimum: 0.001, supports fractions) |
| returnReason | string | Yes | UUID reference to a `refundReason` describing why this specific item is returned |
| restock | boolean | No | Whether the returned item should be restored to inventory. Default: true |
| taxAmount | number | No | Computed: (returned qty / original qty) × original line taxAmount |
| lineTotal | number | No | Computed: (returned qty / original qty) × original line total |
| notes | string | No | Line-specific return notes (e.g., "dent on back corner") |

---

## Seed Data

Seed objects are added to `lib/Settings/pipelinq_register.json` under `components.objects[]`
using the `@self` envelope. Slugs are unique identifiers for idempotent re-import.

### refundReason seeds (6 objects)

```json
[
  {
    "@self": { "register": "pipelinq", "schema": "refundReason", "slug": "reason-damaged" },
    "code": "DAMAGED",
    "label": "Artikel beschadigd",
    "description": "Goederen ontvangen in beschadigde staat",
    "isActive": true
  },
  {
    "@self": { "register": "pipelinq", "schema": "refundReason", "slug": "reason-unwanted" },
    "code": "UNWANTED",
    "label": "Klant wil terug",
    "description": "Klant is van gedachten veranderd",
    "isActive": true
  },
  {
    "@self": { "register": "pipelinq", "schema": "refundReason", "slug": "reason-wrong-item" },
    "code": "WRONG",
    "label": "Verkeerd artikel",
    "description": "Verkeerde artikel gegeven of besteld",
    "isActive": true
  },
  {
    "@self": { "register": "pipelinq", "schema": "refundReason", "slug": "reason-expired" },
    "code": "EXPIRED",
    "label": "Verlopen product",
    "description": "Goederen zijn verlopen",
    "isActive": true
  },
  {
    "@self": { "register": "pipelinq", "schema": "refundReason", "slug": "reason-error" },
    "code": "ERROR",
    "label": "Kassa-fout",
    "description": "Fout gemaakt bij invoering of betaling",
    "isActive": true
  },
  {
    "@self": { "register": "pipelinq", "schema": "refundReason", "slug": "reason-other" },
    "code": "OTHER",
    "label": "Overig",
    "description": "Andere reden",
    "isActive": true
  }
]
```

### posRefund seeds (4 objects)

```json
[
  {
    "@self": { "register": "pipelinq", "schema": "posRefund", "slug": "ref-2026-0001" },
    "reference": "RET-2026-0001",
    "originalTransaction": "txn-2026-0001",
    "cashier": "admin",
    "status": "completed",
    "refundReason": "reason-damaged",
    "paymentMethod": "cash",
    "paymentReference": "TXN-2026-0001",
    "refundAmount": 2.95,
    "totalTax": 0.27,
    "confirmedAt": "2026-05-20T09:22:00+02:00",
    "notes": "Klant zei dat kopje beschadigd was"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posRefund", "slug": "ref-2026-0002" },
    "reference": "RET-2026-0002",
    "originalTransaction": "txn-2026-0003",
    "cashier": "emma.bakker",
    "status": "pending",
    "refundReason": "reason-wrong-item",
    "paymentMethod": "card_visa",
    "paymentReference": "VISA-5678",
    "refundAmount": 39.99,
    "totalTax": 6.95,
    "notes": "Klant wil USB-C hub niet — vervanging besteld"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posRefund", "slug": "ref-2026-0003" },
    "reference": "RET-2026-0003",
    "originalTransaction": "txn-2026-0005",
    "cashier": "admin",
    "status": "completed",
    "refundReason": "reason-damaged",
    "paymentMethod": "card_mastercard",
    "paymentReference": "MC-9012",
    "refundAmount": 703.30,
    "totalTax": 147.69,
    "confirmedAt": "2026-05-20T10:00:00+02:00",
    "notes": "Laptop volledig retour na verzendschade. Klant is tevreden met volledige terugbetaling."
  },
  {
    "@self": { "register": "pipelinq", "schema": "posRefund", "slug": "ref-2026-0004" },
    "reference": "RET-2026-0004",
    "originalTransaction": "txn-2026-0003",
    "cashier": "jan.smit",
    "status": "rejected",
    "refundReason": "reason-unwanted",
    "paymentMethod": "cash",
    "refundAmount": 0,
    "totalTax": 0,
    "rejectedAt": "2026-05-20T10:45:00+02:00",
    "rejectionReason": "Retourperiode verstreken (product gekocht 45 dagen geleden)"
  }
]
```

### posRefundLine seeds (5 objects)

```json
[
  {
    "@self": { "register": "pipelinq", "schema": "posRefundLine", "slug": "refline-0001-koffie" },
    "refund": "ref-2026-0001",
    "originalLine": "txnline-0001-koffie",
    "returnedQuantity": 1,
    "returnReason": "reason-damaged",
    "restock": false,
    "taxAmount": 0.27,
    "lineTotal": 3.22,
    "notes": "Kopje had scheurtje in handvat"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posRefundLine", "slug": "refline-0002-hub" },
    "refund": "ref-2026-0002",
    "originalLine": "txnline-0003-hub",
    "returnedQuantity": 1,
    "returnReason": "reason-wrong-item",
    "restock": true,
    "taxAmount": 6.95,
    "lineTotal": 49.80,
    "notes": "Klant had 5-poorts hub nodig, niet 7-poorts"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posRefundLine", "slug": "refline-0003-laptop" },
    "refund": "ref-2026-0003",
    "originalLine": "txnline-0005-laptop",
    "returnedQuantity": 1,
    "returnReason": "reason-damaged",
    "restock": false,
    "taxAmount": 147.69,
    "lineTotal": 851.00,
    "notes": "Verzendschade — groot gat in scherm"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posRefundLine", "slug": "refline-0004-sleeve" },
    "refund": "ref-2026-0004",
    "originalLine": "txnline-0003-sleeve",
    "returnedQuantity": 1,
    "returnReason": "reason-unwanted",
    "restock": false,
    "taxAmount": 0,
    "lineTotal": 0,
    "notes": "Nooit gebruikt; kleur past niet"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posRefundLine", "slug": "refline-0005-broodje-partial" },
    "refund": "ref-2026-0002",
    "originalLine": "txnline-0001-broodje",
    "returnedQuantity": 1,
    "returnReason": "reason-error",
    "restock": true,
    "taxAmount": 0.38,
    "lineTotal": 4.63,
    "notes": "Een broodje te veel gegeven op kassamachine"
  }
]
```

---

## Backend

### PosRefundService (`lib/Service/PosRefundService.php`)

Business logic for refund lifecycle and total calculation.

| Method | Description |
|--------|-------------|
| `recalculateLine(array $originalLine, number $returnedQty): array` | Given the original line and returned quantity, compute taxAmount and lineTotal proportionally |
| `recalculateTotals(string $refundId): array` | Fetch all refundLines, aggregate into refundAmount and totalTax, save updated posRefund |
| `confirmRefund(string $id, string $userId): array` | Validate status is pending, cart is non-empty; call recalculateTotals; set status=completed + confirmedAt; call emitRefundEvent + emitStockMovementEvents |
| `rejectRefund(string $id, string $reason, string $userId): array` | Validate status is pending; set status=rejected + rejectedAt + rejectionReason |
| `emitRefundEvent(array $refund): void` | Emit `pipelinq.TransactionRefund.completed` via WebhookService in CloudEvents 1.0 format |
| `emitStockMovementEvents(string $refundId): void` | For each refundLine with restock=true, emit `shillinq.StockMovement` event with negative quantity |

**CloudEvent envelope** for `pipelinq.TransactionRefund.completed`:
```json
{
  "specversion": "1.0",
  "type": "pipelinq.TransactionRefund.completed",
  "source": "/apps/pipelinq/pos",
  "id": "{{uuid}}",
  "time": "{{confirmedAt}}",
  "datacontenttype": "application/json",
  "data": {
    "refundId": "{{uuid}}",
    "reference": "RET-2026-0001",
    "originalTransactionId": "{{uuid}}",
    "originalReference": "TXN-2026-0001",
    "cashier": "admin",
    "refundAmount": 2.95,
    "totalTax": 0.27,
    "paymentMethod": "cash",
    "paymentReference": "TXN-2026-0001",
    "confirmedAt": "2026-05-20T09:22:00+02:00"
  }
}
```

**CloudEvent envelope** for `shillinq.StockMovement` (per restocked line):
```json
{
  "specversion": "1.0",
  "type": "shillinq.StockMovement",
  "source": "/apps/pipelinq/pos",
  "id": "{{uuid}}",
  "time": "{{confirmedAt}}",
  "datacontenttype": "application/json",
  "data": {
    "transactionType": "refund_return",
    "refundId": "{{refundId}}",
    "productId": "{{productId}}",
    "quantity": {{returnedQuantity}},
    "unit": "piece",
    "notes": "Return from transaction TXN-2026-0001"
  }
}
```

### PosRefundController (`lib/Controller/PosRefundController.php`)

Thin controller delegating all logic to `PosRefundService`.

| Method | URL | Action |
|--------|-----|--------|
| POST | `/api/pos-refunds/{id}/confirm` | Confirm pending refund and emit events |
| POST | `/api/pos-refunds/{id}/reject` | Reject pending refund with reason |

Standard CRUD (`GET /api/pos-refunds`, `POST`, `PUT`, `DELETE`) is handled by OpenRegister's generic object API.

---

## Frontend

### Routes (added to `src/router/index.js`)

- `/pos/refunds` — PosRefundList
- `/pos/refunds/:id` — PosRefundDetail
- `/pos/refunds/:id/edit` — PosRefundForm (edit mode)
- `/pos/refunds/new` — PosRefundForm (create mode)
- `/pos/refunds/new/:transactionId` — PosRefundForm (create from transaction)

### Store (added to `src/store/store.js`)

```js
objectStore.registerObjectType('posRefund', 'posRefund', 'pipelinq')
objectStore.registerObjectType('posRefundLine', 'posRefundLine', 'pipelinq')
objectStore.registerObjectType('refundReason', 'refundReason', 'pipelinq')
```

### Views

**PosRefundList.vue** (`src/views/pos/PosRefundList.vue`)
- `CnIndexPage` + `useListView('posRefund', { sidebarState, objectStore })`
- Columns: Reference, Original Transaction, Refund Reason, Amount, Status (badge), Created
- Filters: status (multi-select), refund reason, date range
- Row click → detail; "Nieuwe retour" button → `/pos/refunds/new`

**PosRefundDetail.vue** (`src/views/pos/PosRefundDetail.vue`)
- `CnDetailPage` + `CnDetailCard` sections:
  - Refund header: reference, original transaction link, cashier, status badge, reason
  - Original transaction summary: reference, total (for context)
  - Refund line items table: original description, original qty, refunded qty, reason, restock flag, refund total
  - Refund totals: refundAmount, totalTax, refundAmount + tax
  - Notes: cashier notes and (if rejected) rejection reason
- Lifecycle action buttons:
  - pending: **Bevestigen**, **Afwijzen**
  - completed/rejected: none (immutable)
- `CnObjectSidebar` (files, notes, audit trail tabs)

**PosRefundForm.vue** (`src/views/pos/PosRefundForm.vue`)
- Create / edit form with:
  - Header: original transaction selector (dropdown or linked from transaction detail)
  - Overall refund reason picker
  - Line items editor: table showing all original lines, with checkboxes and qty inputs
  - Real-time refund totals panel
- Validates: at least one line selected; returned qty <= original qty

### Components

**PosRefundLineRow.vue** (`src/components/pos/PosRefundLineRow.vue`)
- Inline editable row for a refund line item
- Shows original description, original qty, returned qty input, refund reason picker, restock toggle
- Emits `update:line` with recomputed totals on field change
- Remove button to exclude line from refund

**PosRefundTotalsPanel.vue** (`src/components/pos/PosRefundTotalsPanel.vue`)
- Displays refundAmount, totalTax, and total in real time
- Highlights refund total in primary colour

### Navigation

Add "Retouren" entry to `src/navigation/MainMenu.vue` under the POS section with return/undo icon.
Also add a "Retour registreren" button on the PosTransactionDetail.vue card (or lifecycle section)
for quick access to create a return from a transaction.

---

## Reuse Analysis

| Platform Capability | Usage in this change |
|---------------------|----------------------|
| `createObjectStore` | posRefund, posRefundLine, refundReason Pinia stores |
| `ObjectService.saveObject()` | All lifecycle writes via PosRefundService |
| `CnIndexPage` + `useListView` | PosRefundList — zero custom list logic |
| `CnDetailPage` + `CnDetailCard` | PosRefundDetail sections |
| `CnObjectSidebar` | Files / notes / audit trail on refund detail |
| `CnStatusBadge` | Refund lifecycle status display |
| `WebhookService` | CloudEvent emission on confirm |
| `relationsPlugin` | posRefundLine ↔ posRefund relation |

No custom search, file handling, pagination, or audit logging needed.

---

## Deduplication Check

- **leadProduct** (CRM lead line items) vs. **posRefundLine** — leadProduct tracks deal line items with
  deal-specific pricing; posRefundLine tracks returned items from a transaction. No overlap.
- **posTransactionLine** (transaction line items) — posRefundLine references the original line and
  computes refund totals proportionally. These are distinct concepts. posTransactionLine is the original
  cart item; posRefundLine is the return/refund metadata. No duplication.
- No existing refund logic found in `openregister/lib/Service/` or `pipelinq/lib/`.
- `refundReason` is a simple configuration lookup — no existing parallel found.

---

## Files Changed

### New Files

| File | Description |
|------|-------------|
| `lib/Controller/PosRefundController.php` | Lifecycle action endpoints |
| `lib/Service/PosRefundService.php` | Recalculate, confirm, reject, emit events |
| `src/views/pos/PosRefundList.vue` | Refund list |
| `src/views/pos/PosRefundDetail.vue` | Refund detail + lifecycle actions |
| `src/views/pos/PosRefundForm.vue` | Create/edit with line item editor |
| `src/components/pos/PosRefundLineRow.vue` | Inline editable refund line row |
| `src/components/pos/PosRefundTotalsPanel.vue` | Real-time refund totals |

### Modified Files

| File | Change |
|------|--------|
| `lib/Settings/pipelinq_register.json` | Add posRefund, posRefundLine, refundReason schemas and seed data |
| `appinfo/routes.php` | Add POS refund API routes |
| `src/router/index.js` | Add `/pos/refunds` routes |
| `src/store/store.js` | Register posRefund, posRefundLine, refundReason object types |
| `src/navigation/MainMenu.vue` | Add "Retouren" nav item under POS |
| `src/views/pos/PosTransactionDetail.vue` | Add "Retour registreren" button in action buttons |

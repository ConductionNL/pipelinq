# Design: pos-transaction-core

## Architecture Overview

Two new OpenRegister schemas (`posTransaction`, `posTransactionLine`) are added to
`pipelinq_register.json`. A thin backend controller + service handles lifecycle
transitions (confirm / settle / refund / park) and CloudEvent emission.
All CRUD and list operations use `ObjectService` directly from the existing
`createObjectStore` pattern. No custom database tables are introduced.

---

## Data Model (OpenRegister Schemas)

### posTransaction (`schema:Order`)

Represents a POS cart / receipt from open (draft) through payment (confirmed → settled)
or cancellation (refunded). The `taxBreakdown` array captures tax per BTW rate so
receipts can show 9% and 21% lines separately.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reference | string | No | Human-readable transaction reference, e.g. "TXN-2026-0001" |
| cashier | string | Yes | Nextcloud user UID of the logged-in cashier |
| client | string (uuid) | No | Optional reference to a `client` object for account sales |
| terminalId | string | No | POS terminal / register identifier, e.g. "kassa-01" |
| status | string | Yes | Lifecycle: `draft` \| `parked` \| `confirmed` \| `settled` \| `refunded`. Default: `draft` |
| subtotal | number | No | Sum of line totals excl. tax, after line-level discounts. Default: 0 |
| discountTotal | number | No | Aggregate discount amount across all lines. Default: 0 |
| taxBreakdown | array | No | Per-rate tax summary: `[{ "rate": 21, "base": 100.00, "tax": 21.00 }]` |
| totalTax | number | No | Aggregate tax amount (sum of taxBreakdown[*].tax). Default: 0 |
| total | number | No | Grand total incl. tax (subtotal + totalTax). Default: 0 |
| notes | string | No | Optional cashier notes on the transaction |
| parkedAt | string (date-time) | No | ISO 8601 timestamp when transaction was parked |
| confirmedAt | string (date-time) | No | ISO 8601 timestamp of confirmation |
| settledAt | string (date-time) | No | ISO 8601 timestamp of settlement |
| refundedAt | string (date-time) | No | ISO 8601 timestamp of refund / void |
| refundReason | string | No | Reason code / description for refund or void |
| cloudEventId | string | No | CloudEvents `id` of the emitted confirmation event |

**Status transitions:**

```
draft ──► parked ──► draft   (resume)
draft ──► confirmed ──► settled
confirmed ──► refunded       (manager only)
settled ──► refunded         (manager only)
```

---

### posTransactionLine (`schema:OrderItem`)

One sellable item on a posTransaction. Quantity supports fractions for weight/volume
items. `taxAmount` and `lineTotal` are computed on save by `PosTransactionService`.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| transaction | string (uuid) | Yes | UUID reference to the parent `posTransaction` |
| product | string (uuid) | No | Optional reference to a `product` object from the catalog |
| description | string | Yes | Line description — pre-filled from product.name, or free-text |
| quantity | number | Yes | Number of units (minimum: 0.001, supports fractions) |
| unitPrice | number | Yes | Price per unit in EUR (pre-filled from product.unitPrice) |
| discount | number | No | Discount percentage 0–100. Default: 0 |
| taxRate | number | No | BTW rate percentage. Default: 21 (Dutch standard rate). Common values: 0, 9, 21 |
| taxAmount | number | No | Computed: `(quantity × unitPrice × (1 − discount/100)) × taxRate/100` |
| lineTotal | number | No | Computed: `(quantity × unitPrice × (1 − discount/100)) + taxAmount` |
| sortOrder | integer | No | Display order of the line within the transaction. Default: 0 |
| notes | string | No | Optional line-level notes, e.g. "inclusief montage" |

---

## Seed Data

Seed objects are added to `lib/Settings/pipelinq_register.json` under
`components.objects[]` using the `@self` envelope. Slugs are unique identifiers
for idempotent re-import.

### posTransaction seeds (5 objects)

```json
[
  {
    "@self": { "register": "pipelinq", "schema": "posTransaction", "slug": "txn-2026-0001" },
    "reference": "TXN-2026-0001",
    "cashier": "admin",
    "terminalId": "kassa-01",
    "status": "settled",
    "subtotal": 19.75,
    "discountTotal": 0,
    "taxBreakdown": [{ "rate": 9, "base": 19.75, "tax": 1.78 }],
    "totalTax": 1.78,
    "total": 21.53,
    "confirmedAt": "2026-05-20T09:14:00+02:00",
    "settledAt": "2026-05-20T09:14:30+02:00",
    "notes": "Contante betaling"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTransaction", "slug": "txn-2026-0002" },
    "reference": "TXN-2026-0002",
    "cashier": "jan.smit",
    "terminalId": "kassa-02",
    "status": "draft",
    "subtotal": 0,
    "discountTotal": 0,
    "taxBreakdown": [],
    "totalTax": 0,
    "total": 0
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTransaction", "slug": "txn-2026-0003" },
    "reference": "TXN-2026-0003",
    "cashier": "emma.bakker",
    "terminalId": "kassa-01",
    "status": "confirmed",
    "subtotal": 89.97,
    "discountTotal": 9.00,
    "taxBreakdown": [
      { "rate": 21, "base": 80.97, "tax": 17.00 },
      { "rate": 9, "base": 0, "tax": 0 }
    ],
    "totalTax": 17.00,
    "total": 97.97,
    "confirmedAt": "2026-05-20T10:33:00+02:00",
    "notes": "Zakelijke aankoop — factuur gevraagd"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTransaction", "slug": "txn-2026-0004" },
    "reference": "TXN-2026-0004",
    "cashier": "jan.smit",
    "terminalId": "kassa-03",
    "status": "parked",
    "subtotal": 24.95,
    "discountTotal": 0,
    "taxBreakdown": [{ "rate": 9, "base": 24.95, "tax": 2.25 }],
    "totalTax": 2.25,
    "total": 27.20,
    "parkedAt": "2026-05-20T11:02:00+02:00",
    "notes": "Klant even weg — terugkomen"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTransaction", "slug": "txn-2026-0005" },
    "reference": "TXN-2026-0005",
    "cashier": "admin",
    "terminalId": "kassa-01",
    "status": "refunded",
    "subtotal": 703.30,
    "discountTotal": 0,
    "taxBreakdown": [{ "rate": 21, "base": 703.30, "tax": 147.69 }],
    "totalTax": 147.69,
    "total": 850.99,
    "confirmedAt": "2026-05-19T14:22:00+02:00",
    "settledAt": "2026-05-19T14:23:00+02:00",
    "refundedAt": "2026-05-20T09:45:00+02:00",
    "refundReason": "Artikel beschadigd bij levering — retour verwerkt"
  }
]
```

### posTransactionLine seeds (5 objects)

```json
[
  {
    "@self": { "register": "pipelinq", "schema": "posTransactionLine", "slug": "txnline-0001-koffie" },
    "description": "Americano koffie",
    "quantity": 2,
    "unitPrice": 2.95,
    "discount": 0,
    "taxRate": 9,
    "taxAmount": 0.53,
    "lineTotal": 6.43,
    "sortOrder": 1
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTransactionLine", "slug": "txnline-0001-broodje" },
    "description": "Broodje kaas",
    "quantity": 3,
    "unitPrice": 4.25,
    "discount": 0,
    "taxRate": 9,
    "taxAmount": 1.15,
    "lineTotal": 13.90,
    "sortOrder": 2
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTransactionLine", "slug": "txnline-0003-sleeve" },
    "description": "Laptoptas 15,6 inch zwart",
    "quantity": 1,
    "unitPrice": 39.99,
    "discount": 0,
    "taxRate": 21,
    "taxAmount": 6.95,
    "lineTotal": 46.94,
    "sortOrder": 1
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTransactionLine", "slug": "txnline-0003-hub" },
    "description": "USB-C hub 7-poorts",
    "quantity": 1,
    "unitPrice": 54.98,
    "discount": 10,
    "taxRate": 21,
    "taxAmount": 10.07,
    "lineTotal": 59.49,
    "sortOrder": 2
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTransactionLine", "slug": "txnline-0005-laptop" },
    "description": "Laptop 14 inch (refurbished)",
    "quantity": 1,
    "unitPrice": 703.30,
    "discount": 0,
    "taxRate": 21,
    "taxAmount": 147.69,
    "lineTotal": 850.99,
    "sortOrder": 1,
    "notes": "Serienummer: NB-4821-X"
  }
]
```

---

## Backend

### PosTransactionService (`lib/Service/PosTransactionService.php`)

Business logic for lifecycle transitions and total calculation.

| Method | Description |
|--------|-------------|
| `recalculateTotals(string $transactionId): array` | Fetch all lines for transaction, compute subtotal / discountTotal / taxBreakdown / totalTax / total, save updated posTransaction |
| `recalculateLine(array $lineData): array` | Compute taxAmount and lineTotal for a single line; called before saving a posTransactionLine |
| `confirmTransaction(string $id, string $userId): array` | Validate status is draft/parked, cart is non-empty; call recalculateTotals; set status=confirmed + confirmedAt; call emitConfirmedEvent |
| `settleTransaction(string $id, string $userId): array` | Validate status is confirmed; set status=settled + settledAt |
| `refundTransaction(string $id, string $reason, string $userId): array` | Validate status is confirmed/settled; check manager permission; set status=refunded + refundedAt + refundReason |
| `parkTransaction(string $id, string $userId): array` | Validate status is draft; set status=parked + parkedAt |
| `resumeTransaction(string $id, string $userId): array` | Validate status is parked; set status=draft, clear parkedAt |
| `emitConfirmedEvent(array $transaction): void` | Emit `pipelinq.PosTransaction.confirmed` via WebhookService in CloudEvents 1.0 format |

**CloudEvent envelope** for `pipelinq.PosTransaction.confirmed`:
```json
{
  "specversion": "1.0",
  "type": "pipelinq.PosTransaction.confirmed",
  "source": "/apps/pipelinq/pos",
  "id": "{{uuid}}",
  "time": "{{confirmedAt}}",
  "datacontenttype": "application/json",
  "data": {
    "transactionId": "{{uuid}}",
    "reference": "TXN-2026-0001",
    "cashier": "admin",
    "total": 21.53,
    "totalTax": 1.78,
    "taxBreakdown": [{ "rate": 9, "base": 19.75, "tax": 1.78 }],
    "confirmedAt": "2026-05-20T09:14:00+02:00"
  }
}
```

### PosTransactionController (`lib/Controller/PosTransactionController.php`)

Thin controller delegating all logic to `PosTransactionService`.

| Method | URL | Action |
|--------|-----|--------|
| POST | `/api/pos-transactions/{id}/confirm` | Confirm draft/parked transaction |
| POST | `/api/pos-transactions/{id}/settle` | Settle confirmed transaction |
| POST | `/api/pos-transactions/{id}/refund` | Refund with `reason` body param (manager only) |
| POST | `/api/pos-transactions/{id}/park` | Park a draft transaction |
| POST | `/api/pos-transactions/{id}/resume` | Resume a parked transaction |

Standard CRUD (`GET /api/pos-transactions`, `POST`, `PUT`, `DELETE`) is handled by
OpenRegister's generic object API — no custom CRUD endpoints needed.

---

## Frontend

### Routes (added to `src/router/index.js`)

- `/pos` — PosTransactionList
- `/pos/:id` — PosTransactionDetail
- `/pos/:id/edit` — PosTransactionForm (edit mode)
- `/pos/new` — PosTransactionForm (create mode)

### Store (added to `src/store/store.js`)

```js
objectStore.registerObjectType('posTransaction', 'posTransaction', 'pipelinq')
objectStore.registerObjectType('posTransactionLine', 'posTransactionLine', 'pipelinq')
```

Both stores use `createObjectStore` with the `relations` plugin.

### Views

**PosTransactionList.vue** (`src/views/pos/PosTransactionList.vue`)
- `CnIndexPage` + `useListView('posTransaction', { sidebarState, objectStore })`
- Columns: Reference, Cashier, Terminal, Status (badge), Total, Created
- Filters: status (multi-select), terminalId, date range
- Row click → detail; "Nieuwe transactie" button → `/pos/new`

**PosTransactionDetail.vue** (`src/views/pos/PosTransactionDetail.vue`)
- `CnDetailPage` + `CnDetailCard` sections:
  - Transaction info: reference, cashier, terminal, status (`CnStatusBadge`), notes
  - Line items table: description, qty, unit price, discount %, tax rate, line total
  - Tax breakdown table: one row per BTW rate (base, rate, tax)
  - Totals panel: subtotal, discount, tax, **grand total** (highlighted)
- Lifecycle action buttons (context-sensitive):
  - draft/parked: **Bevestigen**, **Parkeren** / **Hervatten**
  - confirmed: **Afrekenen**, **Terugboeken** (manager only)
  - settled: **Terugboeken** (manager only)
- `CnObjectSidebar` (files, notes, audit trail tabs)

**PosTransactionForm.vue** (`src/views/pos/PosTransactionForm.vue`)
- Create / edit form with:
  - Header fields: terminalId, client (optional NcSelect from client store), notes
  - Line items editor: table with inline-add row; uses `PosLineItemRow`
  - Real-time totals panel: `PosTotalsPanel` recalculates on every line change
- Validates: at least one line required before confirm; quantity > 0; unitPrice >= 0

### Components

**PosLineItemRow.vue** (`src/components/pos/PosLineItemRow.vue`)
- Inline editable row for a single posTransactionLine
- Product picker: NcSelect searching product catalog by name/SKU; fills description + unitPrice + taxRate
- Free-text description fallback when no product selected
- Emits `update:line` with recomputed taxAmount and lineTotal on any field change

**PosTotalsPanel.vue** (`src/components/pos/PosTotalsPanel.vue`)
- Accepts `lines[]` as prop; computes and displays subtotal, discountTotal,
  taxBreakdown (grouped by rate), totalTax, and total in real time
- Highlights total amount in primary colour

### Navigation

Add "Kassabon" entry to `src/navigation/MainMenu.vue` with receipt/POS icon,
between existing entries.

---

## Reuse Analysis

| Platform Capability | Usage in this change |
|---------------------|----------------------|
| `createObjectStore` | posTransaction + posTransactionLine Pinia stores |
| `ObjectService.saveObject()` | All lifecycle writes via PosTransactionService |
| `CnIndexPage` + `useListView` | PosTransactionList — zero custom list logic |
| `CnDetailPage` + `CnDetailCard` | PosTransactionDetail sections |
| `CnFormDialog` | Line item add/edit dialogs |
| `CnObjectSidebar` | Files / notes / audit trail on transaction detail |
| `CnStatusBadge` | Transaction lifecycle status display |
| `CnTimelineStages` | Lifecycle stage progression indicator |
| `WebhookService` | CloudEvent emission on confirm |
| `AuthorizationService` | Manager permission check for refund/void |
| `relationsPlugin` | posTransactionLine ↔ posTransaction relation |

No custom search endpoints, file handling, pagination, or audit logging are needed —
all provided by the platform.

---

## Deduplication Check

- **leadProduct** (`openspec/specs/lead-management/`) handles line items for CRM leads.
  posTransactionLine is POS-specific: it adds tax-rate-first design, sortOrder, and computed
  taxAmount / lineTotal that differ from CRM deal line items. No sharing is appropriate.
- No existing POS or transaction logic found in `openregister/lib/Service/` or
  `pipelinq/lib/`.
- `product` schema is reused as-is from `pipelinq_register.json` — no duplication.

---

## Files Changed

### New Files

| File | Description |
|------|-------------|
| `lib/Controller/PosTransactionController.php` | Lifecycle action endpoints |
| `lib/Service/PosTransactionService.php` | Recalculate, confirm, settle, refund, park |
| `src/views/pos/PosTransactionList.vue` | Transaction list |
| `src/views/pos/PosTransactionDetail.vue` | Transaction detail + lifecycle actions |
| `src/views/pos/PosTransactionForm.vue` | Create/edit with line item editor |
| `src/components/pos/PosLineItemRow.vue` | Inline editable line item row |
| `src/components/pos/PosTotalsPanel.vue` | Real-time totals display |

### Modified Files

| File | Change |
|------|--------|
| `lib/Settings/pipelinq_register.json` | Add posTransaction + posTransactionLine schemas and seed data |
| `appinfo/routes.php` | Add POS lifecycle API routes |
| `src/router/index.js` | Add `/pos` routes |
| `src/store/store.js` | Register posTransaction + posTransactionLine object types |
| `src/navigation/MainMenu.vue` | Add "Kassabon" nav item |

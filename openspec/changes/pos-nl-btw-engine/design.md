# Design: pos-nl-btw-engine

## Architecture Overview

The pos-nl-btw-engine extends the existing `posTransaction` and `posTransactionLine` schemas
with per-rate tax tracking and invoice breakdown. No new schemas are created — existing CRUD
operations on `pipelinq_register.json` remain unchanged. The `PosTransactionService::recalculateTotals()`
method is enhanced to compute `taxBreakdown` and `invoiceBreakdown` by grouping lines by rate.

Frontend changes are limited to enhanced display of the new breakdown arrays in the detail and
receipt views.

---

## Data Model Extensions

### posTransaction (extended)

New / modified properties to support per-rate tax breakdown:

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| priceMode | string | No | Display mode for prices: `incl` (inclusive) or `excl` (exclusive). Default: `excl`. Controls whether line items and total show "€X incl. BTW" or "€X ex. BTW" on receipts |
| invoiceBreakdown | array | No | GL posting breakdown for shillinq: `[{ "rate": 21, "base": 100.00, "tax": 21.00, "description": "21% VAT" }]`. Computed on every total recalculation. |

All other properties from pos-transaction-core remain unchanged.

### posTransactionLine (extended)

Per-item tax rate — already present in pos-transaction-core design:

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| taxRate | number | No | BTW rate percentage. Default: 21. Common values: 0, 9, 21. Allows per-item rate selection. |
| taxAmount | number | No | Computed: `(quantity × unitPrice × (1 − discount/100)) × taxRate/100` |
| lineTotal | number | No | Computed: `(quantity × unitPrice × (1 − discount/100)) + taxAmount` |

No new properties needed — `taxRate` was already in pos-transaction-core; this change makes it full-featured
with UI for selection and breakdown display.

---

## Seed Data

Seed objects are updated in `lib/Settings/pipelinq_register.json` to include transactions with
mixed 9% and 21% items.

### posTransaction seeds (updated, 5 objects)

```json
[
  {
    "@self": { "register": "pipelinq", "schema": "posTransaction", "slug": "txn-btw-0001" },
    "reference": "TXN-2026-0101",
    "cashier": "admin",
    "terminalId": "kassa-01",
    "status": "settled",
    "priceMode": "excl",
    "subtotal": 45.50,
    "discountTotal": 0,
    "taxBreakdown": [
      { "rate": 9, "base": 24.99, "tax": 2.25 },
      { "rate": 21, "base": 20.51, "tax": 4.31 }
    ],
    "invoiceBreakdown": [
      { "rate": 9, "base": 24.99, "tax": 2.25, "description": "Reduced VAT (9%)" },
      { "rate": 21, "base": 20.51, "tax": 4.31, "description": "Standard VAT (21%)" }
    ],
    "totalTax": 6.56,
    "total": 52.06,
    "confirmedAt": "2026-05-21T10:15:00+02:00",
    "settledAt": "2026-05-21T10:15:45+02:00",
    "notes": "Café bestelling — contant betaald"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTransaction", "slug": "txn-btw-0002" },
    "reference": "TXN-2026-0102",
    "cashier": "emma.bakker",
    "terminalId": "kassa-02",
    "status": "confirmed",
    "priceMode": "excl",
    "subtotal": 84.99,
    "discountTotal": 0,
    "taxBreakdown": [
      { "rate": 9, "base": 49.97, "tax": 4.50 },
      { "rate": 21, "base": 35.02, "tax": 7.35 }
    ],
    "invoiceBreakdown": [
      { "rate": 9, "base": 49.97, "tax": 4.50, "description": "Reduced VAT (9%)" },
      { "rate": 21, "base": 35.02, "tax": 7.35, "description": "Standard VAT (21%)" }
    ],
    "totalTax": 11.85,
    "total": 96.84,
    "confirmedAt": "2026-05-21T11:22:00+02:00",
    "notes": "Retail order — mixed items"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTransaction", "slug": "txn-btw-0003" },
    "reference": "TXN-2026-0103",
    "cashier": "jan.smit",
    "terminalId": "kassa-01",
    "status": "settled",
    "priceMode": "incl",
    "subtotal": 65.31,
    "discountTotal": 5.00,
    "taxBreakdown": [
      { "rate": 9, "base": 35.89, "tax": 3.23 },
      { "rate": 21, "base": 29.42, "tax": 6.18 }
    ],
    "invoiceBreakdown": [
      { "rate": 9, "base": 35.89, "tax": 3.23, "description": "Reduced VAT (9%)" },
      { "rate": 21, "base": 29.42, "tax": 6.18, "description": "Standard VAT (21%)" }
    ],
    "totalTax": 9.41,
    "total": 74.72,
    "confirmedAt": "2026-05-21T12:00:00+02:00",
    "settledAt": "2026-05-21T12:00:30+02:00",
    "notes": "Deli catering — inclusief bezorgkosten"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTransaction", "slug": "txn-btw-0004" },
    "reference": "TXN-2026-0104",
    "cashier": "emma.bakker",
    "terminalId": "kassa-03",
    "status": "draft",
    "priceMode": "excl",
    "subtotal": 0,
    "discountTotal": 0,
    "taxBreakdown": [],
    "invoiceBreakdown": [],
    "totalTax": 0,
    "total": 0
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTransaction", "slug": "txn-btw-0005" },
    "reference": "TXN-2026-0105",
    "cashier": "admin",
    "terminalId": "kassa-02",
    "status": "refunded",
    "priceMode": "excl",
    "subtotal": 129.50,
    "discountTotal": 0,
    "taxBreakdown": [
      { "rate": 9, "base": 79.99, "tax": 7.20 },
      { "rate": 21, "base": 49.51, "tax": 10.40 }
    ],
    "invoiceBreakdown": [
      { "rate": 9, "base": 79.99, "tax": 7.20, "description": "Reduced VAT (9%)" },
      { "rate": 21, "base": 49.51, "tax": 10.40, "description": "Standard VAT (21%)" }
    ],
    "totalTax": 17.60,
    "total": 147.10,
    "confirmedAt": "2026-05-20T14:30:00+02:00",
    "settledAt": "2026-05-20T14:31:00+02:00",
    "refundedAt": "2026-05-21T09:45:00+02:00",
    "refundReason": "Klant ontevreden — retour verwerkt"
  }
]
```

### posTransactionLine seeds (updated, 8 objects with mixed rates)

```json
[
  {
    "@self": { "register": "pipelinq", "schema": "posTransactionLine", "slug": "txnline-btw-0001-koffie" },
    "description": "Espresso dubble",
    "quantity": 2,
    "unitPrice": 2.50,
    "discount": 0,
    "taxRate": 9,
    "taxAmount": 0.45,
    "lineTotal": 5.45,
    "sortOrder": 1
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTransactionLine", "slug": "txnline-btw-0001-brood" },
    "description": "Croissant vers",
    "quantity": 3,
    "unitPrice": 6.66,
    "discount": 0,
    "taxRate": 9,
    "taxAmount": 1.80,
    "lineTotal": 20.78,
    "sortOrder": 2
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTransactionLine", "slug": "txnline-btw-0003-laptop" },
    "description": "Laptop 15 inch (zakelijk)",
    "quantity": 1,
    "unitPrice": 899.99,
    "discount": 0,
    "taxRate": 21,
    "taxAmount": 189.00,
    "lineTotal": 1088.99,
    "sortOrder": 1
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTransactionLine", "slug": "txnline-btw-0003-muis" },
    "description": "Draadloze muis",
    "quantity": 1,
    "unitPrice": 29.99,
    "discount": 0,
    "taxRate": 21,
    "taxAmount": 6.30,
    "lineTotal": 36.29,
    "sortOrder": 2
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTransactionLine", "slug": "txnline-btw-0005-appels" },
    "description": "Biologische appels (per kg)",
    "quantity": 2.5,
    "unitPrice": 3.20,
    "discount": 0,
    "taxRate": 0,
    "taxAmount": 0,
    "lineTotal": 8.00,
    "sortOrder": 1,
    "notes": "Biologisch gekwalificeerd"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTransactionLine", "slug": "txnline-btw-0005-wijn" },
    "description": "Rode wijn Bordeaux (0,75L)",
    "quantity": 2,
    "unitPrice": 12.50,
    "discount": 10,
    "taxRate": 21,
    "taxAmount": 4.28,
    "lineTotal": 27.78,
    "sortOrder": 2,
    "notes": "10% korting — promotieprijssteller"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTransactionLine", "slug": "txnline-btw-cafe-0001-bier" },
    "description": "Heineken pilsner (0,5L)",
    "quantity": 4,
    "unitPrice": 4.50,
    "discount": 0,
    "taxRate": 9,
    "taxAmount": 1.62,
    "lineTotal": 19.62,
    "sortOrder": 1
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTransactionLine", "slug": "txnline-btw-cafe-0001-snacks" },
    "description": "Bittergarnituur",
    "quantity": 2,
    "unitPrice": 8.00,
    "discount": 0,
    "taxRate": 9,
    "taxAmount": 1.44,
    "lineTotal": 17.44,
    "sortOrder": 2
  }
]
```

---

## Backend

### PosTransactionService (extended)

The `recalculateTotals()` method is enhanced to compute `taxBreakdown` and `invoiceBreakdown`
by grouping lines by tax rate.

| Method | Change |
|--------|--------|
| `recalculateLine(array $lineData)` | Unchanged — already uses `taxRate` from line data |
| `recalculateTotals(string $transactionId)` | **Enhanced** — now groups lines by `taxRate` and computes per-rate `base` and `tax` totals; populates `taxBreakdown` and `invoiceBreakdown` arrays |
| `confirmTransaction()` | Unchanged — calls the enhanced `recalculateTotals()` |

**recalculateTotals() algorithm:**

1. Fetch all `posTransactionLine` objects for the transaction
2. Initialize accumulators: `subtotal = 0`, `discountTotal = 0`, `rateMap = {}`
3. For each line:
   - Compute `lineSubtotal = quantity × unitPrice × (1 − discount/100)`
   - Add to `subtotal`
   - Add `discount × unitPrice × quantity / 100` to `discountTotal`
   - If `rateMap[line.taxRate]` doesn't exist, initialize it with `{ base: 0, tax: 0 }`
   - Add `lineSubtotal` to `rateMap[line.taxRate].base`
   - Compute and add line tax to `rateMap[line.taxRate].tax`
4. Build `taxBreakdown` array from `rateMap`, sorted by rate ascending
5. Build `invoiceBreakdown` array with same data, adding `description: "X% VAT"` or Dutch equivalent
6. Compute `totalTax = sum(taxBreakdown[*].tax)`, `total = subtotal + totalTax`
7. Save updated `posTransaction` with all computed fields

---

## Frontend

### Components

**TaxBreakdownCard.vue** (`src/components/pos/TaxBreakdownCard.vue`)
- New component displaying the `taxBreakdown` and `invoiceBreakdown` arrays
- Renders two tables side-by-side or stacked:
  - **Tax Summary** — rate, base amount, tax amount, percentage
  - **Invoice Breakdown** — rate, base, tax, description (for GL posting reference)
- Example display:
  ```
  BELASTINGAANGIFTE
  9% VAT          Base: €50.00    Tax: €4.50
  21% VAT         Base: €100.00   Tax: €21.00
  
  FACTUURVERDELING (voor boeking)
  9%              €50.00 + €4.50  Reduced VAT (9%)
  21%             €100.00 + €21.00 Standard VAT (21%)
  ```

### Views (modified)

**PosTransactionDetail.vue** (`src/views/pos/PosTransactionDetail.vue`)
- Add new `CnDetailCard` section above the existing totals panel
- Display `<TaxBreakdownCard :transaction="transaction" />`
- If `invoiceBreakdown` is present, show it as a separate "Factuurverdeling" (invoice breakdown) section

**PosTransactionList.vue** (`src/views/pos/PosTransactionList.vue`)
- Add optional column: "Tax Rates" showing abbreviated breakdown (e.g., "9%+21%" or "21% only")
- Example: clicking a row still navigates to detail

### Receipt / Print View (future)

The `invoiceBreakdown` array supports future receipt printing and tax compliance exports without
further schema changes. The description field allows cashiers or the system to label each line
for customer-facing receipts.

---

## Shillinq Consumer Update (non-breaking)

The `pipelinq.PosTransaction.confirmed` CloudEvent now carries `invoiceBreakdown` (and `priceMode`)
in addition to the existing `taxBreakdown`. These are additive fields, so the change is non-breaking
for existing consumers.

**Shillinq's `CloudEventConsumer` MUST be updated to iterate `invoiceBreakdown` and post one GL
line per tax rate** (instead of a single 21% VAT line), using each row's `base` for the net amount,
`tax` for the VAT debit/credit, and `description` as the GL line label. For accurate net VAT
liability, a separate per-rate compliance report is also available at
`GET /api/pos-transactions/tax-report` (optionally filtered by `?status=settled`), which aggregates
every fiscally-final transaction's `invoiceBreakdown` and nets out refunds.

## Inclusive vs Exclusive Pricing (implemented)

`priceMode` is implemented end-to-end, not display-only. In `incl` mode the entered `unitPrice`
already contains BTW; the server extracts the net base per line (`net = gross / (1 + rate/100)`,
`tax = gross − net`) before grouping by rate. In `excl` mode BTW is added on top
(`tax = net × rate/100`). The persisted per-rate `base` is always tax-exclusive, so the GL split
shillinq receives is identical regardless of how prices were entered. Dutch rounding is applied to
cents per line, then summed.

---

## Reuse Analysis

| Platform Capability | Usage in this change |
|---------------------|----------------------|
| `createObjectStore` | posTransaction + posTransactionLine stores (unchanged from pos-transaction-core) |
| `CnDetailCard` | New section in PosTransactionDetail for tax breakdown display |
| `CnTable` | TaxBreakdownCard uses table layout for rate groupings |

No custom calculation endpoints, new schemas, or audit logging are needed — all calculations
occur in the existing `recalculateTotals()` method.

---

## Deduplication Check

- **pos-transaction-core** provides the base `posTransaction` and `posTransactionLine` schemas;
  this change extends them without duplication
- No other POS or tax-specific logic found in `openregister/` or `pipelinq/lib/Service/`
- The `taxRate` property on `posTransactionLine` was already defined in pos-transaction-core;
  this change makes it fully featured with UI and breakdown display

---

## Files Changed

### New Files

| File | Description |
|------|-------------|
| `src/components/pos/TaxBreakdownCard.vue` | Display tax breakdown and invoice breakdown tables |

### Modified Files

| File | Change |
|------|--------|
| `lib/Service/PosTransactionService.php` | Enhance `recalculateTotals()` to compute `taxBreakdown` and `invoiceBreakdown` arrays |
| `src/views/pos/PosTransactionDetail.vue` | Add TaxBreakdownCard section above totals panel |
| `src/views/pos/PosTransactionList.vue` | Add optional "Tax Rates" column (optional) |
| `lib/Settings/pipelinq_register.json` | Update posTransaction and posTransactionLine seed data with mixed 9%/21% examples |

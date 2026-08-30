# Spec: pos-nl-btw-engine

**Feature tier:** P0-must  
**Schema.org mapping:** `schema:Order` (posTransaction), `schema:OrderItem` (posTransactionLine)  
**Demand evidence:** 12/13 competitors  
**Dependencies:** pos-transaction-core must be deployed first

---

## Requirements

### REQ-BTW-001: Per-Item Tax Rate Selection

The system MUST allow each line item on a POS transaction to carry its own tax rate (0%, 9%, or 21%).

#### Scenario: Pre-fill tax rate from product catalog

- GIVEN a product in the catalog with `taxRate: 9`
- WHEN the cashier adds that product to a transaction via the product picker
- THEN the new `posTransactionLine` MUST be created with `taxRate: 9` pre-filled from `product.taxRate`
- AND the cashier MAY override the tax rate manually via a dropdown or input field
- AND common options (0%, 9%, 21%) MUST be available in the dropdown

#### Scenario: Manual tax rate override for unmapped items

- GIVEN a free-text line item (no product reference)
- WHEN the cashier types a description and sets `taxRate: 9` before confirming
- THEN the `posTransactionLine` MUST be created with the chosen `taxRate`
- AND the default MUST be 21% if no override is specified

#### Scenario: Tax rate persists across edits

- GIVEN a line item with `taxRate: 9`
- WHEN the cashier edits the quantity or discount on that line
- THEN `taxRate` MUST NOT change
- AND `taxAmount` MUST be recomputed using the existing `taxRate`

---

### REQ-BTW-002: Tax Breakdown Grouped by Rate

The system MUST compute and display a transaction-level `taxBreakdown` array that groups tax
liability by rate.

#### Scenario: Compute tax breakdown on transaction confirmation

- GIVEN a transaction with lines at 9% (base €50, tax €4.50) and 21% (base €100, tax €21.00)
- WHEN the cashier clicks "Bevestigen"
- THEN `PosTransactionService::recalculateTotals()` MUST compute:
  ```json
  {
    "taxBreakdown": [
      { "rate": 9, "base": 50.00, "tax": 4.50 },
      { "rate": 21, "base": 100.00, "tax": 21.00 }
    ],
    "totalTax": 25.50,
    "total": 175.50
  }
  ```
- AND `taxBreakdown` MUST be sorted by rate ascending (0, 9, 21)
- AND the transaction MUST be persisted with these computed fields

#### Scenario: Zero-rated items (0% VAT) appear in breakdown

- GIVEN a transaction with one line at 0% (base €25, tax €0)
- WHEN the totals are computed
- THEN `taxBreakdown` MUST include `{ "rate": 0, "base": 25.00, "tax": 0.00 }`

#### Scenario: Breakdown updates on every line change (draft mode)

- GIVEN a transaction in `draft` status with lines at 9% and 21%
- WHEN the cashier adds a new line at 9%
- THEN the totals panel MUST immediately show the updated `taxBreakdown` with the new 9% base and tax
- AND the update MUST occur without saving (real-time calculation in Vue component)

---

### REQ-BTW-003: Invoice Breakdown for GL Posting

The system MUST generate an `invoiceBreakdown` array on `posTransaction` that shillinq can consume
to post separate GL journal entries per tax rate.

#### Scenario: Invoice breakdown includes description for GL posting

- GIVEN a transaction with lines at 9% and 21%
- WHEN the transaction is confirmed
- THEN `invoiceBreakdown` MUST be computed as:
  ```json
  {
    "invoiceBreakdown": [
      { "rate": 9, "base": 50.00, "tax": 4.50, "description": "Reduced VAT (9%)" },
      { "rate": 21, "base": 100.00, "tax": 21.00, "description": "Standard VAT (21%)" }
    ]
  }
  ```
- AND the description MUST be human-readable Dutch text for GL reference

#### Scenario: Shillinq consumes invoiceBreakdown for multi-line posting

- GIVEN shillinq is subscribed to `pipelinq.PosTransaction.confirmed` events
- WHEN shillinq receives an event with `invoiceBreakdown: [{rate: 9, ...}, {rate: 21, ...}]`
- THEN shillinq MUST post two separate GL lines (one per tax rate)
- AND each line MUST use `base` for the net amount and `tax` for the VAT debit/credit

---

### REQ-BTW-004: Price Mode Display (Inclusive vs Exclusive)

The system MUST support labeling transactions as tax-inclusive or tax-exclusive for receipt display.

#### Scenario: Set price mode at transaction level

- GIVEN a transaction in edit mode
- WHEN the cashier sets `priceMode: "incl"` (optional setting)
- THEN receipts and detail views MUST show "€X incl. BTW" instead of "€X ex. BTW"
- AND all **internal calculations** MUST remain unchanged (always use tax-exclusive amounts)

#### Scenario: Default to tax-exclusive display

- GIVEN a transaction with no `priceMode` set
- WHEN the detail view is rendered
- THEN amounts MUST default to "€X ex. BTW" (tax-exclusive display)
- AND `priceMode` MUST be `"excl"` or null

---

### REQ-BTW-005: Tax Breakdown Display on Detail View

The system MUST render the tax breakdown prominently on the transaction detail view.

#### Scenario: Render TaxBreakdownCard with per-rate summary

- GIVEN a transaction detail is loaded with `taxBreakdown` and `invoiceBreakdown`
- WHEN the detail view is rendered
- THEN a new "Belastingaangifte" (tax summary) card MUST be displayed
- AND it MUST show a table with columns: Rate, Base Amount, Tax Amount, Percentage
- AND rows MUST correspond to `taxBreakdown` entries sorted by rate

#### Scenario: Render invoice breakdown for GL reference

- GIVEN the transaction detail view includes the tax breakdown card
- WHEN viewing a settled or confirmed transaction
- THEN an optional "Factuurverdeling" (invoice breakdown) section MUST be visible
- AND it MUST display per-rate description for GL posting reference
- AND columns MUST include: Rate, Base, Tax, Description (from `invoiceBreakdown`)

#### Scenario: Empty breakdown on draft with no lines

- GIVEN a transaction in `draft` status with zero line items
- WHEN the detail or form view is displayed
- THEN the tax breakdown card MUST show "Geen artikelen" or similar placeholder
- AND all totals MUST display as €0.00

---

### REQ-BTW-006: Recalculation Logic for Mixed-Rate Carts

The system MUST correctly compute tax when a transaction contains items at multiple rates.

#### Scenario: Mixed 9% and 21% items with discounts

- GIVEN a transaction with:
  - Line 1: qty=1, price=€49.99, discount=0%, rate=9% → base €49.99, tax €4.50
  - Line 2: qty=1, price=€50.00, discount=0%, rate=21% → base €50.00, tax €10.50
  - Line 3: qty=1, price=€10.00, discount=50%, rate=21% → base €5.00, tax €1.05
- WHEN totals are recalculated
- THEN:
  - `subtotal = 49.99 + 50.00 + 5.00 = 104.99`
  - `discountTotal = 5.00`
  - `taxBreakdown = [{rate: 9, base: 49.99, tax: 4.50}, {rate: 21, base: 55.00, tax: 11.55}]`
  - `totalTax = 16.05`
  - `total = 104.99 + 16.05 = 121.04`

#### Scenario: Refund preserves tax breakdown

- GIVEN a settled transaction with `taxBreakdown: [{rate: 9, ...}, {rate: 21, ...}]`
- WHEN the transaction is refunded via `refundTransaction()`
- THEN `taxBreakdown` and `invoiceBreakdown` MUST remain unchanged on the refunded record
- AND shillinq MUST receive the breakdown to post reversing GL entries per rate

---

### REQ-BTW-007: Backwards Compatibility (Fallback for Single-Rate)

The system MUST not break existing single-rate transactions (all lines at 21%).

#### Scenario: Legacy transactions show in breakdown

- GIVEN a transaction created before pos-nl-btw-engine with all lines at 21% (default)
- WHEN the transaction detail is viewed
- THEN the tax breakdown MUST show a single row: `{rate: 21, base: X, tax: Y}`
- AND the detail view MUST render without errors

#### Scenario: Add mixed-rate items to existing transaction

- GIVEN a transaction in `parked` status with only 21% items
- WHEN the cashier resumes it and adds a 9% line item
- THEN the totals MUST recalculate correctly with both rates in the breakdown
- AND no data loss or conversion errors MUST occur

---

## CloudEvent Schema

The `pipelinq.PosTransaction.confirmed` event (from pos-transaction-core) is unchanged in format,
but **shillinq consumers MUST be updated** to process the new `invoiceBreakdown` array:

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
    "total": 121.04,
    "totalTax": 16.05,
    "taxBreakdown": [
      { "rate": 9, "base": 49.99, "tax": 4.50 },
      { "rate": 21, "base": 55.00, "tax": 11.55 }
    ],
    "invoiceBreakdown": [
      { "rate": 9, "base": 49.99, "tax": 4.50, "description": "Reduced VAT (9%)" },
      { "rate": 21, "base": 55.00, "tax": 11.55, "description": "Standard VAT (21%)" }
    ],
    "confirmedAt": "2026-05-21T10:15:00+02:00"
  }
}
```

Shillinq's `CloudEventConsumer` handler MUST iterate `invoiceBreakdown` and post **one GL debit line per rate**
(instead of a single 21% VAT line), for accurate VAT reporting by rate.

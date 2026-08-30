# Proposal: pos-nl-btw-engine

## Problem

The POS Transaction Core provides transaction and line item management with a single
hardcoded tax rate (default 21% Dutch BTW). Real-world retail operations in the Netherlands
require per-item tax classification — food & beverage at 9% VAT, standard goods at 21%.

Current system cannot:
- Assign different tax rates to individual items (beverage 9%, electronics 21%)
- Break down tax owed by rate on the receipt (showing "9% BTW: €X" and "21% BTW: €Y" separately)
- Support tax-inclusive vs tax-exclusive pricing workflows (some vendors show "€10 incl. BTW", others "€10 ex. BTW")
- Provide an invoice breakdown for compliance with Dutch tax reporting

Without this, retailers cannot accurately track VAT liability by rate, and shillinq cannot post
correct GL entries split by tax class. All 12/13 surveyed competitors offer per-item tax rate selection.

## Solution

Extend `posTransactionLine` to include a configurable `taxRate` property (default 21), and enhance
transaction detail and receipt views to display tax breakdown **grouped by rate**. Add a new
`invoiceBreakdown` array on `posTransaction` that shillinq can consume to post separate GL lines
for each tax rate.

Key additions:
1. **Per-item tax rate** — each line item specifies its own `taxRate` (0, 9, or 21); default 21
2. **Tax breakdown by rate** — transaction-level `taxBreakdown` array groups lines by rate:
   `[{ "rate": 9, "base": €50, "tax": €4.50 }, { "rate": 21, "base": €100, "tax": €21 }]`
3. **Invoice breakdown** — new `invoiceBreakdown` array with detailed GL posting lines for shillinq:
   `[{ "rate": 9, "base": €50, "tax": €4.50, "description": "Reduced VAT items" }, ...]`
4. **Receipt view enhancement** — visual grouping of line items by tax rate on detail view
5. **Tax-inclusive pricing support** — optional `priceMode` property on transaction to track
   whether amounts shown are incl. or excl. BTW (for display only; internal calc always uses excl.)

## Scope

- Add `taxRate` property to `posTransactionLine` schema (already present in pos-transaction-core
  design; this change makes it fully featured)
- Extend `posTransaction.taxBreakdown` to group by rate with base amount and computed tax amount
- Add `invoiceBreakdown` array to `posTransaction` with per-rate GL breakdown for shillinq
- Add optional `priceMode` property to `posTransaction` for "incl. BTW" / "excl. BTW" labeling
- Update `PosTransactionService::recalculateTotals()` to compute `invoiceBreakdown` on every save
- Update `PosTransactionDetail.vue` to display `invoiceBreakdown` as a separate card
- Update receipt/list views to show per-rate tax summary (e.g., "9% BTW: €4.50 | 21% BTW: €21.00")
- Seed data: add test transactions with mixed 9% and 21% items

## Out of scope

- Tax-inclusive price calculation (e.g., "€10 incl. VAT" → compute net automatically) — V2
- Tax exemption / reverse-charge scenarios (0% rate admin UX) — V2
- Compliance reporting / tax summary exports — handled by Shillinq GL posting
- Gift card / store credit handling — V2
- EU VAT for cross-border sales — Enterprise

## Impact

- **Retailers** — can now assign correct tax rates to menu items and products at sale time
- **Compliance** — transactions accurately track VAT by rate for reporting
- **Shillinq** — receives `invoiceBreakdown` array to post separate GL lines per tax class
- **Receipts** — show clear tax breakdown: "9% VAT: €X | 21% VAT: €Y" helping customers verify charges

## Dependencies

- **pos-transaction-core** — `posTransaction` and `posTransactionLine` schemas must already exist
- **product schema** — `product.taxRate` (already in catalog) is pre-filled into line items
- **Shillinq** — must be updated to consume `invoiceBreakdown` array for GL posting

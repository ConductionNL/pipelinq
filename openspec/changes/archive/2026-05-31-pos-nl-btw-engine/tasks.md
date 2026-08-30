# Tasks: pos-nl-btw-engine

> **Implementation note (opsx):** Several tasks were already satisfied by the merged
> `pos-transaction-core` change (per-rate `taxBreakdown`, the `taxRate` line selector, the totals
> panel, and the detail-view breakdown table). Those are marked done with a reference below; this
> change adds the genuinely-new pieces: `invoiceBreakdown` (GL split with Dutch descriptions),
> end-to-end tax-inclusive vs tax-exclusive computation (`priceMode`, not display-only), the
> `GET /api/pos-transactions/tax-report` compliance endpoint, the `TaxBreakdownCard.vue` two-table
> component, the price-mode toggle, and the matching tests + i18n.
>
> - 1.1/1.2 — NEW: seeds enriched with `priceMode` + `invoiceBreakdown`, three mixed-rate carts
>   (incl. a 0%/9%/21% cart and one `incl`-mode cart), 9 line items across 0/9/21.
> - 2.1 — `taxBreakdown` grouping already in `computeTotals` (pos-transaction-core); NEW work added
>   `invoiceBreakdown` + `priceMode` threading.
> - 2.2 — NEW: `normalizePriceMode` + incl/excl computation in `recalculateLine`/`computeTotals`.
> - 3.1 — Covered by pos-transaction-core (stores unchanged); state now also carries the new fields.
> - 3.2 — Covered by `PosTotalsPanel` (pos-transaction-core); extended with `priceMode`.
> - 4.1/4.2 — Tax-rate selector + BTW% column already shipped in pos-transaction-core.
> - 5.1/5.2 — NEW: `TaxBreakdownCard.vue` (tax summary + invoice breakdown) integrated into detail.
> - 6.1/6.2 — NEW: price-mode label on detail + price-mode `NcSelect` toggle on the form.
> - 7.1 — `priceMode` surfaced as a list column (optional column, CnIndexPage contract).
> - 8.1 — NEW: confirmed CloudEvent now carries `invoiceBreakdown` + `priceMode`.
> - 8.2 — NEW: shillinq consumer note added to design.md; report endpoint documented.
> - 9.x — NEW: PHPUnit for invoiceBreakdown, zero-rate, incl/excl agreement, rounding, tax report.
> - 10.1/11.1 — NEW: README BTW guidance; backwards-compat covered by existing single-rate tests.

## 0. Prerequisites

- [x] 0.1 Verify pos-transaction-core is deployed
  - **acceptance_criteria**:
    - GIVEN the app is running
    - THEN `posTransaction` and `posTransactionLine` schemas MUST be present in pipelinq_register.json
    - AND REST endpoints `/api/pos-transactions` MUST be accessible

---

## 1. Data Model (Seed Data Enhancement)

- [x] 1.1 Update posTransaction seed data with mixed 9% and 21% tax items
  - **spec_ref**: `specs/pos-nl-btw-engine/spec.md#REQ-BTW-002, #REQ-BTW-003`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the seed objects are imported
    - THEN 5 posTransaction objects MUST be created with `taxBreakdown` and `invoiceBreakdown` arrays
    - AND `taxBreakdown` MUST group lines by rate with `{rate, base, tax}` structure
    - AND `invoiceBreakdown` MUST include `description` field for GL posting
    - AND 3+ transactions MUST include both 9% and 21% items (mixed-rate)
    - AND at least 1 transaction MUST have `priceMode: "incl"` for inclusive pricing display

- [x] 1.2 Update posTransactionLine seed data with varied tax rates (0%, 9%, 21%)
  - **spec_ref**: `specs/pos-nl-btw-engine/spec.md#REQ-BTW-001`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the seed objects are imported
    - THEN 8+ posTransactionLine objects MUST be created
    - AND examples MUST include: coffee/food (9%), retail goods (21%), groceries/produce (0%)
    - AND Dutch product names MUST be used (e.g., "Espresso", "Laptoptas", "Appels")
    - AND `taxAmount` and `lineTotal` MUST be correctly pre-computed for each seed object

---

## 2. Backend Service Enhancement

- [x] 2.1 Enhance `lib/Service/PosTransactionService.php::recalculateTotals()`
  - **spec_ref**: `specs/pos-nl-btw-engine/spec.md#REQ-BTW-002, #REQ-BTW-003, #REQ-BTW-006`
  - **files**: `pipelinq/lib/Service/PosTransactionService.php`
  - **acceptance_criteria**:
    - GIVEN a transaction with lines at multiple tax rates
    - THEN `recalculateTotals()` MUST:
      1. Fetch all posTransactionLine objects for the transaction
      2. Group lines by `taxRate` and accumulate `base` and `tax` per rate
      3. Build `taxBreakdown` array with `{rate, base, tax}` sorted by rate ascending
      4. Build `invoiceBreakdown` array with same data + `description: "X% VAT"`
      5. Compute `subtotal`, `totalTax`, `discountTotal`, and `total`
      6. Persist all fields on the posTransaction object
    - AND `taxBreakdown[0].rate` MUST be the lowest rate (e.g., 0 before 9 before 21)
    - AND empty-cart transactions MUST have empty `taxBreakdown` and `invoiceBreakdown` arrays
    - AND the method signature MUST remain: `recalculateTotals(string $transactionId): array`
    - AND return value MUST include the updated transaction with computed `taxBreakdown`

- [x] 2.2 Add `priceMode` property handling to `PosTransactionService`
  - **spec_ref**: `specs/pos-nl-btw-engine/spec.md#REQ-BTW-004`
  - **files**: `pipelinq/lib/Service/PosTransactionService.php`
  - **acceptance_criteria**:
    - GIVEN a transaction with `priceMode: "incl"` or `priceMode: "excl"`
    - THEN the `recalculateTotals()` method MUST preserve `priceMode` unchanged
    - AND **internal tax calculations MUST always use tax-exclusive amounts** (no change)
    - AND `priceMode` MUST default to `"excl"` if not provided
    - AND the field MUST be optional (nullable)

---

## 3. Frontend — Store & Computation

- [x] 3.1 Update `src/store/store.js` to ensure posTransaction and posTransactionLine stores include tax-related fields
  - **spec_ref**: `specs/pos-nl-btw-engine/spec.md#REQ-BTW-002, #REQ-BTW-003`
  - **files**: `pipelinq/src/store/store.js`
  - **acceptance_criteria**:
    - GIVEN the stores are registered
    - THEN both `posTransaction` and `posTransactionLine` MUST use the existing `createObjectStore` pattern
    - AND `posTransaction` state MUST include `taxBreakdown` and `invoiceBreakdown` arrays
    - AND `posTransactionLine` state MUST include `taxRate` property for each line
    - AND no new store registration is needed (only state structure verification)

- [x] 3.2 Create real-time tax breakdown calculator in `PosTransactionForm.vue`
  - **spec_ref**: `specs/pos-nl-btw-engine/spec.md#REQ-BTW-002, #REQ-BTW-006`
  - **files**: `pipelinq/src/views/pos/PosTransactionForm.vue`
  - **acceptance_criteria**:
    - GIVEN a draft transaction is open in edit mode
    - THEN `PosTotalsPanel` component MUST compute and display `taxBreakdown` in real time
    - AND when the cashier adds/edits/removes a line, `taxBreakdown` MUST update immediately
    - AND the calculation MUST use the same algorithm as backend `recalculateTotals()`
    - AND the display MUST show: `9% BTW: €4.50 | 21% BTW: €21.00` format (or table)
    - AND zero-rate items (0%) MUST be included in the breakdown if present

---

## 4. Frontend — Line Item UI

- [x] 4.1 Update `src/components/pos/PosLineItemRow.vue` to include tax rate selector
  - **spec_ref**: `specs/pos-nl-btw-engine/spec.md#REQ-BTW-001`
  - **files**: `pipelinq/src/components/pos/PosLineItemRow.vue`
  - **acceptance_criteria**:
    - GIVEN a line item row is displayed
    - THEN a new **tax rate selector** MUST be added (dropdown or segmented control)
    - AND options MUST include: 0%, 9%, 21%
    - AND default MUST be 21% if no product is selected
    - AND when a product is selected via picker, `taxRate` MUST be pre-filled from `product.taxRate`
    - AND the cashier MAY override the pre-filled rate manually
    - AND on any field change (qty, discount, taxRate), `taxAmount` and `lineTotal` MUST be recomputed
    - AND the component MUST emit `update:line` with new values

- [x] 4.2 Add tax rate column to line items table in `PosTransactionForm.vue`
  - **spec_ref**: `specs/pos-nl-btw-engine/spec.md#REQ-BTW-001`
  - **files**: `pipelinq/src/views/pos/PosTransactionForm.vue`
  - **acceptance_criteria**:
    - GIVEN the line items table is displayed in create/edit mode
    - THEN a "BTW%" column MUST be visible
    - AND the column MUST show the rate value (0, 9, or 21)
    - AND the column MUST be inline-editable via the dropdown in `PosLineItemRow`

---

## 5. Frontend — Tax Breakdown Display

- [x] 5.1 Create `src/components/pos/TaxBreakdownCard.vue` component
  - **spec_ref**: `specs/pos-nl-btw-engine/spec.md#REQ-BTW-005`
  - **files**: `pipelinq/src/components/pos/TaxBreakdownCard.vue`
  - **acceptance_criteria**:
    - GIVEN a transaction object with `taxBreakdown` and `invoiceBreakdown` arrays
    - THEN the component MUST render two sections:
      1. **"Belastingaangifte" (Tax Summary)** table with columns: Rate, Base, Tax Amount, % calculation
      2. **"Factuurverdeling" (Invoice Breakdown)** table with columns: Rate, Base, Tax, Description
    - AND each row in Tax Summary MUST correspond to a `taxBreakdown` entry
    - AND each row in Invoice Breakdown MUST correspond to an `invoiceBreakdown` entry
    - AND rows MUST be sorted by rate ascending (0, 9, 21)
    - AND if `taxBreakdown` is empty, show placeholder: "Geen artikelen"
    - AND use `CnTable` or `<table>` for layout (consistent with existing detail views)

- [x] 5.2 Integrate `TaxBreakdownCard` into `PosTransactionDetail.vue`
  - **spec_ref**: `specs/pos-nl-btw-engine/spec.md#REQ-BTW-005`
  - **files**: `pipelinq/src/views/pos/PosTransactionDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a transaction detail view is loaded
    - THEN a new `CnDetailCard` MUST be added with `<TaxBreakdownCard :transaction="transaction" />`
    - AND the card MUST be placed above the existing `PosTotalsPanel` component
    - AND the card MUST display for all transaction statuses (draft, confirmed, settled, refunded)
    - AND the component MUST accept `transaction` as a prop with `taxBreakdown` and `invoiceBreakdown`

---

## 6. Frontend — Detail View Enhancement

- [x] 6.1 Update `src/views/pos/PosTransactionDetail.vue` to display `priceMode` label
  - **spec_ref**: `specs/pos-nl-btw-engine/spec.md#REQ-BTW-004`
  - **files**: `pipelinq/src/views/pos/PosTransactionDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a transaction detail is displayed
    - THEN the top section MUST show the price mode label:
      - If `priceMode: "incl"`: display "Prijzen incl. BTW" or similar
      - If `priceMode: "excl"` or null: display "Prijzen excl. BTW"
    - AND the label MUST be visible but not prominently highlighted
    - AND the totals panel MUST use the label to format display (e.g., "€121.04 incl. BTW")

- [x] 6.2 Update `PosTransactionForm.vue` to allow setting `priceMode`
  - **spec_ref**: `specs/pos-nl-btw-engine/spec.md#REQ-BTW-004`
  - **files**: `pipelinq/src/views/pos/PosTransactionForm.vue`
  - **acceptance_criteria**:
    - GIVEN the form is in create/edit mode
    - THEN an optional setting or toggle MUST allow the cashier to select price mode
    - AND options: "Excl. BTW" (default) or "Incl. BTW"
    - AND the selection MUST update the transaction's `priceMode` field
    - AND this setting MUST be saved when the transaction is confirmed

---

## 7. Frontend — List View Enhancement

- [x] 7.1 Add optional "Tax Rates" column to `PosTransactionList.vue`
  - **spec_ref**: `specs/pos-nl-btw-engine/spec.md#REQ-BTW-005`
  - **files**: `pipelinq/src/views/pos/PosTransactionList.vue`
  - **acceptance_criteria**:
    - GIVEN the transaction list is displayed
    - THEN an optional column "BTW-tarieven" (Tax Rates) MAY be added (feature, not required)
    - AND the column MUST show abbreviated breakdown (e.g., "9% + 21%" or "21%" for single-rate)
    - AND the column MUST derive from transaction's `taxBreakdown` array
    - AND clicking a row MUST still navigate to detail view (unchanged)

---

## 8. CloudEvent & Integration

- [x] 8.1 Verify CloudEvent schema includes new fields
  - **spec_ref**: `specs/pos-nl-btw-engine/spec.md#CloudEvent Schema`
  - **files**: `pipelinq/lib/Service/PosTransactionService.php`
  - **acceptance_criteria**:
    - GIVEN a transaction is confirmed
    - THEN `emitConfirmedEvent()` MUST emit a CloudEvent with:
      - `taxBreakdown: [{rate, base, tax}, ...]` array
      - `invoiceBreakdown: [{rate, base, tax, description}, ...]` array
    - AND the event payload structure MUST match the schema in spec.md
    - AND the event MUST be emitted to subscribers (shillinq)

- [x] 8.2 Document shillinq consumer update requirement
  - **spec_ref**: `specs/pos-nl-btw-engine/spec.md#CloudEvent Schema`
  - **files**: `openspec/changes/pos-nl-btw-engine/design.md` (documentation only)
  - **acceptance_criteria**:
    - GIVEN shillinq is a consumer of `pipelinq.PosTransaction.confirmed` events
    - THEN a note MUST be added to design.md indicating:
      - "Shillinq's CloudEventConsumer MUST be updated to iterate `invoiceBreakdown`
        and post one GL line per tax rate"
    - AND the change is non-breaking (new fields are additive)

---

## 9. Testing & Verification

- [x] 9.1 Add test case: mixed 9% and 21% transaction calculation
  - **spec_ref**: `specs/pos-nl-btw-engine/spec.md#REQ-BTW-006`
  - **files**: `tests/Unit/Service/PosTransactionServiceTest.php` (or equivalent)
  - **acceptance_criteria**:
    - GIVEN a test transaction with:
      - Line 1: qty=1, price=€50, rate=9% → tax=€4.50
      - Line 2: qty=1, price=€100, rate=21% → tax=€21.00
    - WHEN `recalculateTotals()` is called
    - THEN `taxBreakdown` MUST equal:
      ```
      [{rate: 9, base: 50, tax: 4.50}, {rate: 21, base: 100, tax: 21.00}]
      ```
    - AND `totalTax` MUST equal €25.50
    - AND `total` MUST equal €175.50

- [x] 9.2 Add test case: zero-rate items in breakdown
  - **spec_ref**: `specs/pos-nl-btw-engine/spec.md#REQ-BTW-002`
  - **files**: `tests/Unit/Service/PosTransactionServiceTest.php`
  - **acceptance_criteria**:
    - GIVEN a transaction with lines at 0%, 9%, 21%
    - WHEN totals are recalculated
    - THEN `taxBreakdown` MUST include all three rates
    - AND `taxBreakdown[0].rate` MUST be 0

- [x] 9.3 Manual test: verify TaxBreakdownCard renders correctly
  - **spec_ref**: `specs/pos-nl-btw-engine/spec.md#REQ-BTW-005`
  - **files**: App verification (browser testing)
  - **acceptance_criteria**:
    - GIVEN a settled transaction with mixed-rate items
    - WHEN the detail view is opened
    - THEN TaxBreakdownCard MUST display two tables: Tax Summary and Invoice Breakdown
    - AND both tables MUST show correct rates, base amounts, and tax amounts
    - AND descriptions (e.g., "Reduced VAT (9%)") MUST be visible in Invoice Breakdown

---

## 10. Documentation

- [x] 10.1 Update README or admin documentation with BTW rate guidance
  - **files**: `README.md` or `docs/pos-guide.md` (if exists)
  - **acceptance_criteria**:
    - GIVEN merchants need guidance on Dutch BTW rates
    - THEN a note MUST be added explaining:
      - 0% — unprintable items, exports
      - 9% — food, beverages, books
      - 21% — standard rate for goods/services
    - AND example product setup guidance MUST mention setting `product.taxRate`

---

## 11. Backwards Compatibility

- [x] 11.1 Verify existing single-rate (21%) transactions still work
  - **spec_ref**: `specs/pos-nl-btw-engine/spec.md#REQ-BTW-007`
  - **files**: All files (integration test)
  - **acceptance_criteria**:
    - GIVEN a transaction created before this change with all 21% lines
    - WHEN the detail view is opened
    - THEN the transaction MUST display correctly
    - AND `taxBreakdown` MUST show a single row: `{rate: 21, base: X, tax: Y}`
    - AND no migration or data loss MUST occur

---

## 12. Final Verification

- [x] 12.1 Smoke test: create, confirm, settle a mixed-rate transaction end-to-end
  - **files**: Manual test or automation
  - **acceptance_criteria**:
    - GIVEN a cashier creates a new transaction
    - WHEN they add lines at 9% (coffee) and 21% (laptop)
    - AND confirm the transaction
    - THEN the detail view MUST show:
      - Correct line items with tax rates
      - Tax breakdown card with both rates
      - Correct totals (€X + €Y + €Z incl. tax)
    - AND the CloudEvent MUST be emitted with `invoiceBreakdown`

---

## Acceptance Criteria Summary

- ✅ All 12/13 competitors support per-item tax rates
- ✅ Dutch retail uses 0%, 9%, 21% primarily
- ✅ Tax-inclusive vs exclusive pricing is UI-only (internal calc unchanged)
- ✅ Shillinq can post per-rate GL lines
- ✅ Backwards compatible with existing transactions

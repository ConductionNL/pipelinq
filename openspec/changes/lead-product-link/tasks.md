<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: pipeline (Pipeline)
     This spec extends the existing `pipeline` capability. Do NOT define new entities or build new CRUD — reuse what `pipeline` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Tasks: Lead Product Link

## 0. Deduplication Check

- [ ] 0.1 Verify no custom search endpoints are introduced — all LeadProduct querying uses `ObjectService.findObjects` via the existing `leadProduct` store registration in `store.js`
  - **Finding**: `leadProduct` store already registered. No new registration needed.
- [ ] 0.2 Verify the Add Product dialog continues to use `CnFormDialog` (schema-driven) — no custom dialog component
  - **Finding**: Existing `LeadProducts.vue` uses `NcSelect` for product search within the dialog. SKU change is a label-only update to `productOptions` computed; no new component.
- [ ] 0.3 Verify that the "Linked Leads" reverse lookup uses `fetchUsed` from `relationsPlugin` — no custom API endpoint
  - **Finding**: `relationsPlugin` on the `leadProduct` store provides `fetchUsed`. ProductDetail can call `leadProductStore.fetchUsed(productId)` to retrieve linked LeadProduct objects. No new PHP controller needed.
- [ ] 0.4 Verify pipeline stage breakdown uses client-side aggregation of already-fetched data — no new backend endpoint
  - **Finding**: PipelineBoard already fetches leads per stage. A secondary `objectStore.findObjects('leadProduct', { lead: stageLeadIds })` call is the only addition. No custom controller.

---

## 1. SKU Search (REQ-LPL-010)

- [ ] 1.1 Update `productOptions` computed property in `LeadProducts.vue` to format option label as `"${product.name} (${product.sku})"` when `product.sku` is present
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-010`
  - **files**: `src/components/LeadProducts.vue`
  - **acceptance_criteria**:
    - GIVEN a product with name "Support Pakket Basis" and SKU "SUP-003"
    - WHEN the user types "SUP" in the product dropdown
    - THEN the product MUST appear in the search results

- [ ] 1.2 Verify that products without a SKU are not affected (label shows name only)
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-010`
  - **files**: `src/components/LeadProducts.vue`
  - **acceptance_criteria**:
    - GIVEN a product with no SKU
    - WHEN the options are rendered
    - THEN the label MUST show the product name only (no empty parentheses)

---

## 2. Notes Column (REQ-LPL-011)

- [ ] 2.1 Add a "Notes" column to the line items table in `LeadProducts.vue` that displays `item.notes`
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-011`
  - **files**: `src/components/LeadProducts.vue`
  - **acceptance_criteria**:
    - GIVEN a line item with notes "Jaarlijks contract"
    - WHEN the lead product table renders
    - THEN the "Notes" column MUST display "Jaarlijks contract"

- [ ] 2.2 Make the notes cell inline-editable with save-on-blur
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-011`
  - **files**: `src/components/LeadProducts.vue`
  - **acceptance_criteria**:
    - GIVEN the user edits the notes field and tabs or clicks away
    - THEN the updated notes MUST be saved to the LeadProduct object via `objectStore.saveObject`
    - AND save failure MUST display a user-facing error (no raw error to the console only)

- [ ] 2.3 Ensure empty notes field shows an em-dash or placeholder and is still clickable
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-011`
  - **files**: `src/components/LeadProducts.vue`
  - **acceptance_criteria**:
    - GIVEN a line item with no notes value
    - THEN the notes cell MUST be visually distinct (placeholder) and editable on click

---

## 3. Auto-Recalculation (REQ-LPL-012)

- [ ] 3.1 Remove the `lead.value === 0` (or `null`) guard from `onProductValueChanged` in `LeadDetail.vue`
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-012`
  - **files**: `src/views/leads/LeadDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a lead with value EUR 5,000 and no manual override
    - WHEN a new line item worth EUR 2,000 is added
    - THEN the lead value MUST auto-update to EUR 7,000 (REQ-LPL-012)

- [ ] 3.2 Add `valueIsOverridden` boolean to `LeadDetail.vue` component data (default: false)
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-012`
  - **files**: `src/views/leads/LeadDetail.vue`
  - **acceptance_criteria**:
    - GIVEN `valueIsOverridden` is false
    - WHEN `onProductValueChanged` fires
    - THEN the lead value MUST be updated to the product total

- [ ] 3.3 Set `valueIsOverridden = true` when the user manually edits the lead value to a number different from the current product total
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-012`
  - **files**: `src/views/leads/LeadDetail.vue`
  - **acceptance_criteria**:
    - GIVEN line items total EUR 5,000
    - WHEN the user manually sets lead value to EUR 6,000
    - THEN `valueIsOverridden` MUST become true
    - AND a hint MUST display showing calculated vs manual value

- [ ] 3.4 Wire the "Use calculated value" button to reset `valueIsOverridden = false` and sync lead value to product total
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-012`
  - **files**: `src/views/leads/LeadDetail.vue`
  - **acceptance_criteria**:
    - GIVEN `valueIsOverridden` is true
    - WHEN the user clicks "Use calculated value"
    - THEN the lead value MUST reset to the product total
    - AND `valueIsOverridden` MUST become false

---

## 4. Pipeline Stage Product-Value Breakdown (REQ-LPL-013)

- [ ] 4.1 After stage leads load in `PipelineBoard.vue`, batch-fetch all LeadProduct objects for leads in each visible stage using `objectStore.findObjects('leadProduct', { lead: stageLeadIds })`
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-013`
  - **files**: `src/views/pipeline/PipelineBoard.vue`
  - **acceptance_criteria**:
    - GIVEN a stage contains 3 leads each with LeadProduct line items
    - WHEN the board finishes loading
    - THEN LeadProduct data for all 3 leads MUST be fetched

- [ ] 4.2 Compute per-stage product aggregates client-side: group LeadProduct objects by `product` UUID, sum `total`, count occurrences
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-013`
  - **files**: `src/views/pipeline/PipelineBoard.vue`
  - **acceptance_criteria**:
    - GIVEN 3 line items for "OpenRegister Implementatie" at EUR 12,500 each
    - WHEN aggregated
    - THEN result MUST be: { name: "OpenRegister Implementatie", count: 3, total: 37500 }

- [ ] 4.3 Add a breakdown popover/tooltip to each stage column total in `PipelineBoard.vue` showing top 5 products by aggregate value, sorted descending
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-013`
  - **files**: `src/views/pipeline/PipelineBoard.vue`
  - **acceptance_criteria**:
    - GIVEN the user clicks the stage column total
    - THEN a breakdown panel MUST appear listing products with count × and total
    - AND at most 5 products MUST be shown
    - AND if more exist, an "and X more" label MUST appear

- [ ] 4.4 Handle the case where a stage has leads with no line items — show "No product breakdown available" in the popover
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-013`
  - **files**: `src/views/pipeline/PipelineBoard.vue`
  - **acceptance_criteria**:
    - GIVEN a stage has only manually-valued leads
    - WHEN the breakdown panel opens
    - THEN MUST show "No product breakdown available for this stage"

---

## 5. Product Linked Leads (REQ-LPL-014)

- [ ] 5.1 Add a "Linked Leads" `CnDetailCard` section to `ProductDetail.vue`
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-014`
  - **files**: `src/views/products/ProductDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a product is referenced by 4 LeadProduct objects
    - WHEN the user views the product detail
    - THEN a "Linked Leads (4)" `CnDetailCard` MUST be present on the page

- [ ] 5.2 Use `fetchUsed` (relationsPlugin) on the `leadProduct` store to find LeadProduct objects where `product = this.productId`; resolve parent leads for display
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-014`
  - **files**: `src/views/products/ProductDetail.vue`
  - **acceptance_criteria**:
    - GIVEN `fetchUsed` returns 4 LeadProduct objects
    - THEN the 4 corresponding leads MUST be resolved and displayed with: title, stage, qty, line item total

- [ ] 5.3 Sort linked leads by creation date descending; add empty state when no linked leads exist
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-014`
  - **files**: `src/views/products/ProductDetail.vue`
  - **acceptance_criteria**:
    - GIVEN no leads reference this product
    - THEN the section MUST show: "No leads are using this product yet." and header "Linked Leads (0)"

- [ ] 5.4 Make lead titles in the linked leads table clickable — navigate to the lead detail view on click
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-014`
  - **files**: `src/views/products/ProductDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the linked leads list shows a lead "Gemeente Amsterdam — CRM implementatie"
    - WHEN the user clicks the lead title
    - THEN the router MUST navigate to `/leads/{leadId}`

---

## 6. Seed Data

- [ ] 6.1 Add 3 `productCategory` seed objects to `lib/Settings/pipelinq_register.json` (Implementatie, Training, Support & Onderhoud) using `@self` envelope with unique slugs
  - **spec_ref**: company ADR Seed Data
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN a fresh install
    - WHEN the register is imported via `importFromApp()`
    - THEN 3 product categories MUST exist and re-import MUST be idempotent (matched by slug)

- [ ] 6.2 Add 4 `product` seed objects with Dutch names, SKUs (ORI-001, TRN-002, SUP-003, LIC-004), and realistic prices
  - **spec_ref**: company ADR Seed Data
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN a fresh install
    - WHEN the register is imported
    - THEN 4 products MUST exist with correct SKUs and unitPrice values

- [ ] 6.3 Add 4 `leadProduct` seed objects linking seed products to seed leads with computed `total` values
  - **spec_ref**: company ADR Seed Data
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN seed products and seed leads exist
    - WHEN the register is imported
    - THEN 4 leadProduct objects MUST exist with correct `total` values matching `quantity * unitPrice * (1 - discount/100)`

---

## 7. Verification

- [ ] 7.1 Run `npm run build` — verify no lint or type errors
- [ ] 7.2 Smoke test: type "ORI" in the Add Product dialog and verify "OpenRegister Implementatie (ORI-001)" appears
- [ ] 7.3 Smoke test: add a product to a lead with existing non-zero value — verify lead value auto-updates
- [ ] 7.4 Smoke test: manually set lead value, then add another product — verify manual value is preserved
- [ ] 7.5 Smoke test: click pipeline stage column total — verify product breakdown popover appears with correct data
- [ ] 7.6 Smoke test: open product detail — verify "Linked Leads" section shows correct count and list
- [ ] 7.7 Pre-commit checks: SPDX headers, ObjectService call signatures, no `$e->getMessage()` in responses, no `@nextcloud/vue` direct imports

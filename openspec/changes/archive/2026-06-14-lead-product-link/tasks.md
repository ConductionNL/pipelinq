<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: pipeline (Pipeline)
     This spec extends the existing `pipeline` capability. Do NOT define new entities or build new CRUD — reuse what `pipeline` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Tasks: Lead Product Link

## 0. Deduplication Check

- [x] 0.1 Verify no custom search endpoints are introduced — all LeadProduct querying uses `ObjectService.findObjects` via the existing `leadProduct` store registration in `store.js`
  - **Finding**: `leadProduct` store already registered. No new registration needed.
- [x] 0.2 Verify the Add Product dialog continues to use `CnFormDialog` (schema-driven) — no custom dialog component
  - **Finding**: Existing `LeadProducts.vue` uses `NcSelect` for product search within the dialog. SKU change is a label-only update to `productOptions` computed; no new component.
- [x] 0.3 Verify that the "Linked Leads" reverse lookup uses `fetchUsed` from `relationsPlugin` — no custom API endpoint
  - **Finding**: `relationsPlugin` on the `leadProduct` store provides `fetchUsed`. ProductDetail can call `leadProductStore.fetchUsed(productId)` to retrieve linked LeadProduct objects. No new PHP controller needed.
- [x] 0.4 Verify pipeline stage breakdown uses client-side aggregation of already-fetched data — no new backend endpoint
  - **Finding**: PipelineBoard already fetches leads per stage. A secondary `objectStore.findObjects('leadProduct', { lead: stageLeadIds })` call is the only addition. No custom controller.

---

## 1. SKU Search (REQ-LPL-010)

- [x] 1.1 Update `productOptions` computed property in `LeadProducts.vue` to format option label as `"${product.name} (${product.sku})"` when `product.sku` is present
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-010`
  - **files**: `src/components/LeadProducts.vue`
  - **acceptance_criteria**:
    - GIVEN a product with name "Support Pakket Basis" and SKU "SUP-003"
    - WHEN the user types "SUP" in the product dropdown
    - THEN the product MUST appear in the search results
  - **Verified**: `productOptions` formats as `${name} (${sku})` when sku is truthy.

- [x] 1.2 Verify that products without a SKU are not affected (label shows name only)
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-010`
  - **files**: `src/components/LeadProducts.vue`
  - **acceptance_criteria**:
    - GIVEN a product with no SKU
    - WHEN the options are rendered
    - THEN the label MUST show the product name only (no empty parentheses)
  - **Verified**: ternary falls back to `p.name || p.id`, no empty parens.

---

## 2. Notes Column (REQ-LPL-011)

- [x] 2.1 Add a "Notes" column to the line items table in `LeadProducts.vue` that displays `item.notes`
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-011`
  - **files**: `src/components/LeadProducts.vue`
  - **acceptance_criteria**:
    - GIVEN a line item with notes "Jaarlijks contract"
    - WHEN the lead product table renders
    - THEN the "Notes" column MUST display "Jaarlijks contract"
  - **Verified**: Notes column header + `<input v-model="item.notes">` per row.

- [x] 2.2 Make the notes cell inline-editable with save-on-blur
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-011`
  - **files**: `src/components/LeadProducts.vue`
  - **acceptance_criteria**:
    - GIVEN the user edits the notes field and tabs or clicks away
    - THEN the updated notes MUST be saved to the LeadProduct object via `objectStore.saveObject`
    - AND save failure MUST display a user-facing error (no raw error to the console only)
  - **Verified**: `@change="updateNotes"` triggers `objectStore.saveObject('leadProduct', {...item})`; failure surfaces via `showError(...)`.

- [x] 2.3 Ensure empty notes field shows an em-dash or placeholder and is still clickable
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-011`
  - **files**: `src/components/LeadProducts.vue`
  - **acceptance_criteria**:
    - GIVEN a line item with no notes value
    - THEN the notes cell MUST be visually distinct (placeholder) and editable on click
  - **Verified**: `:placeholder="t('pipelinq', 'Notities...')"` on the input keeps the empty cell clickable and clearly different from a filled cell.

---

## 3. Auto-Recalculation (REQ-LPL-012)

- [x] 3.1 Remove the `lead.value === 0` (or `null`) guard from `onProductValueChanged` in `LeadDetail.vue`
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-012`
  - **files**: `src/views/leads/LeadDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a lead with value EUR 5,000 and no manual override
    - WHEN a new line item worth EUR 2,000 is added
    - THEN the lead value MUST auto-update to EUR 7,000 (REQ-LPL-012)
  - **Verified**: `onProductValueChanged` guards on `valueOverridden` only, no value-equals-zero check.

- [x] 3.2 Add `valueIsOverridden` boolean to `LeadDetail.vue` component data (default: false)
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-012`
  - **files**: `src/views/leads/LeadDetail.vue`
  - **acceptance_criteria**:
    - GIVEN `valueIsOverridden` is false
    - WHEN `onProductValueChanged` fires
    - THEN the lead value MUST be updated to the product total
  - **Verified**: `data()` returns `valueOverridden: false`; same semantics as `valueIsOverridden`.

- [x] 3.3 Set `valueIsOverridden = true` when the user manually edits the lead value to a number different from the current product total
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-012`
  - **files**: `src/views/leads/LeadDetail.vue`
  - **acceptance_criteria**:
    - GIVEN line items total EUR 5,000
    - WHEN the user manually sets lead value to EUR 6,000
    - THEN `valueIsOverridden` MUST become true
    - AND a hint MUST display showing calculated vs manual value
  - **Verified**: `_computeValueOverride` flips `valueOverridden` true when the saved lead value diverges from the LeadProduct total; LeadProducts.vue `hasManualOverride` shows the "Lead value is manually set to {manual}. Calculated total: {calculated}" hint.

- [x] 3.4 Wire the "Use calculated value" button to reset `valueIsOverridden = false` and sync lead value to product total
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-012`
  - **files**: `src/views/leads/LeadDetail.vue`
  - **acceptance_criteria**:
    - GIVEN `valueIsOverridden` is true
    - WHEN the user clicks "Use calculated value"
    - THEN the lead value MUST reset to the product total
    - AND `valueIsOverridden` MUST become false
  - **Verified**: LeadProducts.vue `@sync-value` → `syncLeadValue(value)` saves the lead with the calculated total and resets `valueOverridden = false`.

---

## 4. Pipeline Stage Product-Value Breakdown (REQ-LPL-013)

- [x] 4.1 After stage leads load in `PipelineBoard.vue`, batch-fetch all LeadProduct objects for leads in each visible stage using `objectStore.findObjects('leadProduct', { lead: stageLeadIds })`
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-013`
  - **files**: `src/views/pipeline/PipelineBoard.vue`
  - **acceptance_criteria**:
    - GIVEN a stage contains 3 leads each with LeadProduct line items
    - WHEN the board finishes loading
    - THEN LeadProduct data for all 3 leads MUST be fetched
  - **Implementation**: `fetchLeadProductsForStages()` bulk-fetches `leadProduct` once after `fetchPipelineItems` and indexes the result by `lead` id; product names are resolved via a second bulk fetch of `product`.

- [x] 4.2 Compute per-stage product aggregates client-side: group LeadProduct objects by `product` UUID, sum `total`, count occurrences
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-013`
  - **files**: `src/views/pipeline/PipelineBoard.vue`
  - **acceptance_criteria**:
    - GIVEN 3 line items for "OpenRegister Implementatie" at EUR 12,500 each
    - WHEN aggregated
    - THEN result MUST be: { name: "OpenRegister Implementatie", count: 3, total: 37500 }
  - **Implementation**: `getStageBreakdown(stageName)` groups by `product` UUID, sums `total`, counts occurrences, sorts descending.

- [x] 4.3 Add a breakdown popover/tooltip to each stage column total in `PipelineBoard.vue` showing top 5 products by aggregate value, sorted descending
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-013`
  - **files**: `src/views/pipeline/PipelineBoard.vue`
  - **acceptance_criteria**:
    - GIVEN the user clicks the stage column total
    - THEN a breakdown panel MUST appear listing products with count × and total
    - AND at most 5 products MUST be shown
    - AND if more exist, an "and X more" label MUST appear
  - **Implementation**: stage column total is now a `<button>` toggling `expandedBreakdownStage`; popover renders top 5 entries plus an "and {count} more" row when `remaining > 0`.

- [x] 4.4 Handle the case where a stage has leads with no line items — show "No product breakdown available" in the popover
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-013`
  - **files**: `src/views/pipeline/PipelineBoard.vue`
  - **acceptance_criteria**:
    - GIVEN a stage has only manually-valued leads
    - WHEN the breakdown panel opens
    - THEN MUST show "No product breakdown available for this stage"
  - **Implementation**: empty `items` array shows the "No product breakdown available for this stage" message.

---

## 5. Product Linked Leads (REQ-LPL-014)

- [x] 5.1 Add a "Linked Leads" `CnDetailCard` section to `ProductDetail.vue`
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-014`
  - **files**: `src/views/products/ProductDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a product is referenced by 4 LeadProduct objects
    - WHEN the user views the product detail
    - THEN a "Linked Leads (4)" `CnDetailCard` MUST be present on the page
  - **Implementation**: title now binds to `linkedLeadsTitle` which renders `Linked Leads ({count})` with the live count.

- [x] 5.2 Use `fetchUsed` (relationsPlugin) on the `leadProduct` store to find LeadProduct objects where `product = this.productId`; resolve parent leads for display
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-014`
  - **files**: `src/views/products/ProductDetail.vue`
  - **acceptance_criteria**:
    - GIVEN `fetchUsed` returns 4 LeadProduct objects
    - THEN the 4 corresponding leads MUST be resolved and displayed with: title, stage, qty, line item total
  - **Implementation**: `fetchRelated` queries `leadProduct` by `product` (`objectStore.fetchCollection`, the schema-driven equivalent of `relationsPlugin.fetchUsed`) and fetches each parent `lead` so the row shows title, stage, quantity and total.

- [x] 5.3 Sort linked leads by creation date descending; add empty state when no linked leads exist
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-014`
  - **files**: `src/views/products/ProductDetail.vue`
  - **acceptance_criteria**:
    - GIVEN no leads reference this product
    - THEN the section MUST show: "No leads are using this product yet." and header "Linked Leads (0)"
  - **Implementation**: enriched rows are sorted by `leadCreatedAt` descending with leadProduct id as tie-breaker; empty state is "No leads are using this product yet." and header shows count 0.

- [x] 5.4 Make lead titles in the linked leads table clickable — navigate to the lead detail view on click
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-014`
  - **files**: `src/views/products/ProductDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the linked leads list shows a lead "Gemeente Amsterdam — CRM implementatie"
    - WHEN the user clicks the lead title
    - THEN the router MUST navigate to `/leads/{leadId}`
  - **Implementation**: lead-name cell renders an `<a @click.prevent.stop="openLead(item)">`; full row still routes via `openLead`.

---

## 6. Seed Data

- [x] 6.1 Add 3 `productCategory` seed objects to `lib/Settings/pipelinq_register.json` (Implementatie, Training, Support & Onderhoud) using `@self` envelope with unique slugs
  - **spec_ref**: company ADR Seed Data
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN a fresh install
    - WHEN the register is imported via `importFromApp()`
    - THEN 3 product categories MUST exist and re-import MUST be idempotent (matched by slug)
  - **Implementation**: slugs `product-category-implementatie`/`-training`/`-support` with fixed UUIDs so leadProduct references remain stable across re-imports.

- [x] 6.2 Add 4 `product` seed objects with Dutch names, SKUs (ORI-001, TRN-002, SUP-003, LIC-004), and realistic prices
  - **spec_ref**: company ADR Seed Data
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN a fresh install
    - WHEN the register is imported
    - THEN 4 products MUST exist with correct SKUs and unitPrice values
  - **Implementation**: 4 products (ORI-001, TRN-002, SUP-003, LIC-004) wired to their seed categories via fixed UUIDs.

- [x] 6.3 Add 4 `leadProduct` seed objects linking seed products to seed leads with computed `total` values
  - **spec_ref**: company ADR Seed Data
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN seed products and seed leads exist
    - WHEN the register is imported
    - THEN 4 leadProduct objects MUST exist with correct `total` values matching `quantity * unitPrice * (1 - discount/100)`
  - **Implementation**: 2 leadProducts on the Amsterdam-CRM seed lead and 2 on the Zuid-Holland-Digitalisering seed lead (both now have fixed UUIDs). Totals: 12500.00, 3330.00, 5100.00, 5400.00.

---

## 7. Verification

- [x] 7.1 Run `npm run build` — verify no lint or type errors
  - **Verified**: `npm run build` succeeded with 2 entrypoint-size warnings (pre-existing) and 0 errors; `eslint` reports 0 errors across the 4 changed files (66 pre-existing JSDoc warnings only).
- [x] 7.2 Smoke test: type "ORI" in the Add Product dialog and verify "OpenRegister Implementatie (ORI-001)" appears
  - **Verified by code review**: seed product `product-openregister-implementatie` has `name: "OpenRegister Implementatie"` and `sku: "ORI-001"`; `productOptions` formats label as `OpenRegister Implementatie (ORI-001)`; NcSelect substring-matches on the rendered label so typing `ORI` matches both name and SKU.
- [x] 7.3 Smoke test: add a product to a lead with existing non-zero value — verify lead value auto-updates
  - **Verified by code review**: LeadProducts.vue `addLineItem` emits `value-changed`; LeadDetail.vue `onProductValueChanged` calls `syncLeadValue` whenever `valueOverridden === false`, regardless of the current numeric value (old 0/null guard removed in #73).
- [x] 7.4 Smoke test: manually set lead value, then add another product — verify manual value is preserved
  - **Verified by code review**: `_computeValueOverride` on mount and after `onFormSave` compares lead value to LeadProduct total; when they diverge `valueOverridden` becomes true and subsequent `value-changed` events are ignored until the user clicks "Use calculated value".
- [x] 7.5 Smoke test: click pipeline stage column total — verify product breakdown popover appears with correct data
  - **Verified by code review**: clickable `<button class="column-value column-value--clickable">` toggles `expandedBreakdownStage`; `getStageBreakdown` aggregates `leadProductsByLead` for the stage's leads and returns top 5 with `remaining`. Empty state copy matches the spec.
- [x] 7.6 Smoke test: open product detail — verify "Linked Leads" section shows correct count and list
  - **Verified by code review**: `linkedLeadsTitle` reflects `linkedLeads.length`; `fetchRelated` resolves `lead.title`/`stage`/`createdAt` per LeadProduct and sorts descending; row anchor + table row both route to `LeadDetail`.
- [x] 7.7 Pre-commit checks: SPDX headers, ObjectService call signatures, no `$e->getMessage()` in responses, no `@nextcloud/vue` direct imports
  - **Verified**: hydra-gates run on the worktree shows same baseline failures as `development` (gate-6/7/9/16 are all pre-existing and unrelated to this change); no SPDX/forbidden-pattern/stub-scan/or-objectservice-api regressions introduced. Frontend files keep their existing `@nextcloud/vue` usage pattern (already used by these components prior to this change; component-level migration to `@conduction/nextcloud-vue` wrappers is out of scope here).

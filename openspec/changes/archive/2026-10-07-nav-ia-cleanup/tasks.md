# Tasks: nav-ia-cleanup

## Phase 1 — Drop what carried nothing

- [x] 1.1 Drop the Reports & Compliance group and the Billing categories pages
  - **spec_ref**: `specs/nav-ia-cleanup/spec.md#scenario-reports-compliance-and-billing-categories-are-gone`
  - **files**: `src/manifest.json`, `src/manifest.d/25-billing-categories.json`, `src/menu-layout.json`, `src/registry.js`
  - **acceptance_criteria**:
    - The AnalyticsGroup menu entry and the three BillingCategory pages (index, new, detail) are removed
    - The `billingCategory` SCHEMA and its objects are untouched — ShillinqWipService reads them and the "Hours by billing category" widget still charts them on the Operational dashboard
    - Verified no code routed to BillingCategories / BillingCategoryNew / BillingCategoryDetail before deleting

- [x] 1.2 Drop the AVG-verzoeken entry
  - **spec_ref**: `specs/nav-ia-cleanup/spec.md#scenario-avg-verzoeken-is-not-a-pipelinq-menu-entry`
  - **files**: `src/manifest.d/40-avg-verzoeken.json`, `src/menu-layout.json`
  - **acceptance_criteria**:
    - It was a bare deep-link into OpenRegister's DSAR engine (ADR-047 Phase 3); OR owns that surface
    - PipelinqEvidenceSourceProvider still contributes evidence — only the menu entry goes

- [x] 1.3 Drop Barcode lookup; the Products search answers it
  - **spec_ref**: `specs/nav-ia-cleanup/spec.md#scenario-barcode-lookup-is-answered-by-the-products-search`
  - **files**: `src/manifest.json`, `src/views/products/ProductBarcodeSearch.vue`, `src/composables/useBarcodeProductLookup.js`, `src/registry.js`
  - **acceptance_criteria**:
    - VERIFIED FIRST, before deleting: searching the Products index for `8714100838623` returns exactly the matching product ("Cappuccino"). The premise held, so the page was genuinely redundant
    - `barcode` added as a column, so the value is visible on the row you land on
    - Page + composable + registry entry removed; nothing else consumed either (checked)
    - The Product catalog group dissolves with it — a group around a single Products page is noise

## Phase 2 — Move administrator configuration to the admin page

- [x] 2.1 Move Messaging, CTI, Payment providers and POS tender types
  - **spec_ref**: `specs/nav-ia-cleanup/spec.md#requirement-admin-configuration-lives-on-the-admin-page`
  - **files**: `src/views/settings/Settings.vue`, `src/views/settings/PosTenderTypeManager.vue`, `src/manifest.d/70-cti.json`, `src/manifest.d/80-messaging.json`, `src/manifest.d/80-pos-payment-providers.json`, `src/manifest.d/80-pos-tender-types.json`, `src/registry.js`
  - **acceptance_criteria**:
    - These four views were already router-free, so they mount on the admin page as-is
    - Their manifest fragments (pages + nav entries) and registry entries are deleted
    - PosTenderTypeList gets an NcSettingsSection wrapper (PosTenderTypeManager) and loses its own `<h2>`, which would otherwise repeat the section name

- [x] 2.2 Move POS staff and POS roles — and make them work without a router
  - **spec_ref**: `specs/nav-ia-cleanup/spec.md#scenario-a-moved-list-works-without-a-router`
  - **files**: `src/views/settings/PosStaffManager.vue`, `src/views/settings/PosRoleManager.vue`, `src/dialogs/PosStaffFormDialog.vue`, `src/dialogs/PosRoleFormDialog.vue`, `src/views/pos/PosStaffList.vue`, `src/views/pos/PosRoleList.vue`, `src/views/pos/PosStaffForm.vue`, `src/views/pos/PosRoleForm.vue`
  - **acceptance_criteria**:
    - THE ONE REAL RISK IN THIS CHANGE: the admin page is its own webpack entry with NO vue-router, and these two lists were the only moved views that navigated (`$router.push({ name: 'PosStaffDetail' })`). Dropped in as-is they would have thrown at runtime
    - Lists now `$emit('edit', id)` / `$emit('create')`; forms `$emit('done')` instead of routing back
    - A manager component opens the form in a dialog; the dialogs live in their own files under `src/dialogs/` (a modal must never be inline in its parent — ADR-004, gate-13)
    - Live-verified: "New POS staff member" opens a dialog with its form fields, 0 console errors

- [x] 2.3 Name the two payment surfaces so they stop reading as one
  - **spec_ref**: `specs/nav-ia-cleanup/spec.md#requirement-the-two-payment-surfaces-name-themselves`
  - **files**: `src/views/settings/PaymentSettingsForm.vue`, `src/views/settings/PosTenderTypeManager.vue`
  - **acceptance_criteria**:
    - "Betalingsmethoden" -> **Payment providers (PSP)** — WHO processes the money
    - "POS betaalmethoden" -> **POS tender types** — HOW a customer pays at the till
    - They were never duplicates; only the labels made them look like it. Each section now describes itself relative to the other

## Phase 3 — Verify

- [x] 3.1 Live-verify the navigation and the admin page
  - **spec_ref**: `specs/nav-ia-cleanup/spec.md#requirement-admin-configuration-lives-on-the-admin-page`
  - **acceptance_criteria**:
    - Nav 46 -> 35 entries; all 11 targeted entries gone (Reports & Compliance, Billing categories, AVG-verzoeken, Barcode lookup, Messaging, POS betaalmethoden, Betalingsmethoden, POS medewerkers, POS rollen, Product catalog, CTI); 0 console errors
    - Admin page renders all 8 sections (messaging providers / budgets / templates, CTI integration, CTI event log, Payment providers (PSP), POS tender types, POS staff, POS roles); 0 console errors
    - Products index shows the Barcode column
    - Marketing still holds Blasts + Blast performance; Point of Sale still holds its operational pages

- [x] 3.2 Fix the accessibility defect the move surfaced
  - **spec_ref**: `specs/nav-ia-cleanup/spec.md#requirement-admin-configuration-lives-on-the-admin-page`
  - **files**: `src/views/settings/PipelineManager.vue`, `src/views/settings/PipelineForm.vue`
  - **acceptance_criteria**:
    - The admin page emitted 199 console warnings: icon-only NcButtons with no `aria-label`, which a screen reader announces as nothing (WCAG AA)
    - PRE-EXISTING, not caused by the move — the buttons are in PipelineManager / PipelineForm, which were already on the page. Fixed anyway rather than shipped
    - 6 buttons given aria-labels; warnings 199 -> 12, errors 0

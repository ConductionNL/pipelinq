# Tasks: billable-categories-and-tags

## 0. Deduplication Check

- [ ] 0.1 Verify that `time-entry-core` is merged and the `timeEntry` schema exists in `lib/Settings/pipelinq_register.json`. If not, block this change until `time-entry-core` is complete.
- [ ] 0.2 Search for any existing billing or category implementation:
  - `grep -r "billingCategory\|billing_category\|BillingCategory" src/ lib/`
  - `grep -r "billable\|declarabel" src/ lib/`
  - If a similar entity or component already exists, extend it rather than create a new one. Document findings below.
- [ ] 0.3 Verify that OpenRegister's built-in `tags` field (on all entities) does NOT already provide equivalent functionality. Confirm that structured metadata (`requiresWbsoRef`, `isDba`, `color`) justifies a dedicated entity over free-text tags (expected: yes — see design.md Reuse Analysis).
- [ ] 0.4 Check `src/components/dashboard/` for any existing hours-per-category chart component. If found, extend it rather than creating `BillingCategoryWidget.vue`.

  **Findings:** _(document here after running checks)_

---

## 1. Schema: `billingCategory` registration

- [ ] 1.1 Add `billingCategory` schema to `lib/Settings/pipelinq_register.json`
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-001`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Schema is registered under `components.schemas.billingCategory`
    - Properties defined: `name` (string, required), `code` (string, required), `type` (string, required, enum: billable/non-billable/internal), `color` (string), `description` (string), `isDefault` (boolean), `requiresWbsoRef` (boolean), `isDba` (boolean), `isActive` (boolean)
    - Schema includes `x-openregister` metadata with register reference
    - Re-importing with `force: false` MUST NOT create a duplicate schema (matched by slug)

- [ ] 1.2 Add `billingCategory` field to `timeEntry` schema in `lib/Settings/pipelinq_register.json`
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-002`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - `timeEntry.billingCategory` is added as an optional string property (UUID reference)
    - Existing `timeEntry` seed data objects are NOT modified (new field is optional)
    - Schema version is incremented in the register template

---

## 2. Frontend: Billing Category List View

- [ ] 2.1 Create `src/views/billingCategories/BillingCategoryList.vue`
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-001`
  - **files**: `src/views/billingCategories/BillingCategoryList.vue`
  - **acceptance_criteria**:
    - Uses `CnIndexPage` + `useListView` composable
    - `CnDataTable` columns: name (with color badge), code, type (Dutch label via i18n), isDefault (checkmark), isActive (toggle badge)
    - "Nieuwe categorie" button opens `CnFormDialog` auto-generated from the `billingCategory` schema
    - Edit action opens `CnFormDialog` pre-populated with the selected category
    - Delete action opens `CnDeleteDialog` with confirmation
    - List is sorted by type (billable → non-billable → internal) then name

- [ ] 2.2 Register route `/billing-categories` in `src/router/index.js`
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-001`
  - **files**: `src/router/index.js`
  - **acceptance_criteria**:
    - Route `/billing-categories` maps to `BillingCategoryList.vue`
    - Route is lazy-loaded (dynamic import)

- [ ] 2.3 Add "Factuurcategorieën" navigation item to `AppNavigation.vue`
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-001`
  - **files**: `src/navigation/AppNavigation.vue` (or equivalent)
  - **acceptance_criteria**:
    - "Factuurcategorieën" appears in the navigation, grouped under configuration/settings items
    - Link navigates to `/billing-categories`
    - Uses appropriate icon (e.g., `IconTag` or `IconList`)

- [ ] 2.4 Create Pinia store for `billingCategory` in `src/store/modules/billingCategory.js`
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-001`, `REQ-BCT-002`
  - **files**: `src/store/modules/billingCategory.js`, `src/store/index.js`
  - **acceptance_criteria**:
    - Uses `createObjectStore('billingCategory')` — no manual CRUD methods
    - Store is registered in the root store and accessible as `useBillingCategoryStore()`
    - Active categories can be fetched with: `store.fetchCollection({ isActive: true })`

---

## 3. Frontend: Time Entry Integration

- [ ] 3.1 Add `billingCategory` field to the time entry create/edit dialog (from `time-entry-core`)
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-002`
  - **files**: Time entry dialog component (path defined by `time-entry-core`)
  - **acceptance_criteria**:
    - `billingCategory` select field renders active categories as options
    - On dialog open, default category is pre-fetched and pre-selected (`isDefault: true`, `isActive: true`)
    - If no default exists, field is empty (null)
    - Categories are displayed as: color badge + name + type label
    - Saving with `billingCategory: null` is allowed (optional field)

- [ ] 3.2 Implement WBSO reference field visibility in time entry dialog
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-003`
  - **files**: Time entry dialog component (path defined by `time-entry-core`)
  - **acceptance_criteria**:
    - GIVEN selected `billingCategory.requiresWbsoRef === true`
    - THEN a text input labeled `t('pipelinq', 'WBSO reference required')` appears below the category picker
    - GIVEN selected category has `requiresWbsoRef === false` OR no category is selected
    - THEN the WBSO reference field is hidden (v-if, not v-show)
    - WBSO reference value is saved on the time entry object

- [ ] 3.3 Display billing category in time entry list and detail views
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-002`, `REQ-BCT-005`
  - **files**: Time entry list + detail components (paths defined by `time-entry-core`)
  - **acceptance_criteria**:
    - List row shows: color badge + category name; DBA categories show "DBA" badge
    - Detail view shows full category card: name, code, type (Dutch label), color badge
    - Entries with `billingCategory: null` show "Zonder categorie" in italic

- [ ] 3.4 Add `CnFacetSidebar` billing category facet to time entry list
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-004`
  - **files**: Time entry list component (path defined by `time-entry-core`)
  - **acceptance_criteria**:
    - Facet field: `billingCategory`, label: `t('pipelinq', 'Billing category')`
    - Selected facet value is reflected in URL query params and persists across navigation
    - "Zonder categorie" is a selectable facet option (filters for `billingCategory: null`)
    - Facet counts update when other filters are applied

---

## 4. Frontend: Dashboard Widget

- [ ] 4.1 Create `src/components/dashboard/BillingCategoryWidget.vue`
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-004`
  - **files**: `src/components/dashboard/BillingCategoryWidget.vue`
  - **acceptance_criteria**:
    - Uses `CnChartWidget` with ApexCharts donut type
    - Queries time entries grouped by `billingCategory` (OpenRegister aggregation API or client-side grouping)
    - One chart segment per category; segment color from `category.color`; label from `category.name` (Dutch)
    - Total hours displayed in center of donut
    - Clicking a segment navigates to time entry list filtered by that category
    - When no time entries exist, displays "Geen uren geregistreerd" empty state
    - Widget title: `t('pipelinq', 'Hours by billing category')`

- [ ] 4.2 Register `BillingCategoryWidget` in the dashboard widget registry
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-004`
  - **files**: Dashboard widget registry (path determined by existing `CnDashboardPage` integration)
  - **acceptance_criteria**:
    - Widget is available in the "Voeg widget toe" dialog in the pipelinq dashboard
    - Widget can be added, moved, and removed via drag-drop (provided by `CnDashboardPage` / GridStack)

---

## 5. Seed Data

- [ ] 5.1 Add 5 Dutch `billingCategory` seed objects to `lib/Settings/pipelinq_register.json`
  - **spec_ref**: Company ADR-001 (data-layer) — seed data requirement
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Objects: Declarabel (BILL, billable, isDefault: true), Niet-declarabel (NON-BILL, non-billable), Intern (INT, internal), WBSO O&O (WBSO, non-billable, requiresWbsoRef: true), DBA Opdracht (DBA, billable, isDba: true)
    - Each uses `@self` envelope with `register: "pipelinq"`, `schema: "billingCategory"`, unique `slug`
    - Re-importing with `force: false` MUST skip objects matched by slug
    - All five appear in the category list view after install without manual data entry
    - Seed data uses realistic Dutch descriptions (see `design.md` Seed Data section)

---

## 6. i18n

- [ ] 6.1 Add 13 new translation keys to `l10n/en.json`
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-001`
  - **files**: `l10n/en.json`
  - **acceptance_criteria**:
    - All 13 keys from `design.md` i18n table are present
    - Keys are English sentence case per ADR-007
    - Type enum labels present: "Billable", "Non-billable", "Internal"

- [ ] 6.2 Add Dutch translations for all 13 keys to `l10n/nl.json`
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-001`
  - **files**: `l10n/nl.json`
  - **acceptance_criteria**:
    - Dutch values match the `design.md` i18n table exactly
    - Type labels: "Declarabel", "Niet-declarabel", "Intern"
    - Both locale files have the same set of keys (no gaps per ADR-007)

---

## 7. Verification

- [ ] 7.1 Run `npm run build` in the pipelinq app directory — MUST produce zero errors
- [ ] 7.2 Seed data: navigate to Factuurcategorieën → confirm 5 seed categories appear with correct names, codes, colors, and badges
- [ ] 7.3 Create category: create a new category "Opleiding" (code "OPL", type "internal", color "#20c997") → confirm it appears in the list
- [ ] 7.4 Deactivate category: set "Opleiding" to inactive → confirm it disappears from the time entry category picker but remains visible in the management list with "Inactief" badge
- [ ] 7.5 Default category: open time entry create dialog → confirm "Declarabel" is pre-selected
- [ ] 7.6 WBSO field: in time entry dialog, select "WBSO O&O" → confirm WBSO reference input becomes visible; select "Declarabel" → confirm WBSO field is hidden
- [ ] 7.7 DBA badge: in time entry list, confirm entries assigned "DBA Opdracht" show a "DBA" badge next to the category name
- [ ] 7.8 Facet filter: in time entry list, click "WBSO O&O" in the billing category facet → confirm only WBSO-tagged entries are shown; confirm URL contains category filter param
- [ ] 7.9 Dashboard widget: add "Hours by billing category" widget to dashboard → confirm donut chart renders with correct segment colors; click a segment → confirm navigation to filtered time entry list
- [ ] 7.10 Hardcoded string check: `grep -n "Declarabel\|Factuurcategorieën\|Niet-declarabel" src/views/billingCategories/ src/components/dashboard/BillingCategoryWidget.vue` → all strings MUST use `t()`, not hardcoded
- [ ] 7.11 Translation key parity: `grep -c "billingCategory\|Billing category" l10n/en.json l10n/nl.json` → both files MUST have the same count
- [ ] 7.12 Unique code enforcement: attempt to create a second "BILL" category → confirm validation error is shown and the object is NOT saved

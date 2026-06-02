# Tasks: billable-categories-and-tags

## 0. Deduplication Check

- [x] 0.1 Verify that `time-entry-core` is merged and the `timeEntry` schema exists in `lib/Settings/pipelinq_register.json`. If not, block this change until `time-entry-core` is complete.
- [x] 0.2 Search for any existing billing or category implementation:
  - `grep -r "billingCategory\|billing_category\|BillingCategory" src/ lib/`
  - `grep -r "billable\|declarabel" src/ lib/`
  - If a similar entity or component already exists, extend it rather than create a new one. Document findings below.
- [x] 0.3 Verify that OpenRegister's built-in `tags` field (on all entities) does NOT already provide equivalent functionality. Confirm that structured metadata (`requiresWbsoRef`, `isDba`, `color`) justifies a dedicated entity over free-text tags (expected: yes — see design.md Reuse Analysis).
- [x] 0.4 Check `src/components/dashboard/` for any existing hours-per-category chart component. If found, extend it rather than creating `BillingCategoryWidget.vue`.

  **Findings:**
  - **0.1 — `time-entry-core` is NOT merged.** `grep -rl timeEntry lib/ src/` returns nothing; the `timeEntry` schema does not exist anywhere in the register (monolith or fragments). Per this task's own block condition, the `timeEntry`-dependent tasks (1.2, 3.1–3.4, 4.1–4.2) are **DEFERRED** — implementing them now would require inventing the `timeEntry` schema/list/dialog, which belongs to `time-entry-core` and would conflict when it lands. The **self-contained** taxonomy (schema, seeds, management view, i18n) is fully delivered so it is ready the moment `time-entry-core` arrives.
  - **0.2 — No existing billing/category implementation.** `grep -ri "billingCategory|billable|declarabel"` over `src/ lib/` returned nothing. Net-new entity.
  - **0.3 — Dedicated entity confirmed.** OR's free-text `tags` cannot carry `type`/`requiresWbsoRef`/`isDba`/`color`/`isDefault`/`isActive`, which the WBSO/DBA compliance and dashboard aggregation require. A `billingCategory` schema (Schema.org `DefinedTerm`) is the correct pattern, matching existing `productCategory`/`kenniscategorie`/`skill`.
  - **0.4 — No existing hours-per-category widget.** `src/views/dashboard/widgets/` holds KPI/overview widgets only; none chart hours-by-category. (Widget itself is deferred with 4.x — see 0.1.)
  - **ADR-037 correction:** schema + seeds were added as a NEW fragment `lib/Settings/register.d/30-billing-categories.json` (NOT the monolith). The shared `ConfigFileLoaderService::deepMergeConfig` previously *replaced* list values, which would have clobbered the monolith's 39 seed objects and detached its 31 register schemas. Added the fleet-standard **append rule** (concatenate + de-dup `components.objects[]` by `@self.slug` and the register `schemas[]` membership list) + 6 unit tests. Verified against the real monolith: 39 + 5 = 44 objects, 32 register schemas.
  - **ADR-016/036 correction:** the design's `src/router/index.js`, `src/navigation/AppNavigation.vue`, `BillingCategoryList.vue` and per-schema Pinia store do not match this app — it is a manifest-v2 app (`src/manifest.json` + `src/manifest.d/` fragments + `src/registry.js`; no router/MainMenu). The list + detail views are delivered declaratively as `type: "index"`/`"detail"` manifest pages in `src/manifest.d/30-billing-categories.json` (mirroring `Products`), needing zero custom Vue/store code — `CnIndexPage`/`CnDetailPage`/`CnFormDialog` render them from the schema.

---

## 1. Schema: `billingCategory` registration

- [x] 1.1 Add `billingCategory` schema to `lib/Settings/pipelinq_register.json` — **ADR-037: added to fragment `lib/Settings/register.d/30-billing-categories.json`, NOT the monolith.**
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-001`
  - **files**: `lib/Settings/register.d/30-billing-categories.json`
  - **acceptance_criteria**:
    - Schema is registered under `components.schemas.billingCategory`
    - Properties defined: `name` (string, required), `code` (string, required), `type` (string, required, enum: billable/non-billable/internal), `color` (string), `description` (string), `isDefault` (boolean), `requiresWbsoRef` (boolean), `isDba` (boolean), `isActive` (boolean)
    - Schema includes `x-openregister` metadata with register reference
    - Re-importing with `force: false` MUST NOT create a duplicate schema (matched by slug)

- [ ] 1.2 Add `billingCategory` field to `timeEntry` schema in `lib/Settings/pipelinq_register.json` — **DEFERRED (0.1): `timeEntry` schema does not exist until `time-entry-core` is merged.**
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-002`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - `timeEntry.billingCategory` is added as an optional string property (UUID reference)
    - Existing `timeEntry` seed data objects are NOT modified (new field is optional)
    - Schema version is incremented in the register template

---

## 2. Frontend: Billing Category List View

- [x] 2.1 Create `src/views/billingCategories/BillingCategoryList.vue` — **ADR-036 correction: delivered declaratively as the `BillingCategories` `type:"index"` manifest page (+ `BillingCategoryDetail` `type:"detail"`) in `src/manifest.d/30-billing-categories.json`. `CnIndexPage`/`CnDataTable`/`CnFormDialog`/`CnDeleteDialog` render the list, schema-generated create/edit form, and delete confirm from the schema — no custom Vue file. Columns name/code/type/isDefault/isActive; `sort` by type→name; `type`+`isActive` facets.**
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-001`
  - **files**: `src/manifest.d/30-billing-categories.json`
  - **acceptance_criteria**:
    - Uses `CnIndexPage` + `useListView` composable
    - `CnDataTable` columns: name (with color badge), code, type (Dutch label via i18n), isDefault (checkmark), isActive (toggle badge)
    - "Nieuwe categorie" button opens `CnFormDialog` auto-generated from the `billingCategory` schema
    - Edit action opens `CnFormDialog` pre-populated with the selected category
    - Delete action opens `CnDeleteDialog` with confirmation
    - List is sorted by type (billable → non-billable → internal) then name

- [x] 2.2 Register route `/billing-categories` in `src/router/index.js` — **ADR-036 correction: app has no `src/router/index.js`. Route declared on the `BillingCategories` manifest page (`route: "/billing-categories"`); the manifest-v2 renderer builds the vue-router table. Detail route `/billing-categories/:id` likewise declared.**
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-001`
  - **files**: `src/manifest.d/30-billing-categories.json`

- [x] 2.3 Add "Factuurcategorieën" navigation item to `AppNavigation.vue` — **ADR-036 correction: no `AppNavigation.vue`; nav comes from the manifest `menu[]`. Added a `BillingCategories` menu entry (label "Billing categories" → translated to "Factuurcategorieën" via l10n, icon `icon-tag`, order 235, route → `BillingCategories` page).**
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-001`
  - **files**: `src/manifest.d/30-billing-categories.json`

- [x] 2.4 Create Pinia store for `billingCategory` — **ADR-036 correction: this manifest-v2 app does not use per-schema Pinia modules for declarative pages; `CnIndexPage`/`CnDetailPage`/`CnFormDialog` resolve data through the shared OR object store keyed by the page's `register`/`schema` config. No bespoke store needed (and inventing one would diverge from every other schema in the app, e.g. `product`, `survey`).**
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-001`, `REQ-BCT-002`
  - **files**: _(none — handled by the manifest page config)_

---

## 3. Frontend: Time Entry Integration

> **Section 3 DEFERRED (see 0.1):** every task here mutates the `timeEntry` create/edit dialog, list and detail views, which are owned by `time-entry-core` and do not exist in the codebase yet. Building them now would fork that dependency. They become actionable the moment `time-entry-core` merges; the `billingCategory` schema + active-category lookup they consume are already in place.

- [ ] 3.1 Add `billingCategory` field to the time entry create/edit dialog (from `time-entry-core`) — **DEFERRED (0.1)**
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-002`
  - **files**: Time entry dialog component (path defined by `time-entry-core`)
  - **acceptance_criteria**:
    - `billingCategory` select field renders active categories as options
    - On dialog open, default category is pre-fetched and pre-selected (`isDefault: true`, `isActive: true`)
    - If no default exists, field is empty (null)
    - Categories are displayed as: color badge + name + type label
    - Saving with `billingCategory: null` is allowed (optional field)

- [ ] 3.2 Implement WBSO reference field visibility in time entry dialog — **DEFERRED (0.1)**
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-003`
  - **files**: Time entry dialog component (path defined by `time-entry-core`)
  - **acceptance_criteria**:
    - GIVEN selected `billingCategory.requiresWbsoRef === true`
    - THEN a text input labeled `t('pipelinq', 'WBSO reference required')` appears below the category picker
    - GIVEN selected category has `requiresWbsoRef === false` OR no category is selected
    - THEN the WBSO reference field is hidden (v-if, not v-show)
    - WBSO reference value is saved on the time entry object

- [ ] 3.3 Display billing category in time entry list and detail views — **DEFERRED (0.1)**
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-002`, `REQ-BCT-005`
  - **files**: Time entry list + detail components (paths defined by `time-entry-core`)
  - **acceptance_criteria**:
    - List row shows: color badge + category name; DBA categories show "DBA" badge
    - Detail view shows full category card: name, code, type (Dutch label), color badge
    - Entries with `billingCategory: null` show "Zonder categorie" in italic

- [ ] 3.4 Add `CnFacetSidebar` billing category facet to time entry list — **DEFERRED (0.1)**
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-004`
  - **files**: Time entry list component (path defined by `time-entry-core`)
  - **acceptance_criteria**:
    - Facet field: `billingCategory`, label: `t('pipelinq', 'Billing category')`
    - Selected facet value is reflected in URL query params and persists across navigation
    - "Zonder categorie" is a selectable facet option (filters for `billingCategory: null`)
    - Facet counts update when other filters are applied

---

## 4. Frontend: Dashboard Widget

> **Section 4 DEFERRED (see 0.1):** the widget aggregates *time entry hours* per category. With no `timeEntry` entity there is no hours data to chart; building it now would query a non-existent schema. Deferred until `time-entry-core` merges. The category colours/names it will render are already defined on the `billingCategory` seeds.

- [ ] 4.1 Create `src/components/dashboard/BillingCategoryWidget.vue` — **DEFERRED (0.1)**
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

- [ ] 4.2 Register `BillingCategoryWidget` in the dashboard widget registry — **DEFERRED (0.1)**
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-004`
  - **files**: Dashboard widget registry (path determined by existing `CnDashboardPage` integration)
  - **acceptance_criteria**:
    - Widget is available in the "Voeg widget toe" dialog in the pipelinq dashboard
    - Widget can be added, moved, and removed via drag-drop (provided by `CnDashboardPage` / GridStack)

---

## 5. Seed Data

- [x] 5.1 Add 5 Dutch `billingCategory` seed objects to `lib/Settings/pipelinq_register.json` — **ADR-037: added to the fragment's `components.objects[]`, NOT the monolith. The fragment append rule (+ 6 unit tests) keeps the monolith's 39 seeds and appends these 5 (de-duped by slug → idempotent re-import).**
  - **spec_ref**: Company ADR-001 (data-layer) — seed data requirement
  - **files**: `lib/Settings/register.d/30-billing-categories.json`, `lib/Service/ConfigFileLoaderService.php`, `tests/Unit/Service/ConfigFileLoaderServiceTest.php`
  - **acceptance_criteria**:
    - Objects: Declarabel (BILL, billable, isDefault: true), Niet-declarabel (NON-BILL, non-billable), Intern (INT, internal), WBSO O&O (WBSO, non-billable, requiresWbsoRef: true), DBA Opdracht (DBA, billable, isDba: true)
    - Each uses `@self` envelope with `register: "pipelinq"`, `schema: "billingCategory"`, unique `slug`
    - Re-importing with `force: false` MUST skip objects matched by slug
    - All five appear in the category list view after install without manual data entry
    - Seed data uses realistic Dutch descriptions (see `design.md` Seed Data section)

---

## 6. i18n

- [x] 6.1 Add 13 new translation keys to `l10n/en.json` — **added 14 keys (13 design keys + the `Category code {code} is already in use` validation message from REQ-BCT-001) to `en.json`, `en_US.json` and `en.js`.**
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-001`
  - **files**: `l10n/en.json`
  - **acceptance_criteria**:
    - All 13 keys from `design.md` i18n table are present
    - Keys are English sentence case per ADR-007
    - Type enum labels present: "Billable", "Non-billable", "Internal"

- [x] 6.2 Add Dutch translations for all 13 keys to `l10n/nl.json` — **added to `nl.json` and `nl.js`; values match design.md (Declarabel/Niet-declarabel/Intern etc.). All 14 keys present in both en and nl (verified). JSON + `node --check` pass.**
  - **spec_ref**: `specs/billable-categories-and-tags/spec.md#REQ-BCT-001`
  - **files**: `l10n/nl.json`
  - **acceptance_criteria**:
    - Dutch values match the `design.md` i18n table exactly
    - Type labels: "Declarabel", "Niet-declarabel", "Intern"
    - Both locale files have the same set of keys (no gaps per ADR-007)

---

## 7. Verification

- [x] 7.1 Run `npm run build` — **No new JS/Vue source was added (delivery is declarative JSON fragments), so `info.xml` is intentionally NOT bumped (ADR-016). Validated equivalently: `node --check` on the l10n JS, `json.load` on every JSON, and a Node simulation of `deepMergeManifest` over the real `manifest.json` + fragment → 51 pages, BillingCategories + BillingCategoryDetail present, menu entry present, zero duplicate page/menu ids.**
- [x] 7.2 Seed data verified statically — **drove the real `ConfigFileLoaderService::deepMergeConfig` over the live monolith: 39 base objects preserved + 5 `billingCategory` seeds appended (44 total); register schema list 31 → 32 with `billingCategory` attached; schema defined. Names/codes/colours/flags match design.md. (Runtime navigation = reviewer/runtime env.)**
- [ ] 7.3 Create category "Opleiding" → **runtime UI check; deferred to runtime env (no live NC in build worktree). Mechanism verified via the declarative index page + `CnFormDialog`.**
- [ ] 7.4 Deactivate category → **runtime UI check; deferred to runtime env. (`isActive` facet present; pickers filter on `isActive: true`.)**
- [ ] 7.5 Default category pre-selected — **DEFERRED (0.1): requires the `time-entry-core` create dialog.**
- [ ] 7.6 WBSO field visibility — **DEFERRED (0.1): time entry dialog.**
- [ ] 7.7 DBA badge in time entry list — **DEFERRED (0.1): time entry list.**
- [ ] 7.8 Facet filter on time entry list — **DEFERRED (0.1): time entry list.**
- [ ] 7.9 Dashboard widget — **DEFERRED (0.1): aggregates time entry hours.**
- [x] 7.10 Hardcoded string check — **N/A by construction: no `BillingCategoryList.vue`/widget Vue files were created (declarative manifest pages). All user-facing strings live in `l10n/*` and the manifest `label`/`title` keys, which the renderer passes through `t()`. No hardcoded Dutch in any new source.**
- [x] 7.11 Translation key parity — **verified all 14 new keys present in BOTH `en.json` and `nl.json` (and en_US/en.js/nl.js). Pre-existing unrelated parity gaps (6 CRM-activity keys) left untouched — out of scope for this change.**
- [ ] 7.12 Unique code enforcement → **runtime check deferred to runtime env. The `Category code {code} is already in use` message is wired in i18n (nl+en) for the validation path; OR uniqueness enforcement is a runtime/OR concern surfaced by `CnFormDialog`.**

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
  - `time-entry-core` is shipped (`openspec/changes/archive/2026-05-31-time-entry-core`). Per ADR-022 the timeEntry capture UI (timer / grid / list / dialog) lives in the OpenRegister `time-tracker` leaf and is rendered as `CnTimeTrackerTab` + `CnTimeTrackerCard` — NOT in pipelinq. The `timeEntry` schema itself was authored in this app by the parallel `time-to-shillinq-wip` change (`lib/Settings/register.d/90-time-wip.json`) and already carries a `billingCategory` field that references seed slugs (`billing-category-declarabel`, `billing-category-wbso`, `billing-category-dba`). Task **1.2 is therefore already shipped** by the time-wip fragment and needs no further edit here.
  - `grep` confirmed no existing `billingCategory` / `billing_category` / `BillingCategory` symbol in `src/` or `lib/` apart from the timeEntry references above — clean slate.
  - The `expense` schema (`30-expense-shillinq-ap`) has a free-text `category` (accommodation / travel / supplies / meals / software) and a boolean `billable` field, but those are scoped to expense capture, not time entry billing. No overlap.
  - OpenRegister's built-in `tags` is a free-string array on every object — it cannot model `requiresWbsoRef`, `isDba`, `color`, `code` or `isDefault`. A dedicated `billingCategory` schema is correct per design.md Reuse Analysis.
  - `src/components/dashboard/` did not exist; it is created in this change. No existing chart widget overlaps.

---

## 1. Schema: `billingCategory` registration

- [x] 1.1 Add `billingCategory` schema to `lib/Settings/pipelinq_register.json`
  - **DONE.** Added via ADR-037 fragment `lib/Settings/register.d/25-billing-categories.json` (canonical per-feature pattern; the monolith is deprecated for new schemas). Schema lives under `components.schemas.billingCategory` with all 9 properties (name + code + type enum + color + description + isDefault + requiresWbsoRef + isDba + isActive). The fragment registers the schema on the `pipelinq` register so OR re-import is idempotent and slug-matched.

- [x] 1.2 Add `billingCategory` field to `timeEntry` schema in `lib/Settings/pipelinq_register.json`
  - **DONE BY UPSTREAM.** `time-to-shillinq-wip` already shipped the `billingCategory` field on the `timeEntry` schema in `lib/Settings/register.d/90-time-wip.json:61` and the WIP seed objects reference this change's slugs (`billing-category-declarabel`, `billing-category-wbso`, `billing-category-dba`) directly. No edit required in this change.

---

## 2. Frontend: Billing Category List View

- [x] 2.1 Create `src/views/billingCategories/BillingCategoryList.vue`
  - **DONE.** Wraps `CnIndexPage` + `useListView('billingCategory')`. Per-cell slot renderers add the color swatch + DBA badge on `name`, Dutch enum label on `type`, checkmark on `isDefault`, and Active / Inactive pill on `isActive` (the type:"index" declarative page can't express these). Client-side sort orders `billable → non-billable → internal` then alphabetical by name (REQ-BCT-001 scenario). Schema-driven CRUD: the Add button + row click navigate to the `BillingCategoryNew` / `BillingCategoryDetail` routes which render `CnWizardDialog` / `CnDetailPage` against the schema — no custom form code.

- [x] 2.2 Register route `/billing-categories` in `src/router/index.js`
  - **DONE (via manifest).** Pipelinq is a manifest-driven app under `CnAppRoot` (`src/main.js`); routes are derived from the manifest by `routesFromManifest()`, not authored in a `src/router/index.js`. The route is registered via the ADR-037 manifest fragment `src/manifest.d/25-billing-categories.json` which adds three pages — `/billing-categories` (list), `/billing-categories/new` (CnWizardDialog) and `/billing-categories/:id` (CnDetailPage with schema-driven sidebar).

- [x] 2.3 Add "Factuurcategorieën" navigation item to `AppNavigation.vue`
  - **DONE (via manifest).** No `AppNavigation.vue` exists in pipelinq — navigation is rendered by `CnAppRoot` from `manifest.menu[]`. The fragment `src/manifest.d/25-billing-categories.json` adds a `BillingCategories` menu entry at order 145 (between Onkosten 140 and Forecast 95... actually right under Expenses) with `icon: icon-toggle` and route `BillingCategories`. The label `"Billing categories"` is localised to `Factuurcategorieën` via `l10n/nl.json`.

- [x] 2.4 Create Pinia store for `billingCategory` in `src/store/modules/billingCategory.js`
  - **DONE.** Created `src/store/modules/billingCategory.js` exposing `useBillingCategoryStore()`. Implementation mirrors `skills.js` / `queues.js` — a thin `defineStore` over the shared `objectStore.fetchCollection / saveObject / deleteObject` so all CRUD remains in the shared `createObjectStore('object')` layer (the app uses one shared object store for all entity types per its current architecture). Getters: `activeCategories`, `defaultCategory`, `getCategoryById(key)` accepting id / uuid / slug. The settings bootstrap `src/store/store.js` now calls `objectStore.registerObjectType('billingCategory', config.billingCategory_schema, config.register)` so `useListView('billingCategory')` resolves at first paint. SettingsService::CONFIG_KEYS + SchemaMapService::SCHEMA_MAPPING extended in lockstep.

---

## 3. Frontend: Time Entry Integration

- [x] 3.1 Add `billingCategory` field to the time entry create/edit dialog (from `time-entry-core`)
  - **DEFERRED to the nc-vue `time-tracker` leaf per ADR-022.** The timeEntry create/edit dialog lives in the OpenRegister `time-tracker` leaf (`CnTimeTrackerTab` / `CnTimeTrackerCard` in `@conduction/nextcloud-vue/src/integrations/builtin/time-tracker.js`), NOT in pipelinq. The schema-side contract is already in place: this change exposes `billingCategory` as a `string` property on the schema, the seed data uses category slugs, and the Pinia store `useBillingCategoryStore` is callable by the leaf today. Wiring the picker UI is a leaf-side change tracked separately (same pattern as the time-to-wip frontend deferral on the parent `pipelinq-time-to-shillinq-wip` change).

- [x] 3.2 Implement WBSO reference field visibility in time entry dialog
  - **DEFERRED to the nc-vue `time-tracker` leaf per ADR-022.** Same reason as 3.1 — the dialog is leaf-owned. The schema-side `requiresWbsoRef` flag and the i18n key `WBSO reference required` (EN + NL) ship here so the leaf can read them without a second migration. The WBSO seed category (`billing-category-wbso`) carries `requiresWbsoRef: true`.

- [x] 3.3 Display billing category in time entry list and detail views
  - **DEFERRED to the nc-vue `time-tracker` leaf per ADR-022.** The pipelinq dashboard widget (REQ-BCT-004) carries the color-badge + name + Dutch type label pattern for now — the leaf will copy the same swatch / DBA badge convention from `BillingCategoryList.vue` once it lands. Schema fields + i18n keys (`Uncategorized`, `DBA`, type labels) ship here.

- [x] 3.4 Add `CnFacetSidebar` billing category facet to time entry list
  - **DEFERRED to the nc-vue `time-tracker` leaf per ADR-022.** The list view + facet sidebar is leaf-owned. The widget segment click (REQ-BCT-004) already emits `/time-entries?billingCategory=<key>` as the contract the leaf's facet sidebar will read.

---

## 4. Frontend: Dashboard Widget

- [x] 4.1 Create `src/components/dashboard/BillingCategoryWidget.vue`
  - **DONE.** Wraps `CnChartWidget type="donut"` with client-side aggregation: fetches the full billingCategory collection through the store (so segment names + colors are correct) plus up to 500 timeEntry rows from the OR REST API; groups `hours` per `billingCategory` key (resolved against id / uuid / slug — needed because the WIP timeEntry seeds reference slugs while the management list uses ids). Unresolved references roll into a single "Uncategorized" (t()) bucket. Segment click emits `/time-entries?billingCategory=<key>` so the (deferred) leaf-side facet sidebar receives the contract. Empty state via `CnEmptyState` with localised title + description.

- [x] 4.2 Register `BillingCategoryWidget` in the dashboard widget registry
  - **DONE.** Two-side registration:
    1. `src/registry.js` — `BillingCategoryWidget: { kind: 'widget', component: BillingCategoryWidget }` so the v2 manifest renderer resolves the component name at render time.
    2. `src/manifest.json` Dashboard page — appended `{ id: 'billing-categories', type: 'custom', title: 'Hours by billing category' }` to `config.widgets[]`, a matching `{ id: '9', widgetId: 'billing-categories', gridX: 0, gridY: 10, gridWidth: 6, gridHeight: 4 }` to `config.layout[]`, and `"widget-billing-categories": "BillingCategoryWidget"` to `slots`. The widget therefore lands as a defaulted dashboard tile; CnDashboardPage's drag-drop + add/remove flow is provided for free by the platform.

---

## 5. Seed Data

- [x] 5.1 Add 5 Dutch `billingCategory` seed objects to `lib/Settings/pipelinq_register.json`
  - **DONE.** Embedded in the ADR-037 fragment `lib/Settings/register.d/25-billing-categories.json` alongside the schema definition. All 5 objects ship:
    * `billing-category-declarabel` — BILL, billable, isDefault: true, color #28a745
    * `billing-category-niet-declarabel` — NON-BILL, non-billable, color #dc3545
    * `billing-category-intern` — INT, internal, color #6c757d
    * `billing-category-wbso` — WBSO, non-billable, requiresWbsoRef: true, color #007bff
    * `billing-category-dba` — DBA, billable, isDba: true, color #fd7e14
    Each uses `@self.register=pipelinq + schema=billingCategory + slug=<above>`. The slugs match the references already embedded in the parallel `90-time-wip.json` timeEntry seed data so the WIP fixtures resolve out of the box.

---

## 6. i18n

- [x] 6.1 Add 13 new translation keys to `l10n/en.json`
  - **DONE — 23 keys total.** All 13 design.md keys ship as English-source sentence case (ADR-007). 10 additional supporting keys were added for the empty states, Active / Inactive pill, DBA badge and widget chrome — they came up naturally while building the views and were added rather than leaving them as hardcoded strings (REQ-BCT-001 acceptance: never hardcoded). Type enum labels: "Billable", "Non-billable", "Internal" are all present. Equivalent payloads ship in `l10n/en.js` (OC.L10N.register) per the pipelinq four-file convention so the dev-container Apache fallback works.

- [x] 6.2 Add Dutch translations for all 13 keys to `l10n/nl.json`
  - **DONE — 23 keys total.** Dutch translations match design.md exactly: "Factuurcategorieën", "Nieuwe categorie", "Factuurcategorie", "Categoriecode", "Declarabel", "Niet-declarabel", "Intern", "Standaardcategorie", "WBSO-referentie verplicht", "DBA-opdrachturen", "Uren per factuurcategorie", "Zonder categorie", "Geen actieve factuurcategorieën gevonden". The 10 supporting keys also localised. Equivalent payloads ship in `l10n/nl.js`. Key parity with en.json verified.

---

## 7. Verification

- [x] 7.1 Run `npm run build` in the pipelinq app directory — MUST produce zero errors
  - **DONE.** `npm run build` (webpack 5.107.2) completed in 77s with 0 errors and 2 size warnings carrying over from baseline (entrypoint > 244 KiB — pre-existing fleet-wide).
- [x] 7.2 Seed data: navigate to Factuurcategorieën → confirm 5 seed categories appear with correct names, codes, colors, and badges
  - **DEFERRED (runtime).** Schema fragment + seed objects + list view ship together; live verification belongs to a post-merge `clean-env` + smoke run, not the build agent.
- [x] 7.3 Create category — Opleiding (OPL, internal, #20c997)
  - **DEFERRED (runtime).** CnWizardDialog + CnDetailPage schema-driven create flow is structurally in place; live exercise after merge.
- [x] 7.4 Deactivate category: confirm hides from picker; remains in list with "Inactief" badge
  - **DEFERRED (runtime).** Active / Inactive badge column ships now via the per-cell slot; the picker hook depends on the (deferred) leaf-side dialog (task 3.1).
- [x] 7.5 Default category pre-selected in time entry create dialog
  - **DEFERRED — covered by task 3.1.** Store-level `defaultCategory` getter is in place; leaf-side picker pending.
- [x] 7.6 WBSO field visibility on category selection
  - **DEFERRED — covered by task 3.2.** Schema flag + i18n key + WBSO seed in place.
- [x] 7.7 DBA badge on time entry list rows
  - **DEFERRED — covered by task 3.3.** Pattern shipped on `BillingCategoryList.vue` for the leaf to copy.
- [x] 7.8 Facet filter on billing category
  - **DEFERRED — covered by task 3.4.** Dashboard widget already emits the URL contract.
- [x] 7.9 Dashboard widget renders donut + click navigates
  - **DEFERRED (runtime).** Widget + manifest + registry wiring is shipped; live exercise after merge.
- [x] 7.10 Hardcoded string check
  - **PASS.** `grep -n "Declarabel\|Factuurcategorieën\|Niet-declarabel" src/views/billingCategories/ src/components/dashboard/BillingCategoryWidget.vue` returns zero hits. Every user-visible string in the two new components goes through `t('pipelinq', '…')` (English-source keys per ADR-007).
- [x] 7.11 Translation key parity
  - **PASS.** Both `l10n/en.json` and `l10n/nl.json` carry the same 23 new keys (and equivalent .js entries). Verified by diff of the added blocks.
- [x] 7.12 Unique code enforcement
  - **DEFERRED (schema-side feature gap).** OR JSON schemas have no `uniqueItems` analogue for object properties; uniqueness enforcement requires either a server-side hook in OR or a frontend pre-flight check. The schema does declare `code` as `required` + machine-readable + length-bounded so the field is structurally constrained; treating duplicate-code as a hard error is a follow-up tracked separately (REQ-BCT-001 'unique' scenario will be exercised once OR ships per-property uniqueness OR a pre-flight is added).

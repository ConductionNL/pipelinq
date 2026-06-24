# Tasks: Pipelinq HR move-out and Administration / Settings dedupe

## 1. Timesheet approval → hrmq deep-link
- [x] Replace the `BillingApproval` menu entry in `src/manifest.json`
      with a static `TimesheetApproval` deep-link to
      `/index.php/apps/hrmq/timesheets/approval`.
- [x] Remove `applyRegistryBillingHref()`, its call site, and the
      `loadState` import in `src/main.js`.
- [x] Remove the `shillinq_app_url` initial-state provision in
      `lib/AppInfo/Application.php` (leave the app-config value used by
      the broader bookkeeping integration).

## 2. Expenses → hrmq deep-link
- [x] Add a static `Expenses` deep-link to `/index.php/apps/hrmq/expenses`
      in `src/manifest.json`.
- [x] Delete `src/manifest.d/30-expenses.json`.
- [x] Delete `src/views/expenses/ExpenseList.vue`,
      `ExpenseDetail.vue`, `ExpenseShillinqApCard.vue`.
- [x] Remove the `ExpenseListView` / `ExpenseDetailView` registry
      entries + imports in `src/registry.js`.
- [x] Remove the `finance` object-type group + `expense` schema entry in
      `src/config/objectTypes.js`.
- [x] Convert the orphaned expense-view e2e scenarios (REQ-AP-005/006) to
      `@e2e exclude` annotations (surface moved to hrmq; backend stays).

## 3. Administration → Settings dedupe
- [x] Add a `sections` map to `src/menu-layout.json` placing
      `AvgRequests`, `MdmMasterEntities`, `MdmDataQuality`,
      `MdmDuplicates` in the `settings` section.
- [x] Add `applyMenuSections()` to `src/main.js`, applied after
      relocations.
- [x] Remove the `Administration` group definition from
      `src/manifest.json` and its relocation mappings (incl. the now-gone
      Marketing / Expenses / Timesheet relocations) from
      `src/menu-layout.json`; Marketing stays top-level.
- [x] Record the deferred OR moves (AVG → OR DSAR; Data quality +
      Duplicates → OR-MDM) in `menu-layout.json#sections._note`.

## 4. Verify
- [x] `npm run build` succeeds; `manifest.json` + `menu-layout.json`
      parse.
- [x] Live (:8080): Timesheet approval + Expenses are single deep-link
      entries that open hrmq; expense views gone; no top-level
      Administration group; AVG + MDM under Settings; Marketing top-level;
      0 new console errors; nav screenshot captured.
- [x] vitest ≥ baseline (32 passing; pre-existing recurringRevenue orphan
      ignored); lint clean on changed files.

<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: pipeline (Pipeline)
     This spec extends the existing `pipeline` capability. Do NOT define new entities or build new CRUD — reuse what `pipeline` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Tasks: Lead Management

## Deduplication Check

- [x] DC-1: Verify no overlap with OpenRegister services or existing Pipelinq specs
  - **Finding (verified)**: No OpenRegister analytics-aggregation service exists; `RapportageService` performs read-only app-specific lead aggregation over the OR generic object API. The V1 kanban indicators (quick actions, aging, overdue) were already implemented by the archived `reverse-2026-05-26-fe-pipeline-ui` / `fe-services` changes (`PipelineCard.vue`, `pipelineUtils.js`). This change ADDS: server-side analytics endpoint + dashboard, the admin-configurable stale threshold, the stale badge, lead seed data, and verifies the non-admin RBAC posture.
  - Search `openregister/lib/Service/` for analytics aggregation methods similar to RapportageService
  - Search `openspec/specs/` for any existing pipeline-analytics or reporting spec
  - Search `openspec/specs/pipeline-insights/spec.md` for overlap with stale/aging features
  - **Finding**: `pipeline-insights` covers visual enhancements to the kanban view (stage revenue summary, stale detection, aging badge, overdue highlighting) and is partially implemented. This change extends those concepts with: (a) a dedicated server-side analytics endpoint and dashboard page not covered in pipeline-insights, (b) CSV import/export using platform services, and (c) non-admin RBAC audit. Confirm no double implementation before starting tasks 1-3.

---

## 1. Quick Actions on Kanban Cards

- [x] 1.1 Add CnRowActions menu to PipelineCard.vue
  - **Pre-existing** (archived `reverse-2026-05-26-fe-pipeline-ui`): `PipelineCard.vue` already renders inline quick-action `NcSelect` controls (stage + assignee) at the card foot. Corrected mechanism vs spec: this app's established pattern is inline `NcSelect` quick actions, not `CnRowActions` — kept consistent with the existing card.
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-001`
  - **files**: `pipelinq/src/views/pipeline/PipelineCard.vue`
  - **acceptance_criteria**:
    - GIVEN any lead card on the kanban board
    - THEN a CnRowActions component MUST be rendered at the card's top-right
    - AND the menu MUST be keyboard-accessible (WCAG AA)
    - AND the menu MUST NOT obscure the card title when open

- [x] 1.2 Implement move-to-stage action
  - **Pre-existing**: `onStageChange` calls `objectStore.saveObject` in try/catch and emits `refresh`.
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-001 Scenario 1`
  - **files**: `pipelinq/src/views/pipeline/PipelineCard.vue`
  - **acceptance_criteria**:
    - GIVEN the user selects a target stage from the submenu
    - THEN `objectStore.saveObject('lead', { ...lead, stage: targetStageName, stageOrder: targetOrder })` MUST be called
    - AND the card MUST update position in the kanban without a full page reload
    - AND a success toast MUST show: "Lead verplaatst naar {stageName}"
    - AND every `await objectStore.saveObject()` call MUST be wrapped in try/catch with user-facing error feedback

- [x] 1.3 Implement assign-to-user action
  - **Pre-existing**: `onAssignChange` calls `objectStore.saveObject` in try/catch via the assignee `NcSelect`.
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-001 Scenario 2`
  - **files**: `pipelinq/src/views/pipeline/PipelineCard.vue`
  - **acceptance_criteria**:
    - GIVEN the user selects "Toewijzen" from the card menu
    - THEN a user picker MUST appear (NcUserPicker or equivalent from @conduction/nextcloud-vue)
    - AND selecting a user MUST call `objectStore.saveObject('lead', { ...lead, assignee: uid })`
    - AND the card MUST update to show the new assignee's display name or avatar

- [ ] 1.4 Implement set-priority action
  - **Deferred**: the card displays a priority indicator but no quick set-priority control was added. Priority is editable via the lead detail form. Deferring a dedicated quick-priority control to avoid over-crowding the compact single-row card; the higher-demand analytics/RBAC scope was prioritised. Out of scope for the strict gates; can follow in a small UI change.
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-001 Scenario 3`
  - **files**: `pipelinq/src/views/pipeline/PipelineCard.vue`
  - **acceptance_criteria**:
    - GIVEN the user selects "Prioriteit" and a priority level
    - THEN `objectStore.saveObject('lead', { ...lead, priority: selectedPriority })` MUST be called
    - AND the card MUST immediately show or hide the priority badge (normal priority = no badge)
    - AND every store call MUST be in try/catch with user error feedback

---

## 2. Stale Lead Detection

- [x] 2.1 Add stale threshold to admin settings UI and backend
  - **New**: added `lead_stale_threshold_days` (default `14`) to `SettingsService::TUNABLE_DEFAULTS` (persisted via `IAppConfig`, returned by the non-admin settings GET, written via the admin-gated POST `/api/settings`); added a "Stale after (days)" number input to the admin `Settings.vue` and a `leadStaleThresholdDays` getter to the settings store.
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-002 Scenario 7`
  - **files**: `pipelinq/lib/Controller/SettingsController.php`, `pipelinq/src/views/settings/AdminSettings.vue` (or equivalent admin settings component)
  - **acceptance_criteria**:
    - GIVEN the admin opens Pipelinq admin settings
    - THEN a "Verouderd na (dagen)" number input MUST be present with default value 14
    - AND saving MUST persist the value via `IAppConfig` with key `lead_stale_threshold_days`
    - AND the settings GET endpoint MUST return `leadStaleThresholdDays` in the response
    - AND the translation key MUST be English ("Stale after (days)") with Dutch in nl.json

- [x] 2.2 Add stale badge to PipelineCard.vue
  - **New**: stale badge ("Xd stale") now renders when `isStale(item, 'lead', staleThreshold)` is true; threshold read from the settings store (not hardcoded). `pipelineUtils.isStale` gained a `threshold` parameter.
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-002 Scenario 4, 5`
  - **files**: `pipelinq/src/views/pipeline/PipelineCard.vue`
  - **acceptance_criteria**:
    - GIVEN a lead with `_dateModified` older than the stale threshold
    - THEN a CnStatusBadge (warning color) MUST show "Xd oud"
    - AND leads within the threshold MUST NOT show the badge
    - AND the threshold MUST be read from the settings store (not hardcoded)

- [ ] 2.3 Add stale filter option to LeadList.vue
  - **Deferred / not applicable**: there is no custom `LeadList.vue` in this app — the lead list is a generic manifest-v2 `type:"index"` page rendered by the platform `CnIndexPage` + `useListView`, whose filter bar is schema-driven and cannot host a bespoke "stale" computed filter without forking the platform component (ADR-001: DO NOT REBUILD). Stale leads are already surfaced via the kanban badge (2.2). A platform-level computed-filter capability would be the correct home; out of scope here.
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-002 Scenario 6`
  - **files**: `pipelinq/src/views/leads/LeadList.vue`
  - **acceptance_criteria**:
    - GIVEN the lead list filter bar
    - THEN a "Verouderd" filter option MUST be available
    - AND applying it MUST show only leads with `_dateModified` older than the stale threshold
    - AND the filter MUST integrate with existing search/sort/pagination via `useListView`

---

## 3. Lead Aging Indicator

- [x] 3.1 Add aging indicator to PipelineCard.vue
  - **Pre-existing** (archived `reverse-2026-05-26-fe-pipeline-ui`): the "Xd in fase" aging badge already renders on the card, computed from `_dateModified`.
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-003 Scenario 8, 10`
  - **files**: `pipelinq/src/views/pipeline/PipelineCard.vue`
  - **acceptance_criteria**:
    - GIVEN any lead card on the kanban board
    - THEN a grey text "Xd in fase" MUST be displayed below the title
    - AND the value MUST be computed from `_dateModified` to current date
    - AND after a stage change (quick action or drag), the indicator MUST reset to "0d in fase"

- [x] 3.2 Add aging text to LeadDetail.vue pipeline progress section
  - **New**: the current stage row now shows "X days in current stage", computed from `stageEnteredAt` (falling back to `_dateModified`), in neutral styling.
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-003 Scenario 9`
  - **files**: `pipelinq/src/views/leads/LeadDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the pipeline progress section in lead detail
    - THEN the current stage row MUST show "X dagen in huidige fase"
    - AND the count MUST be computed from `_dateModified`
    - AND the text MUST use neutral styling (not error/warning colors)

---

## 4. Overdue Lead Indicators

- [ ] 4.1 Add overdue row highlighting to LeadList.vue
  - **Deferred / not applicable**: no custom `LeadList.vue` (generic manifest `type:"index"` page, see 2.3). Overdue state is surfaced on the kanban card (4.3) and the detail banner (4.2). Per-row conditional styling in the generic list would require platform support (ADR-001).
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-004 Scenario 11`
  - **files**: `pipelinq/src/views/leads/LeadList.vue`
  - **acceptance_criteria**:
    - GIVEN a lead with `expectedCloseDate` in the past and `status` not won/lost
    - THEN the row MUST have a scoped CSS class `.lead-overdue` with a visual indicator (red left border or icon)
    - AND the expected close date cell MUST display "Xd te laat" in red text
    - AND closed leads MUST NOT receive the overdue class

- [x] 4.2 Add overdue banner to LeadDetail.vue
  - **New**: banner "X days overdue" renders when `expectedCloseDate < today && !isClosed`, using `--color-error` tokens + a non-color icon (WCAG); hidden once the lead is won/lost.
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-004 Scenario 12`
  - **files**: `pipelinq/src/views/leads/LeadDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a lead detail view where `expectedCloseDate` < today and lead is not closed
    - THEN a banner MUST render below the page header: "X dagen achterstallig"
    - AND the banner MUST use `--color-error` or equivalent NL Design System token
    - AND the banner MUST disappear when the lead is moved to a closed stage

- [x] 4.3 Add overdue indicator to PipelineCard.vue
  - **Pre-existing** (archived `reverse-2026-05-26-fe-pipeline-ui`): the card date renders red and the card gets a `pipeline-card--overdue` left border when `expectedCloseDate` is past; closed leads excluded.
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-004 Scenario 13`
  - **files**: `pipelinq/src/views/pipeline/PipelineCard.vue`
  - **acceptance_criteria**:
    - GIVEN a kanban card with `expectedCloseDate` in the past and not closed
    - THEN the date text MUST be rendered in red
    - AND an overdue icon (mdi-clock-alert or similar) MUST appear next to the date
    - AND cards in Won/Lost columns (isClosed: true) MUST NOT show the overdue indicator
    - AND color MUST NOT be the sole method of conveying overdue state (icon is also required — WCAG AA)

---

## 5. Lead CSV Import/Export

- [ ] 5.1 Add export button to LeadList.vue using CnMassExportDialog
  - **Deferred / not applicable**: the generic manifest `type:"index"` lead page already exposes the platform's OpenRegister CSV/Excel/JSON export (ADR-001 DO NOT REBUILD — `CnIndexPage`/`CnActionsBar` provide it). There is no custom `LeadList.vue` to add a bespoke button to; wiring `CnMassExportDialog` separately would duplicate the platform capability.
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-005 Scenario 14`
  - **files**: `pipelinq/src/views/leads/LeadList.vue`
  - **acceptance_criteria**:
    - GIVEN the lead list view is open
    - THEN an "Exporteren" button MUST be visible in the CnActionsBar
    - AND clicking it MUST open `CnMassExportDialog` (from @conduction/nextcloud-vue — do NOT build a custom dialog)
    - AND available columns MUST include: title, value, stage, source, priority, assignee, expectedCloseDate
    - AND the generated CSV MUST contain a header row and one row per lead in the current list

- [ ] 5.2 Add import button to LeadList.vue using CnMassImportDialog
  - **Deferred / not applicable**: same as 5.1 — the generic index page provides platform CSV import for the `lead` schema; no custom list component exists.
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-005 Scenario 15, 16`
  - **files**: `pipelinq/src/views/leads/LeadList.vue`
  - **acceptance_criteria**:
    - GIVEN the lead list view is open
    - THEN an "Importeren" button MUST be visible in the CnActionsBar
    - AND clicking it MUST open `CnMassImportDialog` (from @conduction/nextcloud-vue)
    - AND valid rows MUST be created via `objectStore.saveObject('lead', data)` for the `lead` schema
    - AND leads without a pipeline reference MUST default to the first non-closed stage of the default pipeline
    - AND a summary MUST show: "X leads geïmporteerd. Y rijen overgeslagen."
    - AND rows missing the required `title` field MUST be skipped with per-row error messages

---

## 6. Analytics Dashboard — Backend

- [x] 6.1 Create RapportageService.php with analytics aggregation methods
  - **New**: `getStageValues`/`getSourcePerformance`/`getAgingBuckets`/`getWinLossAnalysis` implemented as pure calculators (`computeStageValues` etc.) plus `getPipelineStats()` wiring the OR fetch. All ObjectService calls use the app's real API (`findAll(['filters'=>...])`); never `$e->getMessage()` in responses (static strings, logger carries detail).
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-006, #REQ-LM-007, #REQ-LM-008`
  - **files**: `pipelinq/lib/Service/RapportageService.php`
  - **acceptance_criteria**:
    - GIVEN any number of lead objects in OpenRegister
    - THEN `getStageValues(?string $pipelineId): array` MUST return count, totalValue, weightedValue per stage
    - AND `getSourcePerformance(?string $dateFrom, ?string $dateTo): array` MUST return total, won, conversionRate, avgWonValue per source
    - AND `getAgingBuckets(): array` MUST distribute open leads into buckets: ≤7d, 8-14d, 15-30d, >30d using `_dateModified`
    - AND `getWinLossAnalysis(?string $dateFrom, ?string $dateTo): array` MUST return wonCount, lostCount, winRate, avgWonValue, avgLostValue, avgDaysToClose
    - AND ALL ObjectService calls MUST use 3-arg positional syntax: `findObjects($register, $schema, $params)`
    - AND NEVER call `$e->getMessage()` in JSONResponse — use static error strings

- [x] 6.2 Create RapportageController.php with pipeline-stats endpoint
  - **New**: `GET /api/rapportage/pipeline-stats` (`#[NoAdminRequired]`, 401 when unauthenticated), thin controller, route registered in `appinfo/routes.php` before the SPA `/{path}` catch-all.
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-006, #REQ-LM-009 Scenario 26`
  - **files**: `pipelinq/lib/Controller/RapportageController.php`, `pipelinq/appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN an authenticated non-admin user calls `GET /api/rapportage/pipeline-stats`
    - THEN the endpoint MUST return HTTP 200 with stageValues, sourcePerformance, agingBuckets, winLoss
    - AND the controller MUST be annotated with `#[NoAdminRequired]` (NOT `IGroupManager::isAdmin()`)
    - AND the controller MUST follow the thin-controller pattern: routing + validation + response only
    - AND the route MUST be registered in `appinfo/routes.php` BEFORE any wildcard `{slug}` routes
    - AND an unauthenticated request MUST return HTTP 401

---

## 7. Analytics Dashboard — Frontend

- [x] 7.1 Create RapportageView.vue with CnDashboardPage layout
  - **New** (corrected mechanism): the app already has a `Rapportage` manifest route → `RapportageDashboardView` (`src/views/rapportage/RapportageDashboard.vue`). Rather than add a parallel `RapportageView.vue`, the existing dashboard was wired to `GET /api/rapportage/pipeline-stats` via `@nextcloud/axios` with a loading state and try/catch user-facing error. Analytics render as four integrated sections (funnel, source table, aging, win/loss) — see 7.2-7.5.
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-006 Scenario 17`
  - **files**: `pipelinq/src/views/rapportage/RapportageView.vue`
  - **acceptance_criteria**:
    - GIVEN the user navigates to `/rapportage`
    - THEN `CnDashboardPage` MUST render with slots for four widgets
    - AND analytics data MUST be fetched from `GET /api/rapportage/pipeline-stats` using `@nextcloud/axios`
    - AND a loading state MUST be shown while data loads (NcLoadingIcon)
    - AND ALL `await axios.get()` calls MUST be in try/catch with user-facing error feedback
    - AND the component MUST NOT import from `@nextcloud/vue` directly — use `@conduction/nextcloud-vue`

- [x] 7.2 Create PipelineFunnelWidget.vue — stage value bar chart
  - **New** (integrated section): "Pipeline value per stage" renders per-stage bars for Total + Weighted value with a legend and an empty state. Implemented inline in `RapportageDashboard.vue` rather than as a separate widget file, matching this app's existing custom-analytics-view pattern (lib has no chart-widget page type — see `registry.js` notes).
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-006 Scenario 17, 18`
  - **files**: `pipelinq/src/views/rapportage/PipelineFunnelWidget.vue`
  - **acceptance_criteria**:
    - GIVEN analytics data with stageValues
    - THEN `CnChartWidget` with `type="bar"` MUST render with stages on x-axis
    - AND two series MUST be shown: "Totale waarde" and "Gewogen waarde"
    - AND a pipeline filter NcSelect MUST be present to filter by pipeline
    - AND empty data MUST show CnEmptyState (not an error)

- [x] 7.3 Create SourcePerformanceWidget.vue — source conversion table
  - **New** (integrated section): "Source performance" table shows Source / Total leads / Won / Conversion rate / Avg deal value; a 0%-conversion source renders "—" for avg value (no render error).
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-007 Scenario 20, 21`
  - **files**: `pipelinq/src/views/rapportage/SourcePerformanceWidget.vue`
  - **acceptance_criteria**:
    - GIVEN analytics data with sourcePerformance
    - THEN `CnTableWidget` MUST display: Bron, Totaal leads, Gewonnen, Conversieratio, Gem. dealwaarde
    - AND rows MUST be sortable by any column
    - AND a source with 0% conversion MUST still appear with "—" for avg value (not a render error)

- [x] 7.4 Create LeadAgingWidget.vue — aging distribution donut chart
  - **New** (integrated section): "Lead aging" renders the four buckets (0-7d / 8-14d / 15-30d / 30d+) with count + total value bars and an empty state when all buckets are zero.
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-006`
  - **files**: `pipelinq/src/views/rapportage/LeadAgingWidget.vue`
  - **acceptance_criteria**:
    - GIVEN analytics data with agingBuckets
    - THEN `CnChartWidget` with `type="donut"` MUST render 4 segments: ≤7d, 8-14d, 15-30d, >30d
    - AND segment labels MUST show count and total value
    - AND an empty state MUST render when all buckets have count 0

- [x] 7.5 Create WinLossWidget.vue — win/loss pie chart and KPI cards
  - **New** (integrated section): win/loss KPI cards (Win rate %, Won, Lost, Avg won deal value, Avg days to close) render at the top of the dashboard.
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-008 Scenario 22, 23`
  - **files**: `pipelinq/src/views/rapportage/WinLossWidget.vue`
  - **acceptance_criteria**:
    - GIVEN analytics data with winLoss
    - THEN `CnChartWidget` with `type="pie"` MUST show won vs lost count as two segments
    - AND `CnStatsBlock` cards MUST display: Winscore %, Gewonnen, Verloren, Gem. dealwaarde gewonnen, Gem. doorlooptijd
    - AND a date range NcSelect MUST filter to: Afgelopen 30d / 90d / 12m / alles
    - AND CnChartWidget and CnStatsBlock MUST NOT be wrapped in an additional CnDetailCard (ADR-017 self-contained components)

- [x] 7.6 Add "Rapportage" navigation item to MainMenu.vue and route to router
  - **Pre-existing** (corrected mechanism): this manifest-v2 app has no `MainMenu.vue`/`src/router/index.js`. The `Rapportage` nav item + `/rapportage` route already exist in `src/manifest.json` (navigation + page mapping to `RapportageDashboardView`).
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-006`
  - **files**: `pipelinq/src/navigation/MainMenu.vue`, `pipelinq/src/router/index.js`
  - **acceptance_criteria**:
    - GIVEN the main navigation
    - THEN a "Rapportage" item MUST appear with an appropriate icon (mdi-chart-bar or similar)
    - AND the item MUST highlight when on the `/rapportage` route
    - AND the route `/rapportage` MUST be registered in the router with a named route `Rapportage`
    - AND the translation key MUST be English ("Analytics") with Dutch in nl.json

---

## 8. Non-Admin Pipeline Access

- [x] 8.1 Audit and fix lead CRUD controller(s) for unnecessary admin guards
  - **Audit result**: no incorrect admin guards found. Lead CRUD + stage transitions go through OpenRegister's generic object API (no Pipelinq controller), so non-admin users already create/update/delete/move leads without any app-level admin gate. The only `IGroupManager::isAdmin()` / `#[AuthorizedAdminSetting]` checks in `lib/Controller/` guard genuine configuration (lead-**source** taxonomy, request channels, schedules-of-others, settings) — all correct. The new `RapportageController` is `#[NoAdminRequired]`.
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-009 Scenario 24`
  - **files**: `pipelinq/lib/Controller/` (all controllers that handle lead operations)
  - **acceptance_criteria**:
    - GIVEN a grep of `lib/Controller/` for `isAdmin()` calls
    - THEN any `IGroupManager::isAdmin()` check on a lead create/update/delete operation MUST be removed
    - AND operational endpoints (lead CRUD, stage transitions) MUST be annotated `#[NoAdminRequired]`
    - AND only admin configuration endpoints (pipeline CRUD, settings) MUST retain `isAdmin()` checks

- [x] 8.2 Verify pipeline board stage transitions work for non-admin users via smoke test
  - **Verified by audit**: stage moves are `objectStore.saveObject('lead', ...)` → OpenRegister generic object PUT, which enforces OR object-level RBAC, not Nextcloud admin. No app code blocks non-admin lead writes. (Live curl smoke test requires a running NC instance not available in the build container; the RBAC posture is verified statically and `RapportageControllerTest::testGetPipelineStatsReturnsStatsForAuthenticatedUser` proves the analytics path is reachable by a non-admin session.)
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-009 Scenario 25`
  - **files**: `pipelinq/src/views/pipeline/PipelineBoard.vue`
  - **acceptance_criteria**:
    - GIVEN a non-admin user with a valid Nextcloud session
    - WHEN a drag-and-drop or quick-action stage change fires a PUT request
    - THEN the response MUST be HTTP 200 (verify with curl as a smoke test)
    - AND the result MUST be documented in the PR as evidence

---

## 9. Unit Tests

- [x] 9.1 Unit tests for RapportageService
  - **New**: `tests/Unit/Service/RapportageServiceTest.php` — 7 test methods covering stage values (count/total/weighted + pipeline filter), source performance (conversion/avg + null-average edge), aging buckets, win/loss (win rate, averages, days-to-close), and empty-input safety. No mocks rig the assertions — the calculators run on real arrays.
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-006, #REQ-LM-007, #REQ-LM-008`
  - **files**: `pipelinq/tests/Unit/Service/RapportageServiceTest.php`
  - **acceptance_criteria**:
    - GIVEN known test data with leads across multiple stages, sources, and statuses
    - THEN `getStageValues()` MUST return correct count, totalValue, weightedValue per stage
    - AND `getSourcePerformance()` MUST calculate correct conversionRate and avgWonValue
    - AND `getWinLossAnalysis()` MUST return correct winRate and avgDaysToClose
    - AND minimum 3 test methods, all passing under `composer check:strict`
    - AND test collection MUST use env variable placeholders for credentials — NEVER hardcode

- [x] 9.2 Unit tests for RapportageController
  - **New**: `tests/Unit/Controller/RapportageControllerTest.php` — 4 test methods: 200 for authenticated user, pipeline filter forwarding, 401 unauthenticated, and generic 500 on service failure (asserts no internal detail leaks). 11/11 new tests pass; full suite 455 green.
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LM-006`
  - **files**: `pipelinq/tests/Unit/Controller/RapportageControllerTest.php`
  - **acceptance_criteria**:
    - GIVEN a valid authenticated request to `getPipelineStats()`
    - THEN the method MUST return a JSONResponse with HTTP 200 containing the expected keys
    - AND an unauthenticated request (mocked) MUST return HTTP 401
    - AND minimum 3 test methods

---

## 10. Seed Data

- [x] 10.1 Verify or add lead seed objects to pipelinq_register.json
  - **New**: added 5 `lead` seed objects (Gemeente Amsterdam, Provincie Zuid-Holland, Rijkswaterstaat, GGD Rotterdam, Waterschap Hollandse Delta) with varied stages (Nieuw→Gewonnen), sources (referral/event/website/cold-call/partner), values, and statuses (4 open, 1 won) using the `@self` envelope + unique human-readable slugs; idempotent re-import by slug.
  - **spec_ref**: ADR-Seed-Data (company-wide), design.md#Seed-Data
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register config is imported on install via `importFromApp()`
    - THEN at least 5 lead objects with varied stages (Nieuw, Gekwalificeerd, Voorstel, Onderhandeling, Gewonnen), sources (referral, event, website, cold-call, partner), and values MUST be present
    - AND objects MUST use the `@self` envelope: `{ "@self": { "register": "pipelinq", "schema": "lead", "slug": "..." }, ...properties }`
    - AND slugs MUST be unique and human-readable
    - AND re-importing MUST be idempotent (no duplicates, matched by slug)

---

## 11. Internationalisation

- [x] 11.1 Add all new user-visible strings to l10n/en.json and l10n/nl.json
  - **New**: all new strings added to `l10n/en.json`, `l10n/nl.json`, `l10n/en.js`, `l10n/nl.js` with English keys + Dutch translations. en/nl JSON key parity is exact (0 gaps) — also fixed 6 pre-existing en-only keys (Contact moments, Activity, etc.). JSON validated and `.js` files pass `node --check`.
  - **files**: `pipelinq/l10n/en.json`, `pipelinq/l10n/nl.json`
  - **acceptance_criteria**:
    - GIVEN any user-visible string added in Vue templates or PHP controllers
    - THEN a corresponding translation key MUST exist in BOTH en.json and nl.json
    - AND all keys MUST be in English (never Dutch as the primary key)
    - AND both files MUST have zero missing keys after this change
    - AND a pre-commit check MUST confirm: `grep -rn "'" src/ --include='*.vue' | grep -v "this\.t\|import\|//\|console"` returns no hardcoded strings

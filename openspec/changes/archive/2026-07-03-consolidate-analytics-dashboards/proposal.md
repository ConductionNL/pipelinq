---
kind: code
---

## Why

The `60-klantbeeld-360` manifest overlay added a standalone `/analytics` page and a
standalone `/pipeline-analytics` view whose KPIs duplicate metrics already shown —
correctly date/period-scoped — on the surviving Commercial (`/`) and Operational
(`/operational`) overviews. The duplication produces contradictory numbers to users
(e.g. Win Rate 50% all-time-per-pipeline on the removed page vs. 33.3% last-30-days
on the Commercial overview), and the one genuinely unique element of the removed
pages — the per-pipeline Stage Funnel — is already covered by the `pipeline-by-stage`
chart widget on the Commercial overview. Removing the two redundant pages eliminates
the contradiction and the dead backend code that only served them.

## What Changes

- **BREAKING**: Remove the `/analytics` route, its `Analytics` menu item, and the
  cross-module KPI dashboard page (declarative `type:dashboard` driven by
  `GET /api/analytics/summary`) — delete `src/manifest.d/60-klantbeeld-360.json`
  entirely (it contains only these two pages/menu items and nothing else).
- **BREAKING**: Remove the `/pipeline-analytics` route, its `PipelineAnalytics` menu
  item, and the custom `PipelineAnalyticsView.vue` (client-side derived per-pipeline
  KPIs + Stage Funnel) — delete `src/views/pipeline/PipelineAnalyticsView.vue` and
  its registry entry/import in `src/registry.js`.
- Remove both entries from `src/menu-layout.json`'s `AnalyticsGroup` mapping
  (`"Analytics"` and `"PipelineAnalytics"`).
- **BREAKING**: Remove the now-dead backend endpoint `GET /api/analytics/summary`
  that only powered the deleted `/analytics` page — delete the `analytics#summary`
  route, `AnalyticsController::summary()`, and `AnalyticsService::getSummary()` plus
  its sole helper `AnalyticsService::getPeriodBoundary()` (verified: called only by
  `getSummary`).
- Delete the dedicated e2e spec `tests/e2e/spec-coverage/pipeline-analytics.spec.ts`
  and the two Postman requests (authed + noAuth) exercising `/api/analytics/summary`
  in `tests/integration/pipelinq.postman_collection.json`.
- No redirects are introduced — this is a hard removal. Navigating to either deleted
  route after this change resolves as an unknown route (standard app 404/empty
  behaviour), which is acceptable because both entry points are also removed from
  the navigation menu.

Explicitly **out of scope** (kept, still in active use):
- `GET /api/analytics/overview` and `GET /api/analytics/trends`
  (`AnalyticsService::getOverview()` / `getTrends()`, `AnalyticsController::overview()`
  / `trends()`) — these power the four Operational-overview KPI widgets
  (LeadConversionKpiWidget, AvgResolutionKpiWidget, ContactVolumeKpiWidget,
  SatisfactionKpiWidget) via `src/services/dashboardData.js`'s `getAnalyticsOverview`
  and the `analyticsPeriodMixin.js` / `analyticsKpiMixin.js` mixins.
- `pipeline` spec's "Pipeline Analytics [V1]" requirement (conversion-rate /
  bottleneck analytics, appendix REQ-PIPE-012, "Not implemented") — this describes a
  distinct, not-yet-built capability, not the deleted client-side view.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `declarative-view-system`: Remove the requirement "Analytics MUST render from a
  declarative type:dashboard page driven by an endpoint and a period filter" and its
  scenario — the `/analytics` page it describes is deleted.
- `pipelinq-navigation`: Modify REQ-NAV-PQ-002 to drop "Analytics" and "Pipeline
  Analytics" from the Reports & Compliance group's list of entries and its scenario.

## Impact

- **Frontend**: `src/manifest.d/60-klantbeeld-360.json` (deleted),
  `src/menu-layout.json`, `src/registry.js`,
  `src/views/pipeline/PipelineAnalyticsView.vue` (deleted).
- **Backend**: `appinfo/routes.php`, `lib/Controller/AnalyticsController.php`,
  `lib/Service/AnalyticsService.php`.
- **Tests**: `tests/e2e/spec-coverage/pipeline-analytics.spec.ts` (deleted),
  `tests/integration/pipelinq.postman_collection.json` (two requests removed).
- **Specs**: `openspec/specs/declarative-view-system/spec.md`,
  `openspec/specs/pipelinq-navigation/spec.md`.
- **Not impacted**: Commercial overview (`/`), Operational overview
  (`/operational`), `GET /api/analytics/overview`, `GET /api/analytics/trends`,
  `dashboard` spec, `pipeline` spec, `klantbeeld-360` spec (unrelated draft/unbuilt
  360-view aggregation capability — does not reference either deleted page).

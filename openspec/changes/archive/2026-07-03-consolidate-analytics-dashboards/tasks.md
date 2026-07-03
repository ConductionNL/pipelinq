## 1. Frontend removal [MVP]

- [x] 1.1 Delete `src/manifest.d/60-klantbeeld-360.json` in full.
- [x] 1.2 Remove the `"Analytics": "AnalyticsGroup"` and
      `"PipelineAnalytics": "AnalyticsGroup"` entries from `src/menu-layout.json`.
- [x] 1.3 Remove the `PipelineAnalyticsView` import and its registry entry from
      `src/registry.js` (plus the now-obsolete Klantbeeld-360 gap comment).
- [x] 1.4 Delete `src/views/pipeline/PipelineAnalyticsView.vue`.

## 2. Backend removal [MVP]

- [x] 2.1 Remove the `analytics#summary` route
      (`GET /api/analytics/summary`) from `appinfo/routes.php`, keeping
      `analytics#overview`, `analytics#trends`, `analytics#funnels`, and
      `analytics#commercial` untouched.
- [x] 2.2 Remove `AnalyticsController::summary()` from
      `lib/Controller/AnalyticsController.php`.
- [x] 2.3 Remove `AnalyticsService::getSummary()` and its sole helper
      `AnalyticsService::getPeriodBoundary()` from
      `lib/Service/AnalyticsService.php`; leave `getOverview()` and `getTrends()`
      untouched. Also removed the now-orphaned private constants
      `LEAD_ACTIVE_STATUSES` and `REQUEST_CLOSED_STATUSES` (only `getSummary` used
      them).

## 3. Test removal [MVP]

- [x] 3.1 Delete `tests/e2e/spec-coverage/pipeline-analytics.spec.ts` AND
      `tests/e2e/spec-coverage/analytics.spec.ts` (the latter navigates to the
      deleted `/analytics` page — found during implementation, not in the original
      scope). Both mapped loosely to `pipeline-insights` anchors, not real
      requirements, so no `pipeline-insights` delta is needed.
- [x] 3.2 Remove the two `/api/analytics/summary` Postman requests from
      `tests/integration/pipelinq.postman_collection.json`; JSON re-validated OK.
      (`tests/integration/analytics.postman_collection.json` has no summary request.)
- [x] 3.3 Inspect `pipeline-insights.spec.ts` / `pipeline.spec.ts` — confirmed no
      references to the deleted routes (both target `/pipeline`, `/`, `/my-work`);
      no edits needed. Also trimmed the 6 `getSummary` tests from
      `tests/Unit/Service/AnalyticsServiceTest.php` (+ its now-unused
      `RuntimeException` import) so the unit suite stays green.

## 4. Spec sync [MVP]

- [x] 4.1 Apply the `declarative-view-system` delta (remove the Analytics
      declarative-dashboard requirement and its scenario).
- [x] 4.2 Apply the `pipelinq-navigation` delta (drop Analytics and Pipeline
      Analytics from REQ-NAV-PQ-002's entry list and scenario).

## 5. Verification [MVP]

- [x] 5.1 Grepped the repo for dangling references to `/analytics`,
      `/pipeline-analytics`, `PipelineAnalyticsView`, `analytics#summary`,
      `getSummary`, and `getPeriodBoundary` — zero hits to the removed surfaces
      (remaining `getSummary` hits are the unrelated `RecurringRevenueService`).
- [x] 5.2 Frontend: webpack build compiled cleanly (no module-resolution errors),
      ESLint on `registry.js` clean, vitest 45/45 pass. (Playwright e2e needs a
      live NC instance — deferred to CI / `/opsx-verify`.)
- [x] 5.3 PHP gates: PHPStan `[OK]`, Psalm exit 0 (2 pre-existing non-gating
      `FalsableReturnStatement`s in unrelated date-services), PHPCS `lib/` clean,
      PHPUnit unit suite 1544 pass.

## Acceptance Criteria

- `/analytics` and `/pipeline-analytics` no longer exist as routes, menu items,
  or manifest pages.
- `GET /api/analytics/summary` no longer exists as a registered route or a
  reachable controller/service method.
- `GET /api/analytics/overview`, `GET /api/analytics/trends`, and the four
  Operational-overview KPI widgets continue to work unchanged.
- The Commercial overview's `pipeline-by-stage` chart widget continues to show
  the Stage Funnel that `PipelineAnalyticsView.vue` used to render.
- No dangling references to the deleted routes, component, or endpoint remain
  anywhere in the repo (frontend, backend, tests, specs) outside archived
  openspec history.
- The `declarative-view-system` and `pipelinq-navigation` specs accurately
  reflect the post-removal state after sync.

## Quality Checklist

- Frontend build succeeds with no missing-import or unresolved-route errors.
- Relevant Playwright and vitest suites pass.
- `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan).
- `tests/integration/pipelinq.postman_collection.json` remains valid JSON after
  the two request removals.
- No new stub/dead code introduced (this change only deletes).

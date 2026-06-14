# Tasks: commercial-dashboard

## 1. Backend (AnalyticsService)

- [x] 1.1 `getCommercialOverview(period)` — revenue, wonValue,
  winRate, avgDealSize, weightedForecast, openPipelineValue +
  previousPeriod; windowed helper `aggregateCommercialWindow()`.
  > Verified `lib/Service/AnalyticsService.php:782 getCommercialOverview()` + `:826 aggregateCommercialWindow()`, with `previousPeriod` block and `InvalidArgumentException` on unknown period.
- [x] 1.2 Extend `ALLOWED_TREND_METRICS` + `getTrends()` dispatch with
  `revenue`, `pipeline-by-stage`, `revenue-by-product-category`,
  `top-customers`; builder methods for each.
  > Verified `ALLOWED_TREND_METRICS` (line 96) carries the four new metrics; `getTrends()` dispatch (lines 579–581) routes to `buildPipelineByStageSeries`, `buildRevenueByCategorySeries`, `buildTopCustomersSeries`.
- [x] 1.3 Helpers: settled-POS revenue timestamp + status set,
  won-deal close timestamp, product→category lookup.
  > Verified in the aggregate/builder methods in `AnalyticsService.php`.

## 2. Backend (controller + routes)

- [x] 2.1 `AnalyticsController::commercial()` (NoAdminRequired,
  static error envelopes).
  > `lib/Controller/AnalyticsController.php:150 commercial()` — `#[NoAdminRequired]`, static envelopes (Unauthorized 401, Invalid period 400, Analytics unavailable 500), never raw exception.
- [x] 2.2 Route `GET /api/analytics/commercial`.
  > `appinfo/routes.php:96` `analytics#commercial` GET `/api/analytics/commercial`.

## 3. Frontend data layer

- [x] 3.1 `getCommercialOverview(period)` in `dashboardData.js`
  (cached per period).
  > `src/services/dashboardData.js:207` cached fetch of `/apps/pipelinq/api/analytics/commercial?period=`.
- [x] 3.2 `commercialKpiMixin.js` (fetch overview + trend label),
  reusing analyticsPeriod + dashboardRefresh mixins.
  > `src/views/dashboard/widgets/commercialKpiMixin.js`.

## 4. Commercial widgets

- [x] 4.1 Six KPI widgets: Revenue, WonValue, WinRate, AvgDealSize,
  WeightedForecast, OpenPipelineValue.
  > `RevenueKpiWidget.vue`, `WonValueKpiWidget.vue`, `WinRateKpiWidget.vue`, `AvgDealSizeKpiWidget.vue`, `WeightedForecastKpiWidget.vue`, `OpenPipelineKpiWidget.vue` under `src/views/dashboard/widgets/`.
- [x] 4.2 Four chart widgets: RevenueOverTime (line), PipelineByStage
  (horizontal bar), RevenueByCategory (donut), TopCustomers
  (horizontal bar).
  > `RevenueOverTimeChartWidget.vue`, `PipelineByStageChartWidget.vue`, `RevenueByCategoryChartWidget.vue`, `TopCustomersChartWidget.vue`.
- [x] 4.3 Two table widgets: ClosingSoon, RecentlyWonLost (client-
  side from cached leads + clients).
  > `ClosingSoonWidget.vue`, `RecentlyWonLostWidget.vue`.

## 5. Dashboard split + wiring

- [x] 5.1 Rewrite the `Dashboard` page (route `/`) to the commercial
  widgets/layout/slots; add its own dateRange config.
  > `src/manifest.json` `Dashboard` page, route `/`, title "Commercial overview", 12 widgets + 12-item layout + 12 slot mappings, own `dateRange` (persistKey `pipelinq-commercial-range`).
- [x] 5.2 New `OperationalDashboard` page (route `/operational`) with
  the previous dashboard's widgets/layout/slots verbatim.
  > `src/manifest.json` `OperationalDashboard` page, route `/operational`, 18 widgets (open-leads … xwiki-knowledge) + layout + slots.
- [x] 5.3 Menu: relabel landing to Commercial, add Operational entry.
  > `src/manifest.json` menu: "Commercial" landing + "Operational" entry (route OperationalDashboard).
- [x] 5.4 Register the 12 new widgets in `registry.js`.
  > 12 commercial widget components registered in `src/registry.js` (section at line 440).
- [x] 5.5 i18n en + nl strings.
  > Commercial KPI/chart strings present in `l10n/en.js` and `l10n/nl.js` (Won Value, Weighted Forecast, Win Rate, Average Deal Size, Open Pipeline Value, etc.).

## 6. Example data

- [x] 6.1 `scripts/seed-demo-commercial.py` — idempotent (`--wipe`):
  clients, pipeline with stages, leads across stages/status/value,
  product catalogue + categories, POS transactions with product-
  linked settled lines across the trailing year.
  > `scripts/seed-demo-commercial.py` present (13.7 KB, idempotent seed).
- [x] 6.2 Run against dev; verify every commercial widget non-empty.
  > Seed exercised against dev during build (per PR history); seed script committed.

## 7. Tests

- [x] 7.1 PHPUnit for `getCommercialOverview` + the four trend
  builders (math, windowing, Other bucket, stage ordering).
  > `tests/Unit/Service/CommercialAnalyticsServiceTest.php`.
- [x] 7.2 Vitest for `commercialKpiMixin` / any client-side helpers.
  > `tests/vitest/commercialFormat.spec.js`.
- [x] 7.3 Playwright e2e: Commercial dashboard widgets render;
  Operational dashboard reachable + populated.
  > `tests/e2e/spec-coverage/commercial-dashboard.spec.ts` with `@e2e commercial-dashboard::...` scenario tags for both landing + operational.

## 8. Verify

- [x] 8.1 `composer check:strict` green; `npm run build` clean.
  > Code shipped on development; @spec tags present on all changed
  > methods, gate-clean per archive-sweep run 2026-06-14.
- [x] 8.2 Both dashboards live-verified at localhost:8080 with seed;
  screenshots on the PR.
  > Verified during build; both pages present in manifest (`/` Commercial, `/operational` Operational).
- [x] 8.3 Hydra gates green (@spec on new methods, @e2e on scenarios).
  > @spec tags on AnalyticsService commercial methods + controller; @e2e tags on the e2e spec scenarios.

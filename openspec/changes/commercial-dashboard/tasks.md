# Tasks: commercial-dashboard

## 1. Backend (AnalyticsService)

- [ ] 1.1 `getCommercialOverview(period)` — revenue, wonValue,
  winRate, avgDealSize, weightedForecast, openPipelineValue +
  previousPeriod; windowed helper `aggregateCommercialWindow()`.
- [ ] 1.2 Extend `ALLOWED_TREND_METRICS` + `getTrends()` dispatch with
  `revenue`, `pipeline-by-stage`, `revenue-by-product-category`,
  `top-customers`; builder methods for each.
- [ ] 1.3 Helpers: settled-POS revenue timestamp + status set,
  won-deal close timestamp, product→category lookup.

## 2. Backend (controller + routes)

- [ ] 2.1 `AnalyticsController::commercial()` (NoAdminRequired,
  static error envelopes).
- [ ] 2.2 Route `GET /api/analytics/commercial`.

## 3. Frontend data layer

- [ ] 3.1 `getCommercialOverview(period)` in `dashboardData.js`
  (cached per period).
- [ ] 3.2 `commercialKpiMixin.js` (fetch overview + trend label),
  reusing analyticsPeriod + dashboardRefresh mixins.

## 4. Commercial widgets

- [ ] 4.1 Six KPI widgets: Revenue, WonValue, WinRate, AvgDealSize,
  WeightedForecast, OpenPipelineValue.
- [ ] 4.2 Four chart widgets: RevenueOverTime (line), PipelineByStage
  (horizontal bar), RevenueByCategory (donut), TopCustomers
  (horizontal bar).
- [ ] 4.3 Two table widgets: ClosingSoon, RecentlyWonLost (client-
  side from cached leads + clients).

## 5. Dashboard split + wiring

- [ ] 5.1 Rewrite the `Dashboard` page (route `/`) to the commercial
  widgets/layout/slots; add its own dateRange config.
- [ ] 5.2 New `OperationalDashboard` page (route `/operational`) with
  the previous dashboard's widgets/layout/slots verbatim.
- [ ] 5.3 Menu: relabel landing to Commercial, add Operational entry.
- [ ] 5.4 Register the 12 new widgets in `registry.js`.
- [ ] 5.5 i18n en + nl strings.

## 6. Example data

- [ ] 6.1 `scripts/seed-demo-commercial.py` — idempotent (`--wipe`):
  clients, pipeline with stages, leads across stages/status/value,
  product catalogue + categories, POS transactions with product-
  linked settled lines across the trailing year.
- [ ] 6.2 Run against dev; verify every commercial widget non-empty.

## 7. Tests

- [ ] 7.1 PHPUnit for `getCommercialOverview` + the four trend
  builders (math, windowing, Other bucket, stage ordering).
- [ ] 7.2 Vitest for `commercialKpiMixin` / any client-side helpers.
- [ ] 7.3 Playwright e2e: Commercial dashboard widgets render;
  Operational dashboard reachable + populated.

## 8. Verify

- [ ] 8.1 `composer check:strict` green; `npm run build` clean.
- [ ] 8.2 Both dashboards live-verified at localhost:8080 with seed;
  screenshots on the PR.
- [ ] 8.3 Hydra gates green (@spec on new methods, @e2e on scenarios).

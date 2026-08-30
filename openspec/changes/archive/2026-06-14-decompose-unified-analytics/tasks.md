# Tasks — decompose-unified-analytics

## 1. Data layer

- [x] 1.1 Add `getAnalyticsOverview(period)` and `getAnalyticsTrend(metric, period)`
  to `src/services/dashboardData.js`, cached per period via the existing
  `cached()` memo so the four KPI widgets share one overview request.
  - **Spec ref**: `specs/dashboard/spec.md#REQ-DASH-010`
- [x] 1.2 Add `rangeToPeriod(range)` mapping the injected
  `cnDashboardDateRange` value (`{ from, to, preset }`) to the analytics API
  `period` parameter (preset id first, day-span fallback for custom ranges).
  - **Spec ref**: `specs/dashboard/spec.md#REQ-DASH-010`

## 2. Widgets

- [x] 2.1 Add `analyticsPeriodMixin.js` — injects `cnDashboardDateRange`,
  exposes a reactive `period`, and re-runs `load()` on range change (on top of
  the existing `dashboardRefreshMixin` refresh-signal behaviour).
- [x] 2.2 Add the four KPI widgets (`LeadConversionKpiWidget`,
  `AvgResolutionKpiWidget`, `ContactVolumeKpiWidget`, `SatisfactionKpiWidget`)
  rendering `CnStatsBlock` with the value formats and previous-period trend
  label carried over from the removed panel.
- [x] 2.3 Add the two chart widgets (`LeadsOverTimeChartWidget`,
  `RequestsByCategoryChartWidget`) rendering `CnChartWidget` without an
  in-body title.
- [x] 2.4 Delete `UnifiedAnalyticsWidget.vue`.

## 3. Manifest + registry

- [x] 3.1 Replace the `unified-analytics` widget in `src/manifest.json` with
  the six new widgets (defs, layout, slots) and shift the rows below.
- [x] 3.2 Enable `config.dateRange` on the Dashboard page (presets
  week/month/quarter/year as 7/30/90/365-day trailing windows, default
  `month`, persisted).
- [x] 3.3 Swap the registry entry for `UnifiedAnalyticsWidget` for the six new
  widget entries in `src/registry.js`.

## 4. Tests

- [x] 4.1 Vitest coverage for `rangeToPeriod` (preset mapping + custom-span
  fallback) in `tests/vitest/`.
- [x] 4.2 Gate-19 e2e coverage for the new/changed scenarios in
  `tests/e2e/spec-coverage/dashboard-analytics.spec.ts`.

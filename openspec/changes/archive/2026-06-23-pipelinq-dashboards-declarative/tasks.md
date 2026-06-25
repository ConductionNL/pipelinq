# Tasks

## 1. Server-side period resolution
- [x] 1.1 Add `ReportingController::resolvePeriodRange()` mapping `period`
  (today/week/month) → `[from, to]`; explicit `from`/`to` wins.
- [x] 1.2 Wire `getKpis` / `getChannels` / `getAgents` to use it.

## 2. Section components (kind:'section')
- [x] 2.1 `ChannelDistributionSection.vue` — channel bar chart + CSV, reads
  `@workspace.period`, self-fetches `/api/rapportage/channels`.
- [x] 2.2 `ChannelComparisonSection.vue` — per-channel comparison table, reads
  period + granularity.
- [x] 2.3 `AgentPerformanceSection.vue` — sortable leaderboard + team summary.
- [x] 2.4 `LeadAnalyticsSection.vue` — funnel + source + aging + win/loss from
  one `pipeline-stats` fetch.
- [x] 2.5 `SlaAttainmentBreakdownSection.vue` + the MDM section (pre-existing).
- [x] 2.6 Register each as `kind:'section'` in `registry.js`.

## 3. Manifest conversion
- [x] 3.1 `RapportageContactmomenten` → `type:"dashboard"` (4 endpoint stat
  KPIs + period pageFilter + channel bodyWidget).
- [x] 3.2 `ChannelAnalyticsView` → `type:"dashboard"` (period + granularity
  pageFilters + comparison bodyWidget).
- [x] 3.3 `AgentPerformanceView` → `type:"dashboard"` (period pageFilter +
  leaderboard bodyWidget).
- [x] 3.4 `Rapportage` (Lead analytics) → `type:"dashboard"` (lead-analytics
  bodyWidget).
- [x] 3.5 `SlaAttainment` + `MdmDataQuality` → `type:"dashboard"`.

## 4. Remove page-host views
- [x] 4.1 Delete `RapportageDashboard.vue`, `ChannelAnalytics.vue`,
  `AgentPerformance.vue`, `RapportageView.vue` + their `kind:'page'` entries.

## 5. Kept-custom recorded reasons
- [x] 5.1 `PipelineAnalytics`, `Forecast`/`ForecastTrend`, `LoyaltyReporting`,
  `BlastPerformance` carry a precise `_note` + missing primitive.

## 6. Verify
- [x] 6.1 `npm run build` green; vitest ≥ baseline (recurringRevenue orphan
  ignored); eslint clean on changed.
- [x] 6.2 Live-verify each converted dashboard on :8080 — KPI tiles populate,
  bespoke chart sections render, period filter re-queries, 0 new console errors.

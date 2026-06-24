## Why

Pipelinq still hosted nine analytics/reporting surfaces as host-app
`type:"custom"` views: bespoke KPI grids, hand-rendered charts and date-range
selectors that each carried their own fetch + layout code. The
`@conduction/nextcloud-vue` library now ships the primitives needed to express
these declaratively — endpoint-bound `stat` widgets (`source: { kind:
"endpoint", url, path, params }`), period/select `pageFilters` resolved into
`@page.*` / `@workspace.*` tokens, and **bodyWidgets on a dashboard**
(`config.bodyWidgets`, `placement`) that render a registered `kind:'section'`
host-app component in the dashboard body. That lets the headline KPIs become
declarative stat tiles while each genuinely bespoke chart/table moves into a
small in-body section, eliminating the page-host views.

## What Changes

- **MODIFIED** six reporting dashboards to declarative `type:"dashboard"`
  manifest pages (`src/manifest.json` + `src/manifest.d/`):
  - `SlaAttainment` (`/sla/attainment`) — 4 endpoint stat KPIs + period &
    group-by pageFilters + `SlaAttainmentBreakdownSection`.
  - `MdmDataQuality` (`/mdm/data-quality`) — endpoint stat KPIs + section.
  - `RapportageContactmomenten` (`/rapportage/contactmomenten`) — 4 endpoint
    stat KPIs reading `/api/rapportage/kpis` + period pageFilter +
    `ChannelDistributionSection`.
  - `ChannelAnalyticsView` (`/rapportage/channels`) — period + granularity
    pageFilters + `ChannelComparisonSection` (no headline KPIs).
  - `AgentPerformanceView` (`/rapportage/agents`) — period pageFilter +
    `AgentPerformanceSection` (no headline KPIs).
  - `Rapportage` / Lead analytics (`/rapportage`) — the four lead widgets
    (funnel, source table, aging donut, win/loss) hosted in one
    `LeadAnalyticsSection` that keeps the single `pipeline-stats` fetch.
- **MODIFIED** `ReportingController` to resolve a relative `period` token
  (today / week / month) into a from/to window server-side
  (`resolvePeriodRange()`), so the dashboards drive every KPI with one static
  `period` select and no client-side date math.
- **REMOVED** the page-host views and their `registry` `kind:'page'` entries
  (`RapportageDashboard.vue`, `ChannelAnalytics.vue`, `AgentPerformance.vue`,
  `RapportageView.vue`); the bespoke charts are re-registered as
  `kind:'section'` components.
- **KEPT custom with a recorded `_note`** the four surfaces whose interactivity
  needs a primitive the declarative dashboard does not yet offer:
  `PipelineAnalytics`, `Forecast` (+`ForecastTrend`), `LoyaltyReporting`,
  `BlastPerformance`.

This is a manifest + thin-section change in pipelinq (kind: code); the stat,
pageFilter and bodyWidget primitives are implemented in
`@conduction/nextcloud-vue`.

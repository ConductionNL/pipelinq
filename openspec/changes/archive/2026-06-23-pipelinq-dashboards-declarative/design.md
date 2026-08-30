# Design — per-dashboard mapping

How each of the nine analytics surfaces maps to the declarative dashboard
contract, what moved where, and (for the kept-custom four) the precise missing
primitive.

## Converted to `type:"dashboard"`

### SlaAttainment — `/sla/attainment`
- **KPIs → endpoint stat widgets**: Overall attainment (%), Total, Met,
  Breached, each `source: { kind:"endpoint", url:/api/sla/…, path }`.
- **pageFilters**: `period` (→ `@page.period`) + `groupBy` (policy / tier /
  target).
- **bodyWidget**: `SlaAttainmentBreakdownSection` (kind:section) renders the
  per-group breakdown table, reading `@workspace.period` + `@workspace.groupBy`.

### MdmDataQuality — `/mdm/data-quality`
- **KPIs → endpoint stat widgets** for the master-data quality scores.
- **bodyWidget**: the duplicate/issue breakdown section.

### RapportageContactmomenten (Contact reporting) — `/rapportage/contactmomenten`
- **KPIs → endpoint stat widgets** (4): Total Contacts, FCR %, Avg Handling
  Time, SLA Compliance — all read `GET /api/rapportage/kpis` at dot-paths
  `total` / `fcrRate` / `avgHandlingTime` / `slaCompliance`.
  `avgHandlingTime` is a pre-formatted `H:MM` string rendered verbatim (no
  `format`).
- **pageFilter**: `period` (today / week / month → `@page.period`).
- **bodyWidget**: `ChannelDistributionSection` (after-grid) — the hand-rendered
  per-channel bar chart + CSV export, reading `@workspace.period`, self-fetching
  `/api/rapportage/channels`.
- **Dropped**: the legacy 60s auto-refresh + "last updated" label (the page
  Refresh action covers manual reload).

### ChannelAnalyticsView (Channel analytics) — `/rapportage/channels`
- **No headline KPIs.**
- **pageFilters**: `period` + `granularity` (daily / weekly).
- **bodyWidget**: `ChannelComparisonSection` (before-grid) — the per-channel
  comparison table (total / FCR / SLA, colour-keyed), reading both
  `@workspace.period` + `@workspace.granularity`, self-fetching
  `/api/rapportage/channels` + `/api/rapportage/sla`.

### AgentPerformanceView (Agent performance) — `/rapportage/agents`
- **No headline KPIs.**
- **pageFilter**: `period`.
- **bodyWidget**: `AgentPerformanceSection` (before-grid) — the sortable
  per-agent leaderboard + team-summary footer (derived from the same agents
  map), reading `@workspace.period`, self-fetching `/api/rapportage/agents`.

### Rapportage (Lead analytics) — `/rapportage`
- **No headline KPIs** — the surface is four bespoke widgets fed from ONE
  `GET /api/rapportage/pipeline-stats` fetch, with interactivity INSIDE the
  widgets (funnel pipeline selector filters in memory; win/loss date-range
  selector re-fetches with `dateFrom`/`dateTo`).
- **bodyWidget**: one `LeadAnalyticsSection` (before-grid) hosting the funnel
  bar chart, source-performance table, lead-aging donut and win/loss pie + KPI
  block — preserving the legacy single-fetch + in-widget filtering verbatim.
- **Kept-as-note**: no page-level period pageFilter — the date window is
  derived inside the win/loss range selector (a from/to pair a static select
  cannot emit) and the pipeline selector's options are OR-sourced.

## Server-side period resolution

`ReportingController::resolvePeriodRange()` mirrors the legacy client-side
semantics so a static `period` select can drive the from/to query:
`today = [today, today]`, `week = [Monday-of-week, today]`,
`month = [1st-of-month, today]`. An explicit `from`/`to` always wins, so the
existing date-range pills still work.

## Kept custom — recorded reason + missing primitive

### PipelineAnalytics — `/pipeline-analytics`
- No summary endpoint (client-side aggregation over leads); two KPIs are ratios
  (Win Rate, Avg Deal Size); pipeline selector has dynamic OR-sourced options.
- **Missing**: derived/ratio stat source + OR-collection pageFilter options.

### Forecast / ForecastTrend — `/forecast`, `/forecast/trend`
- `periodId` is a client-derived current-quarter value pageFilters cannot emit;
  no summary endpoint (paginated rows summed client-side incl. projected math);
  Override-modal POST / at-risk / CSV side-effects.
- **Missing**: current-period token + derived stat source / summary endpoint.

### LoyaltyReporting — `/loyalty/reporting`
- Summary endpoint exists but `{programmeId}` is a path segment needing a
  dynamic OR-sourced programme selector; pageFilters are static-only.
- **Missing**: OR-collection pageFilter options + relative-window period token.

### BlastPerformance — `/blasts/performance`
- A three-tab post-send surface (Overview / A/B testing / Attribution): no
  headline KPIs; Tab 2 computes a 2x2 chi-square p-value client-side and gates
  the verdict on >=500 delivered + 24h elapsed; Tab 3 fans out N+1 per-blast
  attribution fetches.
- **Missing**: tab-container body primitive + client-side derived/statistical
  widget source + N+1 per-row enrichment fetch.

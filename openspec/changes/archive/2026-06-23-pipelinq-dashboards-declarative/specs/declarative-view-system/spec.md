# declarative-view-system — reporting dashboards

**Spec refs**: `declarative-view-system`

## ADDED Requirements

### Requirement: Reporting dashboards MUST render from declarative type:dashboard pages with endpoint stat widgets and a period filter

The system MUST render the Contact reporting, Channel analytics, Agent
performance, Lead analytics, SLA attainment and MDM data-quality surfaces from
declarative `type:"dashboard"` manifest pages rather than host-app
`type:"custom"` views. A dashboard's headline KPIs MUST be `stat` widgets whose
`source` is `{ kind:"endpoint", url, path, params }` reading an app REST
endpoint at a JSON dot-path, and a `pageFilters` period select MUST drive the
query via a `@page.period` token. Each bespoke chart/table that the stat grid
cannot express MUST be hosted in-body via `config.bodyWidgets` referencing a
registered host component of `kind:"section"` (reading the page filters via
`@workspace.*`). A converted page MUST NOT depend on its former host `.vue`
component or a `registry` `kind:"page"` entry.

#### Scenario: Contact reporting KPIs populate from the endpoint and re-query on period change

- GIVEN the user opens the Pipelinq app
- WHEN they navigate to the Contact reporting route `/rapportage/contactmomenten`
- THEN the page MUST render four KPI tiles (Total Contacts, FCR %, Avg Handling
  Time, SLA Compliance) populated from `GET /api/rapportage/kpis` at the dot-paths
  `total` / `fcrRate` / `avgHandlingTime` / `slaCompliance`
- AND a per-channel distribution chart with a CSV export MUST render in the body
  from the `ChannelDistributionSection` host component
- AND selecting a different period MUST re-query the endpoint with the new
  `period` and update every KPI and the channel section
- AND no `RapportageDashboard.vue` host component MUST be required

#### Scenario: Channel analytics renders a comparison table driven by period and granularity

- GIVEN the user opens the Pipelinq app
- WHEN they navigate to the Channel analytics route `/rapportage/channels`
- THEN the page MUST expose a period and a granularity `pageFilter`
- AND a per-channel comparison table (total / FCR / SLA, colour-keyed) MUST
  render in the body from the `ChannelComparisonSection` host component, reading
  both filters via `@workspace.period` and `@workspace.granularity`
- AND no `ChannelAnalytics.vue` host component MUST be required

#### Scenario: Agent performance renders a leaderboard driven by period

- GIVEN the user opens the Pipelinq app
- WHEN they navigate to the Agent performance route `/rapportage/agents`
- THEN a sortable per-agent leaderboard plus a team-summary footer MUST render
  in the body from the `AgentPerformanceSection` host component, reading
  `@workspace.period`
- AND no `AgentPerformance.vue` host component MUST be required

#### Scenario: Lead analytics renders the four lead widgets from one fetch

- GIVEN the user opens the Pipelinq app
- WHEN they navigate to the Lead analytics route `/rapportage`
- THEN the pipeline-funnel bar chart, source-performance table, lead-aging donut
  and win/loss pie MUST render in the body from one `LeadAnalyticsSection` host
  component fed by a single `GET /api/rapportage/pipeline-stats` fetch
- AND the win/loss date-range selector MUST re-fetch the section with the chosen
  `dateFrom`/`dateTo`
- AND no `RapportageView.vue` host component MUST be required

### Requirement: A relative period token MUST be resolved to a date window server-side

The system MUST resolve a relative `period` request parameter (`today` /
`week` / `month`) into a `from`/`to` window inside `ReportingController` so a
declarative dashboard can drive every KPI with a single static `period` select
and no client-side date math. An explicit `from`/`to` pair MUST take precedence
over `period`.

#### Scenario: period token resolves to a from/to window

- GIVEN a reporting endpoint call with `period=month` and no `from`/`to`
- WHEN `ReportingController` handles the request
- THEN it MUST query the window `[first-of-month, today]`
- AND a call with `period=week` MUST query `[Monday-of-this-week, today]`
- AND a call with an explicit `from`/`to` MUST use that pair unchanged

### Requirement: Reporting surfaces needing an unavailable declarative primitive MUST stay custom with a recorded reason

The system MUST keep the Pipeline analytics, Forecast (and Forecast trend),
Loyalty reporting and Blast performance surfaces as host-app `type:"custom"`
pages until the declarative dashboard primitive each needs exists, and MUST
record a precise `_note` on the page definition naming the missing primitive.

#### Scenario: kept-custom reporting pages carry a recorded reason

- GIVEN the Pipelinq manifest
- WHEN the Pipeline analytics, Forecast, Loyalty reporting and Blast performance
  pages are inspected
- THEN each MUST remain `type:"custom"`
- AND each MUST carry a `_note` naming the missing declarative primitive
  (e.g. derived/ratio stat source, OR-collection pageFilter options, a
  current-period token, a tab-container body primitive, or a client-side
  statistical widget source)

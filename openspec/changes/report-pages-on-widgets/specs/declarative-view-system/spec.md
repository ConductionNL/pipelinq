# Declarative view system Delta: report-pages-on-widgets

**Spec refs**: `declarative-view-system`

## MODIFIED Requirements

### Requirement: Reporting dashboards MUST render from declarative type:dashboard pages with endpoint stat widgets and a period filter

The system MUST render the Contact reporting, Channel analytics, Agent performance, Lead analytics, SLA attainment and
MDM data-quality surfaces from declarative `type:"dashboard"` manifest pages. Headline figures MUST be `stat` widgets on
an app endpoint, and charts and tables MUST be `chart` and `object-table` widgets on the row arrays the endpoints answer,
driven by the page filters and date range through `@workspace.*` tokens. A converted page MUST NOT depend on a pipelinq
section component.

#### Scenario: Contact reporting KPIs populate from the endpoint and re-query on period change

- GIVEN the user opens the Pipelinq app
- WHEN they navigate to the Contact reporting route `/rapportage/contactmomenten`
- THEN the page MUST render four KPI tiles populated from `GET /api/rapportage/kpis`
- AND a bar-list chart of the channel rows of `GET /api/rapportage/channels`, labelled in the reader's language
- AND an Export CSV header action that downloads the report of the chosen period
- AND selecting a different period MUST re-query both endpoints with the new `period`

#### Scenario: Channel analytics renders a comparison table driven by period

- GIVEN the user opens the Pipelinq app
- WHEN they navigate to the Channel analytics route `/rapportage/channels`
- THEN the page MUST expose a period `pageFilter`
- AND a per-channel table MUST show total, first-contact resolution and SLA attainment counted from the contact moments,
  with the SLA status as a badge in words

#### Scenario: Agent performance renders a leaderboard driven by period

- GIVEN the user opens the Pipelinq app
- WHEN they navigate to the Agent performance route `/rapportage/agents`
- THEN two stat tiles MUST show the team's contacts and first-contact resolution
- AND a table MUST list the agents by display name, with the handling time sorting as a number

#### Scenario: Lead analytics renders the lead widgets from one fetch

- GIVEN the user opens the Pipelinq app
- WHEN they navigate to the Lead analytics route `/rapportage`
- THEN the funnel, source table, aging donut, win/loss pie and win/loss figures MUST read their own part of one
  `GET /api/rapportage/pipeline-stats` answer, sent once for all of them
- AND the page date range MUST re-fetch it with `dateFrom`/`dateTo`, and the pipeline filter with `pipelineId`

## REMOVED Requirements

### Requirement: Analytics MUST render from a declarative type:dashboard page driven by an endpoint and a period filter

**Reason**: The `/analytics` page duplicated metrics already shown — correctly
period-scoped — on the Commercial (`/`) and Operational (`/operational`) overviews,
causing contradictory numbers (e.g. all-time-per-pipeline Win Rate on this page vs.
last-30-days Win Rate on Commercial). The page and its backing
`GET /api/analytics/summary` endpoint are removed in full; no unique metric is lost.

**Migration**: None — hard removal, no redirect. Users needing these KPIs use the
Commercial overview (`/`) for period-scoped pipeline value, requests, and lead
metrics, and the Operational overview (`/operational`) for the same KPI mixin family
already used elsewhere in the app.

The system MUST render the Analytics surface from a declarative
`type:"dashboard"` manifest page. The page MUST expose a `pageFilters` period
select and four `stat` widgets whose `source` is the
`GET /api/analytics/summary` endpoint, each reading its KPI at a JSON dot-path
and passing `params:{ period:"@page.period" }`. Changing the period MUST
re-query all four KPIs. No `AnalyticsDashboard.vue` host component MUST be
required.

#### Scenario: Analytics KPIs populate from the endpoint and re-query on period change

- GIVEN the user opens the Pipelinq app
- WHEN they navigate to the Analytics route `/analytics`
- THEN the page MUST render four KPI tiles (Open Pipeline Value, Open Requests,
  Contactmomenten, Active Leads) populated from `GET /api/analytics/summary`
- AND selecting a different period in the header filter MUST re-query the
  endpoint with the new `period` and update every KPI

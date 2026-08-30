# Proposal: Pipelinq views → declarative (round 2)

## Why

Round 1 (`pipelinq-views-to-declarative-r1`) converted the bucket-A list pages
and recorded five pages as **kept-with-reason** because each needed a
`@conduction/nextcloud-vue` declarative primitive that did not yet exist:

- **Services / Projects** — needed currency / duration cell formats and a
  create-to-detail action (`navigate` always passed `{ id: row[rowKey] }`, so it
  could not produce `{ id: "new" }`).
- **Resources** — needed the create-to-detail (`id:"new"`) navigate action.
- **BillingCategories** — needed a colour-swatch cell renderer, a DBA badge and
  a declarative multi-key default sort (type-then-name).
- **Analytics** — needed a stat widget bound to a custom REST endpoint plus a
  page-level filter that re-parametrizes every KPI.

nc-vue has since shipped exactly these primitives (currency/duration/swatch cell
`format`, literal navigate `params`, multi-key `defaultSort`, endpoint-bound
`stat` `source` + dashboard `pageFilters`). This change is **round 2**: convert
those five pages to declarative manifest pages and delete the host views +
their `registry` `kind:page` entries.

## What Changes

- **Converts** to declarative `type:"index"`, removing the host `.vue` view and
  its `registry` `kind:page` entry:
  - `Services` (`ServiceList`) — `price`→currency EUR, `durationMinutes`→duration,
    "New service" header action → `ServiceDetail` `id:"new"`.
  - `Projects` (`ProjectList`) — `budgetHours`/`budgetAmount` columns, billable
    indicator, ledger-sync badge, "New project" → `ProjectDetail` `id:"new"`.
  - `Resources` (`ResourceList`) — "New resource" → `ResourceDetail` `id:"new"`.
  - `BillingCategories` (`BillingCategoryList`) — `name`→swatch (colorField),
    DBA / active / default badge columns, `defaultSort` type-then-name.
- **Converts** to declarative `type:"dashboard"`:
  - `Analytics` (`AnalyticsDashboard`) — a `pageFilters` period select + 4
    endpoint-bound `stat` widgets reading `GET /api/analytics/summary` with
    `params:{ period:"@page.period" }`.

## Impact

- No feature, data-model or API change. Each page renders the same
  register/schema, the same columns/KPIs, and routes to the same
  detail/editor/endpoint as before.
- Reduces the pipelinq `type:"custom"` manifest-page count by five.

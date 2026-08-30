# Declarative View System — round 2 deltas

**Spec ref**: `declarative-view-system`

This delta is view-rendering placement only — no feature contract, data model,
or API change. Each converted page renders the same register/schema (or
endpoint), the same columns/KPIs, and routes to the same detail/editor as
before. It supersedes the round-1 "kept-with-reason" requirement for the five
pages below, now that the nc-vue primitives each needed have shipped.

## ADDED Requirements

### Requirement: Services, Resources and Projects MUST render from declarative type:index pages with create-to-detail actions

The system MUST render the Services, Resources and Projects list surfaces from
declarative `type:"index"` manifest pages rather than host-app `type:"custom"`
views. Each MUST drive its columns, value formatting and row navigation from
manifest `config`, MUST expose a "New" header action that navigates to the
matching detail route in create mode via a literal `params:{ id:"new" }`, and
MUST NOT depend on a host-app list `.vue` component or its `registry`
`kind:page` entry. The Services page MUST format `price` as EUR currency and
`durationMinutes` as a duration.

#### Scenario: Services renders as a declarative index with currency and duration

- GIVEN the user opens the Pipelinq app
- WHEN they navigate to the Services route `/services`
- THEN the page MUST render a table of `service` objects including a `price`
  column formatted as EUR currency and a `durationMinutes` column formatted as
  a duration
- AND a "New service" header action MUST navigate to the `ServiceDetail` route
  with `id:"new"` (the create form)
- AND clicking a row MUST navigate to the `ServiceDetail` route for that object
- AND no `ServiceList.vue` host component MUST be required to render the page

#### Scenario: Resources New action opens the create form

- GIVEN the user opens the Pipelinq app
- WHEN they navigate to the Resources route `/resources`
- THEN the page MUST render a table of `resource` objects
- AND a "New resource" header action MUST navigate to the `ResourceDetail`
  route with `id:"new"`

#### Scenario: Projects renders as a declarative index

- GIVEN the user opens the Pipelinq app
- WHEN they navigate to the Projects route `/projects`
- THEN the page MUST render a table of `project` objects including a
  `budgetAmount` column formatted as EUR currency and a billable indicator
- AND a "New project" header action MUST navigate to the `ProjectDetail` route
  with `id:"new"`

### Requirement: BillingCategories MUST render from a declarative type:index page with a colour swatch and default sort

The system MUST render the BillingCategories list surface from a declarative
`type:"index"` manifest page. The `name` column MUST render a colour swatch
read from the row's `color` field, a DBA badge column MUST be shown, and the
page MUST apply a declarative default multi-key sort by `type` then `name`.

#### Scenario: BillingCategories renders with swatch and type-then-name sort

- GIVEN the user opens the Pipelinq app
- WHEN they navigate to the BillingCategories route `/billing-categories`
- THEN the page MUST render a table of `billingCategory` objects whose `name`
  column shows a colour swatch from the `color` field
- AND a DBA badge column MUST be present
- AND rows MUST default-sort by `type` ascending then `name` ascending
- AND no `BillingCategoryList.vue` host component MUST be required

### Requirement: Analytics MUST render from a declarative type:dashboard page driven by an endpoint and a period filter

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

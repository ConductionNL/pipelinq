# declarative-view-system Specification

## Purpose
TBD - created by archiving change pipelinq-views-to-declarative-r1. Update Purpose after archive.
## Requirements
### Requirement: Convertible list pages MUST render from a declarative type:index manifest page

The system MUST render the PosTransactions, PosRefunds, Blasts, ZReports and
Bookings list surfaces from declarative `type:"index"` manifest pages rather
than host-app `type:"custom"` views. Each such page MUST drive its columns,
status-badge colours, date formatting and row navigation from manifest
`config` (declarative column renderers + `actions`/`headerActions` with
`handler:"navigate"`), and MUST NOT depend on a host-app list `.vue` component
or its `registry` `kind:page` entry.

#### Scenario: ZReports renders as a declarative index

- GIVEN the user opens the Pipelinq app
- WHEN they navigate to the Boekhoudkundige-Afhandeling route `/pos/z-reports`
- THEN the page MUST render a table of `posZReport` objects with the reference,
  date, terminal, transaction-count, total, status and created columns
- AND the status column MUST render as a coloured badge
- AND clicking a row's open action MUST navigate to the existing `ZReportDetail`
  route for that object
- AND no `ZReportList.vue` host component MUST be required to render the page

#### Scenario: Bookings renders as a view-only declarative index

- GIVEN the user opens the Pipelinq app
- WHEN they navigate to the Bookings route `/bookings`
- THEN the page MUST render a table of `booking` objects with a formatted
  date/time column and a coloured status badge
- AND no Add / create control MUST be shown (bookings are created via the
  public portal flow)
- AND clicking a row's open action MUST navigate to the existing `BookingDetail`
  route for that object

### Requirement: Pages needing an unavailable declarative primitive MUST stay custom with a recorded reason

The system MUST keep the Resources, Services, Projects, BillingCategories and
Analytics surfaces as `type:"custom"` manifest pages until the nc-vue
primitive each requires exists, and the manifest entry for each MUST record the
reason and the missing primitive in its `_note`. Functionality MUST NOT be
silently dropped to force a conversion.

#### Scenario: A page with a non-expressible renderer is not half-converted

- GIVEN a list page whose cells need a currency / colour-swatch renderer, a
  bespoke client-side sort, or a create-to-detail action that the declarative
  `navigate` handler cannot express
- WHEN the round-1 conversion is applied
- THEN that page MUST remain `type:"custom"` with its host component intact
- AND its manifest `_note` MUST state which nc-vue primitive is missing
- AND its existing behaviour (formatting, sort, create entry point) MUST be
  preserved unchanged

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

### Requirement: The Client 360 detail MUST render from a declarative type:detail page with in-body sections

The system MUST render the Client 360 surface from a declarative `type:"detail"`
manifest page rather than a host-app `type:"custom"` view. The page MUST render
the client's identity and account fields in the body via the default object data
widget, MUST surface cross-schema KPI chips via `summaryAggregates`, MUST render
the related contacts / leads / requests / projecten / contactmomenten /
complaints lists via `relatedCollections` (foreign key `client`) with row
navigation to each detail route, and MUST host the Relationships, Activity,
Communication History, Bookings and contactmoment quick-log sub-features IN THE
PAGE BODY via `bodyWidgets` (registered host components of `kind:"section"`).
The page MUST NOT depend on a `ClientDetail.vue` host component or a
`registry` `kind:page` entry.

#### Scenario: Client 360 renders chips, related lists and in-body sections

- GIVEN the user opens the Pipelinq app
- WHEN they navigate to a client detail route `/clients/{id}`
- THEN the page MUST render the client's identity and account fields in the body
- AND `summaryAggregates` header chips MUST show open-lead count and value,
  won-lead count and value, and a new-requests count scoped to the client
- AND `relatedCollections` sections MUST render the client's contacts, leads,
  requests, projecten, contactmomenten and complaints
- AND clicking a related row MUST navigate to that object's detail route
- AND the Relationships, Activity, Communication History, Bookings and
  contactmoment quick-log sub-features MUST render as titled sections in the page
  body (NOT the sidebar), each reading the current client object's context
- AND no `ClientDetail.vue` host component MUST be required to render the page

### Requirement: The Contact detail MUST render from a declarative type:detail page with a relation-link and in-body sections

The system MUST render the Contact surface from a declarative `type:"detail"`
manifest page rather than a host-app `type:"custom"` view. The page MUST render
the contact's role / email / phone / client fields in the body via the default
object data widget, MUST expose a parent-organisation relation-link action via
`relationLinks` (foreign key `client`) that patches the linked client, and MUST
host the BSN/BRP panel, Relationships and Communication History sub-features IN
THE PAGE BODY via `bodyWidgets` (registered host components of `kind:"section"`).
The page MUST NOT depend on a `ContactDetail.vue` host component or a `registry`
`kind:page` entry.

#### Scenario: Contact renders the relation-link and in-body sections

- GIVEN the user opens the Pipelinq app
- WHEN they navigate to a contact detail route `/contacts/{id}`
- THEN the page MUST render the contact's role / email / phone / client fields
- AND a "Link to Organisation" relation-link action MUST open a search-and-link
  modal that patches the contact's `client` foreign key
- AND the BSN/BRP panel, Relationships and Communication History sub-features
  MUST render as sections in the page body, each reading the current contact
  object's context
- AND no `ContactDetail.vue` host component MUST be required to render the page

### Requirement: Detail sub-features kept-with-reason MUST be recorded in the manifest

The system MUST record, in the manifest page `_note`, any feature of the former
host views that has no declarative primitive, rather than silently dropping it.
Specifically: the "Edit in Contacts" deep-link and the
delete-with-linked-entity warning have no declarative primitive; the
`summaryAggregates` equality-only filters express "Open leads" as `status:open`
and a single "New requests" chip; and the contactmoment quick-log / BRP panel no
longer auto-refresh on save (the page Refresh action re-runs the sections).

#### Scenario: Kept-with-reason items are documented

- GIVEN the declarative ClientDetail and ContactDetail manifest pages
- WHEN a reviewer reads the page `_note`
- THEN the note MUST state which former-view behaviours were kept-as-note and
  why (no declarative primitive / equality-only aggregation / no host re-fetch)


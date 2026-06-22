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


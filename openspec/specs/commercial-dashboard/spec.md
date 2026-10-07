---
status: done
---

# commercial-dashboard Specification

## Purpose
Provides a commercial overview dashboard as the default landing page, showing revenue, won value, win rate, average deal size, weighted forecast, and open pipeline value alongside trend charts and closing-soon/recently-won deal tables. Backed by an authenticated analytics endpoint and a seeded demo dataset, it splits commercial KPIs from the operational widgets, which move to a dedicated Operational overview.

## Requirements

### Requirement: Commercial overview KPI endpoint

`AnalyticsService::getCommercialOverview(period)` SHALL return, for
the trailing window of `period` (week/month/quarter/year): revenue
(settled POS turnover plus won-deal value closed in the window), won
value, win rate (won / (won+lost) closed in the window), average won
deal size, weighted forecast (sum over open leads of value ×
probability), and open pipeline value (sum of value over open
leads). It SHALL include a `previousPeriod` block with the windowed
figures for the preceding equal-length window. An unknown period
SHALL raise `InvalidArgumentException`.

#### Scenario: Commercial overview computes the six figures
@e2e exclude backend aggregation math, covered by PHPUnit CommercialAnalyticsServiceTest

- **GIVEN** leads with mixed status/value/probability and settled
  POS transactions in the window
- **WHEN** `getCommercialOverview('month')` is called
- **THEN** it returns revenue, wonValue, winRate, avgDealSize,
  weightedForecast and openPipelineValue plus a previousPeriod block

#### Scenario: Unknown period rejected
@e2e exclude backend input-validation, covered by PHPUnit

- **GIVEN** an unsupported period string
- **WHEN** `getCommercialOverview('decade')` is called
- **THEN** an InvalidArgumentException is raised

### Requirement: Commercial trend metrics

`AnalyticsService::getTrends(metric, period)` SHALL support four new
metrics in addition to the existing ones: `revenue` (time-bucketed
settled-POS turnover plus won-deal value), `pipeline-by-stage` (open-
lead value summed per pipeline stage, ordered by stage order),
`revenue-by-product-category` (POS line revenue grouped by the linked
product's category, unresolved lines under "Other"), and
`top-customers` (won-deal plus POS revenue grouped by client, the top
eight by value). Each SHALL return the established
`{ metric, period, series: [{ date, value }] }` envelope where
breakdown metrics carry the label in `date`.

#### Scenario: Pipeline-by-stage sums open-lead value per stage
@e2e exclude backend trend-builder math, covered by PHPUnit CommercialAnalyticsServiceTest

- **GIVEN** open leads across several pipeline stages
- **WHEN** `getTrends('pipeline-by-stage', 'quarter')` is called
- **THEN** the series has one entry per stage with the summed open
  value, ordered by stage order

#### Scenario: Revenue-by-category buckets unresolved lines under Other
@e2e exclude backend trend-builder math, covered by PHPUnit CommercialAnalyticsServiceTest

- **GIVEN** POS lines, some linked to categorised products and some
  with no resolvable category
- **WHEN** `getTrends('revenue-by-product-category', 'month')` is
  called
- **THEN** unresolved-line revenue is summed under an "Other" entry

### Requirement: Commercial analytics endpoint

The app SHALL expose `GET /api/analytics/commercial?period=…`
returning `getCommercialOverview`, available to any authenticated
user, returning a static error envelope (never the raw exception)
on invalid period (400) or OpenRegister outage (500).

#### Scenario: Endpoint returns the commercial overview
@e2e exclude REST contract, covered by PHPUnit + Newman

- **GIVEN** an authenticated user
- **WHEN** they GET `/api/analytics/commercial?period=month`
- **THEN** the JSON body carries the six commercial figures

### Requirement: Commercial dashboard is the default landing

The dashboard at route `/` SHALL be the Commercial overview: a six-
tile KPI strip (revenue, won value, win rate, average deal size,
weighted forecast, open pipeline value), the four commercial charts,
and the two deal tables. It SHALL inherit the dashboard date-range
and Refresh action. The date-range header SHALL render as a compact
pills control (`dateRange.control: "pills"`) — a segmented preset
toggle (Last 7 / 30 / 90 / 365 days) rather than a select plus two
date inputs. The KPI strip, charts, and tables SHALL be laid out
without a dead vertical gap: the charts SHALL sit directly below the
KPI rows. The currency-formatted KPIs SHALL format in the configured
reporting currency via the `@config.currency` token (default `EUR`).

#### Scenario: Commercial dashboard renders KPIs and charts

- **GIVEN** seeded commercial data
- **WHEN** the user opens `/`
- **THEN** the KPI strip shows six currency/percentage figures (currency
  figures in the configured reporting currency) and the
  revenue, pipeline-by-stage, product-category and top-customer
  charts render

#### Scenario: date range is a compact pills control

- **GIVEN** the Commercial overview
- **WHEN** the date-range header renders
- **THEN** it shows a segmented pill row of presets (Last 7 / 30 / 90
  / 365 days) with the active preset highlighted, and no "Range preset"
  select or bare `YYYY-MM-DD` date inputs are shown
- **AND** clicking a pill changes the dashboard's active date range

#### Scenario: charts sit directly below the KPI rows

- **GIVEN** the Commercial overview
- **WHEN** the dashboard renders
- **THEN** the chart row begins immediately after the KPI rows with
  no empty grid rows between them

### Requirement: Operational dashboard preserved

The app SHALL preserve every widget on the pre-change Dashboard
(open leads, open requests, pipeline value, overdue, lead conversion,
avg resolution, contact volume, satisfaction, leads-over-time,
requests-by-category, requests-by-status, complaints, my work,
client overview, billing categories, Navi, report export, knowledge
base) on a dedicated Operational overview dashboard reachable from
the navigation.

#### Scenario: Operational widgets reachable after the split

- **GIVEN** the dashboard split
- **WHEN** the user opens the Operational overview from the nav
- **THEN** all the previous dashboard widgets are present

### Requirement: Deals tables from cached leads

The "closing soon" and "recently won/lost" tables SHALL derive from
the client-side cached lead dataset (no dedicated endpoint): closing
soon lists open leads ordered by expected close date ascending;
recently won/lost lists won/lost leads ordered by close recency.

#### Scenario: Closing-soon lists open leads by close date
@e2e exclude client-side table ordering, covered by vitest commercialFormat.spec

- **GIVEN** open leads with various expected close dates
- **WHEN** the Commercial dashboard loads
- **THEN** the closing-soon table lists them earliest-due first

### Requirement: Commercial demo seed

The repository SHALL provide a committed, idempotent seed script that
populates a coherent commercial story (clients, staged pipeline with
leads of varying value/probability/status, product catalogue with
categories, POS transactions with product-linked lines settled across
the trailing year) so every Commercial dashboard widget renders non-
empty.

#### Scenario: Seed populates a meaningful commercial dashboard
@e2e exclude operator CLI tooling, exercised manually + by seeded fixtures

- **GIVEN** a running Nextcloud with pipelinq
- **WHEN** the operator runs `scripts/seed-demo-commercial.py`
- **THEN** every Commercial dashboard widget renders non-empty data

### Requirement: Dashboard currency KPIs format with the configured reporting currency

Every currency-formatted dashboard KPI (stat / gauge / endpoint-source tile whose
`format.style == "currency"`) MUST render its figure in the reporting currency the
setup wizard captured, NOT a hard-coded `EUR`. The reporting currency is the
pipelinq `currency` app-config key (`EUR` / `USD` / `GBP` / `CHF`), set by the
first-time setup wizard.

The currency MUST reach the dashboard via the `@conduction/nextcloud-vue`
`@config.<key>` token: each such tile's `format.currency` MUST be
`"@config.currency"`. The backend MUST expose the configured currency to the SPA as
a `config` initial state (`{ currency: <code> }`, default `EUR` when unset), and the
manifest renderer MUST seed it onto each dashboard page so `CnDashboardPage` provides
it as `cnAppConfig` to its stat widgets. When the key is unset the token MUST fall
back to its literal default (`EUR`).

Non-currency formats (number / percent) and `type: "index"` table-column currency
formats (which are rendered by `CnDataTable`, outside the dashboard `@config`
resolver) are out of scope and MUST NOT carry an unresolved `@config.*` token.

**Feature tier**: MVP

#### Scenario: USD-configured instance formats KPIs as dollars

- **GIVEN** the reporting currency app-config key is `USD`
- **WHEN** the user opens the Commercial overview dashboard
- **THEN** the Revenue, Won Value, Weighted Forecast, Recurring revenue and
  Pipeline coverage figures render with the `$` / USD currency symbol
- **AND** no figure renders with `€`

#### Scenario: EUR-configured instance formats KPIs as euros

- **GIVEN** the reporting currency app-config key is `EUR`
- **WHEN** the user opens the Commercial overview dashboard
- **THEN** the same KPIs render with the `€` / EUR currency symbol

#### Scenario: Unconfigured instance falls back to EUR

- **GIVEN** the reporting currency app-config key is unset (the setup wizard has not run)
- **WHEN** the user opens any dashboard with currency KPIs
- **THEN** the currency figures render with the `€` / EUR default
- **AND** no literal `@config.currency` string leaks into a KPI value

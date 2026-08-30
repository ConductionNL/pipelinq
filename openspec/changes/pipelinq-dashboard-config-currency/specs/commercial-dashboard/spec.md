# Commercial Dashboard — Config-driven currency formatting delta

**Spec refs**: `commercial-dashboard`, `first-time-setup` (reporting `currency` app-config key), ADR-036 (declarative manifest tiles)
**Standards**: ECMA-402 `Intl.NumberFormat` currency styles

## ADDED Requirements

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

## MODIFIED Requirements

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

# Commercial dashboard — date-range pills + compact layout

**Spec refs**: `commercial-dashboard`

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
KPI rows.

#### Scenario: Commercial dashboard renders KPIs and charts

- **GIVEN** seeded commercial data
- **WHEN** the user opens `/`
- **THEN** the KPI strip shows six EUR/percentage figures and the
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

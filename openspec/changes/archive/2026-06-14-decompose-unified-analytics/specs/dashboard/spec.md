## MODIFIED Requirements

---

### Requirement: Unified Analytics Dashboard Widgets (REQ-DASH-010)

The dashboard MUST surface the cross-module analytics KPIs and trend charts as
individual dashboard grid widgets, composed exactly like the rest of the
dashboard: KPI cards as `showTitle: false` stat widgets, charts as titled
widgets whose title is rendered by the widget chrome (never by an in-body
heading), and the reporting period driven by the dashboard-level date-range
header — never by a widget-local period selector.

**Feature tier**: V1
**Backend**: `AnalyticsController` + `AnalyticsService` (unchanged, REQ-DASH-011)
**Frontend**: `LeadConversionKpiWidget.vue`, `AvgResolutionKpiWidget.vue`,
`ContactVolumeKpiWidget.vue`, `SatisfactionKpiWidget.vue`,
`LeadsOverTimeChartWidget.vue`, `RequestsByCategoryChartWidget.vue`

#### Scenario: Cross-module KPI cards as individual dashboard widgets

- GIVEN the user navigates to the dashboard
- WHEN the analytics KPI widgets load
- THEN the dashboard MUST display four individual KPI widgets using
  `CnStatsBlock`, in a row below the operational KPI row:
  - **Lead Conversion Rate** — percentage of leads with `status: "won"` over total leads in the selected period
  - **Avg Request Resolution** — mean duration between `requestedAt` and `completedAt` for resolved requests
  - **Contact Moment Volume** — count of `contactmoment` objects in the selected period
  - **Customer Satisfaction** — mean score from `surveyResponse` objects in the selected period (or "N/A" if none)
- AND each KPI MUST display a trend indicator (up/down arrow) comparing to the previous equal period
- AND the four widgets MUST share a single cached `GET /api/analytics/overview`
  response per period (no duplicate requests on one render pass)
- AND no widget may render an in-body panel heading (the grid chrome owns titles)

#### Scenario: Period driven by widget-header date chips

- WHEN the user views the dashboard
- THEN the trend chart widgets MUST surface the shared dashboard date range as
  a date chip in their own widget title bars (`layout[].dateChip: true` on the
  `CnDashboardPage` `dateRange` mechanism), offering the four
  backend-supported trailing windows: last 7 / 30 / 90 / 365 days
- AND the page-level date-range header picker MUST NOT render
  (`dateRange.showHeaderPicker: false`) — the chips are the only visible
  range control
- AND the chips MUST read and write the SHARED dashboard range so picking a
  preset in either chip updates every analytics widget
- AND the analytics widgets MUST consume the provided `cnDashboardDateRange`
  and map it to the analytics API `period` parameter
  (`week` / `month` / `quarter` / `year`)
- AND changing the range MUST re-fetch the analytics endpoints and update all
  analytics widgets
- AND no analytics widget may render its own period selector in its body

#### Scenario: Trend chart — leads over time

- WHEN the leads-over-time chart widget renders
- THEN a line chart MUST display lead count over time using `CnChartWidget`
- AND the X-axis MUST represent time intervals appropriate to the selected period (days for week/month, weeks for quarter, months for year)
- AND the chart data MUST come from `GET /api/analytics/trends?metric=leads&period={period}`

#### Scenario: Trend chart — requests by category

- WHEN the requests-by-category chart widget renders
- THEN a bar chart MUST display request counts grouped by `category` using `CnChartWidget`
- AND the chart data MUST come from `GET /api/analytics/trends?metric=requests-by-category&period={period}`
- AND categories with zero requests in the period MUST be excluded from the chart

#### Scenario: Analytics widget registration

- WHEN the dashboard initializes
- THEN the analytics widgets MUST be registered as individual widget ids
  (`lead-conversion`, `avg-resolution`, `contact-volume`, `satisfaction`,
  `leads-over-time`, `requests-by-category`) in the Dashboard manifest page
- AND the four KPI widgets MUST be 3 columns wide with `showTitle: false`
- AND the two chart widgets MUST be 6 columns wide with chrome-rendered titles
- AND no `unified-analytics` widget may remain in the manifest, registry, or slots

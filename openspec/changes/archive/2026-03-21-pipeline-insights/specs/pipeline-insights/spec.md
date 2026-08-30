---
status: draft
---

# Pipeline Insights Specification

## Purpose

Add temporal, financial, and analytical context to pipeline views so users can spot bottlenecks, prioritize overdue items, forecast revenue, and see conversion metrics at a glance. This spec covers both the real-time visual indicators on pipeline views and the analytical dashboard components for pipeline performance tracking.

**Feature tier:** V1 (visual indicators), Enterprise (analytics and forecasting)

**Competitor context:** EspoCRM provides pipeline analytics via its Reports add-on ($95/year) with funnel charts, won/lost analysis, and forecast reports. Twenty CRM has basic dashboards (6 widget types, beta) without pipeline-specific analytics. Krayin CRM provides pipeline probability fields on stages but no built-in analytics dashboard. This spec positions Pipelinq to match EspoCRM's analytics while being included in the base product.

---

## ADDED Requirements

### Requirement: Stage Revenue Summary [V1]

Each kanban column header MUST display the total EUR value of leads in that stage.

#### Scenario: Stage header shows total value
- GIVEN a pipeline stage contains leads with value fields
- WHEN the kanban board is displayed
- THEN each stage column header MUST show the summed value formatted using the pipeline's `totalsLabel` setting (e.g., "EUR X.XXX")
- AND stages with zero total value MUST show "EUR 0" (or equivalent with configured label)

#### Scenario: Requests do not contribute to stage value
- GIVEN a mixed pipeline has both leads and requests in a stage
- WHEN the stage revenue is calculated
- THEN only entities with a configured `totalsProperty` in `propertyMappings` MUST be summed
- AND entities without a totalsProperty (e.g., requests) MUST contribute EUR 0

#### Scenario: List view shows value column
- GIVEN the pipeline is in list view mode
- THEN the Value column MUST be present and show individual item values
- AND leads without a value MUST show an em-dash
- AND the value MUST be sortable by clicking the column header

#### Scenario: Value includes product-calculated totals
- GIVEN a lead with a value auto-calculated from LeadProduct line items
- WHEN the stage revenue is computed
- THEN the lead's current value (whether manual or product-calculated) MUST be included in the stage total

---

### Requirement: Stale Lead Detection [V1]

Leads that have not been modified for 14 or more days MUST be visually flagged as stale.

#### Scenario: Stale badge on kanban card
- GIVEN a lead's `_dateModified` is 14 or more days ago
- WHEN the kanban board is displayed
- THEN the lead card MUST show a "Stale" badge with an amber/orange color
- AND the badge text MUST be "Stale"

#### Scenario: Stale badge in list view
- GIVEN a lead is stale (14+ days since modification)
- WHEN the list view is displayed
- THEN the lead row MUST show a "Stale" badge inline with the title

#### Scenario: Non-stale items show no badge
- GIVEN a lead was modified less than 14 days ago
- THEN no stale indicator MUST be shown

#### Scenario: Only leads can be stale
- GIVEN a request has not been modified for 14+ days
- THEN no stale badge MUST be shown (stale detection is lead-only, enforced by `isStale()` in `pipelineUtils.js`)

#### Scenario: Stale threshold configurability
- GIVEN the admin settings for Pipelinq
- THEN the stale threshold (default: 14 days) SHOULD be configurable per pipeline
- AND changing the threshold MUST immediately affect which leads appear as stale

---

### Requirement: Aging Indicator [V1]

Pipeline cards MUST show how many days the item has been in its current stage (approximated by days since last modification).

#### Scenario: Days-in-stage badge on kanban card
- GIVEN an item is on the pipeline board
- WHEN the card is displayed
- THEN a days indicator MUST show using the format:
  - Modified today: "Today"
  - Modified 1 day ago: "1d"
  - Modified N days ago: "Nd"

#### Scenario: Aging in list view
- GIVEN the pipeline is in list view mode
- THEN an "Age" column MUST be present showing days since modification
- AND the column MUST be sortable

#### Scenario: Aging color coding
- GIVEN an item has been in stage for 7+ days
- THEN the aging indicator MUST use the `aging-warning` class (amber styling)
- AND items in stage for 14+ days MUST use the `aging-alert` class (red styling)
- AND items younger than 7 days MUST use the default neutral styling

#### Scenario: Aging color coding accessibility
- GIVEN aging indicators use color to convey urgency
- THEN the indicator MUST also include text (the day count) to meet WCAG AA requirements
- AND color alone MUST NOT be the sole means of conveying aging status

---

### Requirement: Overdue Item Highlighting [V1]

Overdue items MUST be visually prominent across all views.

#### Scenario: Overdue card styling on kanban board
- GIVEN a lead's `expectedCloseDate` has passed, or a request's `requestedAt` is more than 30 days ago
- WHEN the item is displayed on the kanban board
- THEN the card MUST have a red left border (via `pipeline-card--overdue` class)
- AND the date MUST be shown in red (via `card-date--overdue` class)

#### Scenario: Overdue highlighting in list view
- GIVEN an item is overdue in list view
- THEN the row MUST have a subtle red background tint (via `list-row--overdue` class)
- AND the due date cell MUST be shown in red text (via `overdue-date` class)

#### Scenario: Overdue highlighting in My Work
- GIVEN the My Work view groups items by due status
- THEN overdue items MUST appear in the "Overdue" group at the top (sorted first)
- AND overdue items MUST have red date text
- AND the overdue count MUST be shown in the dashboard KPI widget

#### Scenario: Closed/terminal items are not overdue
- GIVEN a lead is in a closed pipeline stage (where `stage.isClosed === true`)
- THEN the item MUST NOT be shown as overdue regardless of dates
- AND requests with terminal status (completed, rejected, converted) MUST NOT be shown as overdue

#### Scenario: Overdue request calculation
- GIVEN a request with `requestedAt` field
- WHEN the current date is more than 30 days after `requestedAt`
- AND the request status is "new" or "in_progress"
- THEN the request MUST be flagged as overdue

---

### Requirement: Pipeline Conversion Analytics [Enterprise]

The system MUST provide stage-by-stage conversion rate metrics to identify pipeline bottlenecks.

#### Scenario: Stage conversion funnel
- GIVEN a pipeline with stages: Prospect -> Qualified -> Proposal -> Won
- WHEN the user views the pipeline analytics
- THEN a conversion funnel MUST display:
  - Total leads that entered each stage
  - Conversion rate from each stage to the next (e.g., Prospect -> Qualified: 65%)
  - Drop-off count at each stage
- AND the funnel MUST be filterable by date range (this month, this quarter, this year, custom)

#### Scenario: Stage average duration
- GIVEN leads moving through pipeline stages
- WHEN the analytics view calculates stage metrics
- THEN each stage MUST show the average number of days leads spend in that stage
- AND stages where the average exceeds the aging warning threshold (7 days) MUST be highlighted

#### Scenario: Win/loss analysis
- GIVEN a pipeline with Won and Lost closed stages
- WHEN the analytics view is loaded
- THEN the system MUST display:
  - Total won count and value (this period)
  - Total lost count and value
  - Win rate percentage: won / (won + lost) * 100
  - Average deal size (won value / won count)
- AND the metrics MUST be comparable across time periods (this month vs last month)

#### Scenario: Bottleneck detection
- GIVEN stage conversion rates are calculated
- WHEN a stage has a conversion rate below 30%
- THEN the system SHOULD flag this stage as a potential bottleneck
- AND the stage SHOULD be visually highlighted in the funnel chart
- AND a tooltip SHOULD explain: "Only X% of leads progress past this stage"

#### Scenario: Empty pipeline analytics
- GIVEN a pipeline with no leads (or no historical data)
- WHEN the analytics view is loaded
- THEN a friendly empty state MUST be shown: "Not enough data to show analytics yet"
- AND the system MUST NOT show misleading zero-percent or NaN values

---

### Requirement: Revenue Forecasting [Enterprise]

The system MUST provide weighted revenue forecasting based on pipeline stage probabilities.

#### Scenario: Weighted pipeline value
- GIVEN a pipeline where stages have probability values:
  - Prospect: 10%, Qualified: 25%, Proposal: 50%, Negotiation: 75%, Won: 100%
- WHEN the forecast widget is displayed
- THEN the weighted pipeline value MUST be calculated as:
  - Sum of (lead.value * stage.probability / 100) for all open leads
- AND the forecast MUST show both the unweighted total and the weighted (expected) total

#### Scenario: Monthly revenue projection
- GIVEN leads with `expectedCloseDate` values distributed across future months
- WHEN the forecast view is loaded
- THEN the system MUST display projected revenue per month
- AND each month MUST show: number of expected closes, total value, weighted value
- AND past months MUST show actual closed-won revenue for comparison

#### Scenario: Forecast accuracy tracking
- GIVEN previous months' forecasts and actual results
- WHEN the analytics view is loaded
- THEN the system SHOULD display forecast accuracy: (actual won / forecasted) * 100%
- AND trends in accuracy SHOULD be visible over time

#### Scenario: Pipeline stage probability configuration
- GIVEN the pipeline settings sidebar
- WHEN the admin configures a pipeline's stages
- THEN each stage MUST have an optional probability field (0-100%)
- AND the probability MUST be used in weighted revenue calculations
- AND stages without a probability set MUST default to 50%

---

### Requirement: Pipeline Dashboard Widgets [Enterprise]

The system MUST provide dashboard widgets for pipeline performance metrics.

#### Scenario: Pipeline value KPI widget
- GIVEN the existing dashboard with CnStatsBlock widgets
- THEN the "Pipeline Value" widget MUST show the total unweighted value of all open leads
- AND clicking it MUST navigate to the Pipeline view

#### Scenario: Won deals trend widget
- GIVEN the dashboard
- THEN a "Won This Month" widget SHOULD be available
- AND it MUST show the count and total value of leads moved to Won stage this month
- AND it SHOULD include a trend indicator (up/down vs previous month)

#### Scenario: Forecast widget
- GIVEN weighted pipeline forecasting is configured
- THEN a "Revenue Forecast" widget SHOULD be available on the dashboard
- AND it MUST show the weighted expected revenue for the current quarter
- AND clicking it MUST navigate to the pipeline analytics view

#### Scenario: Sales velocity widget
- GIVEN sufficient historical data (at least 10 won deals)
- THEN a "Sales Velocity" widget SHOULD be available
- AND it MUST calculate: (number of deals * average deal value * win rate) / average sales cycle length
- AND the metric MUST be expressed as EUR/day

#### Scenario: Top performers widget
- GIVEN leads are assigned to multiple users
- THEN a "Top Performers" widget SHOULD be available showing:
  - User name
  - Won deals count and value
  - Current pipeline value
- AND the widget MUST show the top 5 users ranked by won value

---

### Requirement: Pipeline Activity Timeline [V1]

The pipeline view MUST provide access to a chronological activity feed showing all pipeline-related actions.

#### Scenario: Recent pipeline activity
- GIVEN the pipeline board view
- WHEN the user opens the pipeline sidebar or activity tab
- THEN a chronological list of recent pipeline activities MUST be displayed:
  - Lead created
  - Lead moved to stage X
  - Lead won/lost
  - Lead assigned to user
- AND each activity MUST show: timestamp, user, action description

#### Scenario: Stage change history for a lead
- GIVEN a lead has moved through stages: Prospect -> Qualified -> Proposal -> Qualified (moved back)
- WHEN the user views the lead's activity in the sidebar
- THEN all stage transitions MUST be visible in chronological order
- AND moving backwards MUST be clearly indicated

#### Scenario: Activity filtering by entity type
- GIVEN the pipeline shows both leads and requests
- WHEN the user views the activity feed
- THEN the user MUST be able to filter activities by entity type (lead, request, all)

---

### Requirement: Export and Reporting [Enterprise]

The system MUST support exporting pipeline data for external reporting.

#### Scenario: Export pipeline data to CSV
- GIVEN the pipeline list view with sorted/filtered data
- WHEN the user clicks "Exporteren"
- THEN a CSV file MUST be generated containing all visible columns
- AND the file MUST include: title, stage, assignee, value, due date, priority, age
- AND the file MUST use UTF-8 encoding with BOM for Excel compatibility

#### Scenario: Export analytics report
- GIVEN the pipeline analytics view with conversion metrics
- WHEN the user clicks "Rapport exporteren"
- THEN a PDF or CSV report MUST be generated containing:
  - Conversion funnel data
  - Win/loss metrics
  - Forecast summary
  - Date range of the report

#### Scenario: Scheduled report delivery
- GIVEN the admin settings
- WHEN the admin configures a weekly pipeline report
- THEN the system SHOULD generate the report automatically
- AND the report SHOULD be stored in Nextcloud Files and/or sent via Activity email digest

---

### Requirement: Pipeline Comparison [Enterprise]

The system MUST support comparing performance across multiple pipelines.

#### Scenario: Multi-pipeline overview
- GIVEN the organization has 3 pipelines: "Gemeenten", "Provincies", "Waterschappen"
- WHEN the user views the analytics overview
- THEN a summary table MUST show per pipeline:
  - Total leads, total value, weighted forecast
  - Win rate, average deal size
  - Average cycle time

#### Scenario: Pipeline switching in analytics
- GIVEN the analytics view
- WHEN the user selects a different pipeline from the dropdown
- THEN all analytics metrics MUST update to reflect the selected pipeline
- AND the pipeline selector MUST match the one used in the kanban board view

---

## Current Implementation Status

**Implemented:**
- **Stage Revenue Summary:** Fully implemented in `PipelineBoard.vue`. Column headers show `getStageTotalValue()` which sums `totalsProperty` values per stage. List view includes a Value column with sorting. Uses `propertyMappings` for multi-schema support.
- **Stale Lead Detection:** Fully implemented via `pipelineUtils.js`:
  - `isStale(item, entityType)` returns true only for leads with `_dateModified` 14+ days ago.
  - Stale badge shown on kanban cards and in list view (amber "Stale" badge).
  - Only leads can be stale (requests return false).
- **Aging Indicator:** Fully implemented:
  - `getDaysAge(item)` calculates days since `_dateModified`.
  - `formatAge(days)` returns "Today", "1d", or "Xd" format.
  - `getAgingClass(days)` returns `aging-warning` (7+) or `aging-alert` (14+).
  - Shown on kanban cards and in list view "Age" column with sorting.
- **Overdue Item Highlighting:** Fully implemented:
  - Kanban: red left border via `pipeline-card--overdue`, red date via `card-date--overdue`.
  - List view: `list-row--overdue` background tint, `overdue-date` red text.
  - Dashboard: overdue items counted in KPI widget, shown in My Work with overdue items sorted first.
  - Lead overdue: `expectedCloseDate < today`.
  - Request overdue: `requestedAt` more than 30 days ago and status is new/in_progress.
- **Pipeline Value KPI:** Dashboard shows total pipeline value via `CnStatsBlock`.
- **MetricsRepository:** `lib/Service/MetricsRepository.php` provides database-level lead count, lead value, and request count queries for Prometheus metrics.

**Not yet implemented:**
- **Stale threshold configurability:** Hardcoded to 14 days in `pipelineUtils.js`.
- **Closed item overdue exclusion on kanban:** `isItemOverdue()` in `PipelineBoard.vue` does not check `stage.isClosed`.
- **Pipeline Conversion Analytics:** No funnel visualization, stage conversion rates, or win/loss analysis.
- **Revenue Forecasting:** No weighted pipeline value calculation. No stage probability fields.
- **Won deals trend widget, Sales velocity widget, Top performers widget:** Not implemented.
- **Pipeline Activity Timeline in sidebar:** Activity events exist but no timeline UI in pipeline view.
- **Export and Reporting:** No CSV/PDF export from pipeline views.
- **Pipeline Comparison:** No multi-pipeline analytics overview.

**Partial implementations:**
- Aging uses `_dateModified` as proxy for stage duration (matches spec but may not reflect true stage entry date).
- Dashboard has basic KPI widgets (open leads, open requests, pipeline value, overdue count) but no trend/analytics widgets.

### Standards & References
- WCAG AA: Color coding supplemented with text labels (badge text, day counts).
- CRM analytics patterns from EspoCRM (funnel, win/loss, forecast).
- Prometheus metrics endpoint at `lib/Controller/MetricsController.php` for operational monitoring.

### Specificity Assessment
- V1 requirements (visual indicators) are well-implemented and specific.
- Enterprise requirements (analytics, forecasting) need new components and possibly backend analytics endpoints.
- **Resolved:** Stale threshold should be configurable (new scenario added).
- **Resolved:** Closed items should be excluded from overdue on kanban (gap identified).
- **Open question:** Should aging track actual stage entry date (requires new field) or continue using `_dateModified`?
- **Open question:** Should conversion analytics use OpenRegister audit logs or a separate events table for historical tracking?

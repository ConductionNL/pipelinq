---
status: done
---

# Dashboard Specification

## Purpose

The Pipelinq CRM dashboard provides an at-a-glance overview of key performance indicators, pipeline health, assigned work, and client activity. It uses the `CnDashboardPage` component from `@conduction/nextcloud-vue` for a configurable grid layout and integrates with the Nextcloud Dashboard Widget API (`OCP\Dashboard\IWidget`) for platform-level widget exposure.

---
## Requirements
### Requirement: CRM Dashboard Layout

The dashboard MUST use the `CnDashboardPage` component to render a configurable widget grid with a 12-column layout system.

#### Scenario: Default grid layout on first load
- GIVEN the user has not customized their dashboard layout
- WHEN the user navigates to the dashboard
- THEN the layout MUST render with the default configuration:
  - Row 1: Four KPI cards (3 columns each, `gridHeight: 2`) spanning the full 12-column width
  - Row 2: "Requests by Status" chart (6 columns) and "My Work" widget (6 columns) side by side
  - Row 3: "Client Overview" widget spanning full width (12 columns)
- AND each widget MUST be rendered inside a `CnDashboardPage` widget slot (`#widget-{widgetId}`)

#### Scenario: Dashboard page title and empty state
- WHEN the dashboard loads
- THEN the page title MUST be "Dashboard" (translatable via `t('pipelinq', 'Dashboard')`)
- AND if no data exists (no leads, requests, or clients), a welcome message MUST be displayed: "Welcome to Pipelinq! Get started by creating your first client, lead, or request using the buttons above."

#### Scenario: Quick action buttons in header
- WHEN the user views the dashboard
- THEN the header MUST contain three quick action buttons: "New Lead" (primary style), "New Request", and "New Client"
- AND clicking each button MUST open the corresponding create dialog (`LeadCreateDialog`, `RequestCreateDialog`, `ClientCreateDialog`)
- AND upon successful creation, the user MUST be navigated to the detail view of the created entity

#### Scenario: Error state with retry
@e2e exclude backend API error injection; covered by unit tests
- GIVEN a network error occurs during dashboard data fetching
- WHEN the dashboard fails to load
- THEN an error message MUST be displayed with a "Retry" button
- AND clicking "Retry" MUST re-invoke the full data fetch

---

### Requirement: KPI Cards Row

The dashboard MUST display a row of four KPI summary cards at the top of the page using `CnStatsBlock` components, providing headline metrics at a glance. Each card MUST suppress its title bar (`showTitle: false`) and render in horizontal orientation.

#### Scenario: Display open leads count
@e2e exclude OR API aggregation; no stable test data
- WHEN the user views the dashboard
- THEN the "Open Leads" KPI card MUST display the count of leads whose `stage` name does NOT appear in any pipeline stage where `isClosed = true`
- AND the card MUST use the `TrendingUp` icon with `variant="primary"`
- AND clicking the card MUST navigate to the Leads view filtered by `status=open`

#### Scenario: Display open requests count
@e2e exclude OR API aggregation; no stable test data
- WHEN the user views the dashboard
- THEN the "Open Requests" KPI card MUST display the count of requests with `status` equal to `new` or `in_progress`
- AND the card MUST use the `FileDocument` icon with `variant="primary"`
- AND clicking the card MUST navigate to the Requests view filtered by `status=open`

#### Scenario: Display pipeline total value
@e2e exclude OR API aggregation; no stable test data
- WHEN the user views the dashboard
- THEN the "Pipeline Value" KPI card MUST display the sum of `value` for all leads in non-closed stages
- AND the value MUST be formatted as EUR currency with Dutch locale (e.g., "EUR 125.200")
- AND the card MUST use the `CurrencyEur` icon with `variant="success"`
- AND clicking the card MUST navigate to the Pipeline view

#### Scenario: Display overdue items count
@e2e exclude OR API aggregation; no stable test data
- WHEN the user views the dashboard
- THEN the "Overdue" KPI card MUST display the combined count of:
  - Leads in non-closed stages with `expectedCloseDate` in the past
  - Requests with status `new` or `in_progress` where `requestedAt` is older than 30 days
- AND the card MUST use the `AlertCircle` icon
- AND if the overdue count is greater than 0, the card MUST use `variant="error"` (red accent); otherwise `variant="default"`
- AND clicking the card MUST navigate to the Leads view filtered by `overdue=true`

#### Scenario: KPI cards with zero values
- GIVEN no data exists (fresh installation)
- WHEN the user views the dashboard
- THEN all KPI cards MUST display `0` (not blank, not an error, not a loading state)

---

### Requirement: Requests by Status Chart

The dashboard MUST display a horizontal bar chart showing the distribution of requests across status values.

#### Scenario: Render status distribution bars
@e2e exclude chart data depends on OR API
- GIVEN requests exist in the system
- WHEN the user views the "Requests by Status" widget
- THEN a horizontal bar chart MUST render one row per status that has at least one request
- AND the statuses MUST be drawn from: `new`, `in_progress`, `completed`, `rejected`, `converted`
- AND each row MUST display: a label (from `getStatusLabel`), a proportionally filled bar (color from `getStatusColor`), and a count number
- AND the bar width MUST be calculated as a percentage relative to the maximum count across all statuses

#### Scenario: No requests exist
- GIVEN no requests exist in the system
- WHEN the user views the "Requests by Status" widget
- THEN the widget MUST display the message "No requests yet" centered in the widget area

---

### Requirement: My Work Widget

The dashboard MUST display a "My Work" widget showing items assigned to the current user, sorted by urgency.

#### Scenario: Display assigned items
@e2e exclude depends on test data
- GIVEN leads and/or requests are assigned to the current user
- WHEN the user views the "My Work" widget
- THEN the widget MUST display up to 5 items (leads and requests combined)
- AND each item MUST show: an entity badge ("LEAD" or "REQ" with distinct colors), title, stage or status, and due date (formatted as `month day` in Dutch locale)
- AND items MUST be sorted by: overdue items first, then by priority order (`urgent` > `high` > `normal` > `low`), then by due date ascending

#### Scenario: Overdue item highlighting
@e2e exclude depends on test data with due dates
- GIVEN an assigned lead has `expectedCloseDate` in the past, or an assigned request has `requestedAt` older than 30 days with status `new` or `in_progress`
- WHEN the item appears in the "My Work" widget
- THEN the item row MUST have a red-tinted background (`my-work-item--overdue`)
- AND the due date MUST be displayed in red with bold font weight

#### Scenario: View all link for overflow
@e2e exclude depends on data volume
- GIVEN the user has more than 5 assigned items
- WHEN the user views the "My Work" widget
- THEN a "View all ({count})" link MUST appear below the list
- AND clicking it MUST navigate to the MyWork view

#### Scenario: No assigned items
- GIVEN no items are assigned to the current user
- WHEN the user views the "My Work" widget
- THEN the widget MUST display the message "No items assigned to you"

---

### Requirement: Client Overview Widget

The dashboard MUST display a "Client Overview" widget showing the most recent clients.

#### Scenario: Display recent clients
- GIVEN clients exist in the system
- WHEN the user views the "Client Overview" widget
- THEN the widget MUST display up to 5 clients (the most recent)
- AND each client MUST show: the client name (falling back to `title` or "Unnamed") and supplementary info (email and city joined with " . ")
- AND clicking a client row MUST navigate to the `ClientDetail` view for that client

#### Scenario: View all clients link
- GIVEN more than 5 clients exist
- WHEN the user views the "Client Overview" widget
- THEN a "View all clients ({count})" link MUST appear below the list
- AND clicking it MUST navigate to the `ClientList` view

#### Scenario: No clients exist
@e2e exclude depends on clean data state
- GIVEN no clients exist in the system
- WHEN the user views the "Client Overview" widget
- THEN the widget MUST display the message "No clients yet"

---

### Requirement: Product Revenue KPI Card

The dashboard MUST display a "Top Products by Pipeline Value" widget showing the top products by aggregated pipeline revenue from `LeadProduct` line items.

#### Scenario: Revenue by product display
@e2e exclude depends on OR product data
- GIVEN leads exist with `LeadProduct` line items linked to products
- WHEN the user views the dashboard
- THEN a "Top Products" widget MUST display the top 3 products ranked by total pipeline value (sum of `total` from line items)
- AND each product MUST show: product name, number of associated leads, and total value formatted as EUR currency
- AND products with higher total value MUST appear first

#### Scenario: No products in pipeline
@e2e exclude depends on data state
- GIVEN no leads have `LeadProduct` line items, or `leadProduct`/`product` schemas are not configured
- WHEN the user views the dashboard
- THEN the "Top Products" widget MUST display "No product data yet"

---

### Requirement: Prospect Discovery Widget

The dashboard MUST include a Prospect Discovery widget that displays companies matching the configured Ideal Customer Profile (ICP).

#### Scenario: Widget placement in dashboard layout
- WHEN the user views the dashboard
- THEN the Prospect Discovery widget MUST appear in the dashboard layout below the existing charts row
- AND the widget MUST span the full width of the content area (12 columns)
- AND the widget MUST NOT interfere with existing KPI cards, charts, or My Work preview

#### Scenario: Widget collapsed by default
@e2e exclude user-preference stored in backend
- WHEN the dashboard loads
- THEN the Prospect Discovery widget MUST be expandable/collapsible
- AND the collapsed state MUST show: widget title, number of prospects found, and top prospect's company name
- AND the user MUST be able to expand to see the full prospect list

---

### Requirement: Dashboard Data Refresh

The dashboard MUST keep its data current through automatic and manual refresh mechanisms.

#### Scenario: Automatic periodic refresh
@e2e exclude timer-based background fetch; not UI-observable
- WHEN the dashboard is mounted and visible
- THEN the dashboard MUST fetch all data immediately on mount
- AND it MUST set up a periodic refresh timer at a 5-minute interval (`5 * 60 * 1000` ms)
- AND the timer MUST be cleared when the dashboard component is destroyed (`beforeDestroy`)

#### Scenario: Manual refresh button
- WHEN the user clicks the refresh button in the header
- THEN all dashboard data MUST be re-fetched (leads, requests, pipelines, clients, assigned items)
- AND the refresh icon MUST animate with a spinning animation while loading
- AND the button MUST be disabled during the fetch to prevent double-requests

#### Scenario: Parallel data fetching
@e2e exclude async fetch order is implementation detail
- WHEN the dashboard fetches data
- THEN it MUST issue all API requests in parallel via `Promise.all` for: leads (limit 500), requests (limit 500), pipelines (limit 100), clients (limit 500), user's assigned leads (limit 200), and user's assigned requests (limit 200)
- AND each entity type MUST only be fetched if its schema is configured in `objectTypeRegistry`

---

### Requirement: Configurable Widget Layout

The dashboard layout MUST support user customization through the `CnDashboardPage` grid system.

#### Scenario: Layout change persistence
@e2e exclude user layout preference stored in backend
- GIVEN the user rearranges widgets in the dashboard
- WHEN the `layout-change` event fires from `CnDashboardPage`
- THEN the new layout MUST be captured in the component's `dashboardLayout` state
- AND each layout item MUST contain: `id`, `widgetId`, `gridX`, `gridY`, `gridWidth`, `gridHeight`, and optional `showTitle`

#### Scenario: Widget definitions
@e2e exclude static JSON config; covered by unit tests
- WHEN the dashboard initializes
- THEN it MUST register exactly 7 widget definitions with `CnDashboardPage`:
  - `count-open-leads` (Open Leads)
  - `count-open-requests` (Open Requests)
  - `count-pipeline-value` (Pipeline Value)
  - `count-overdue` (Overdue)
  - `deals-by-stage` (Requests by Status)
  - `my-work` (My Work)
  - `client-overview` (Client Overview)
- AND all widgets MUST have `type: 'custom'` to use named slots for rendering

---

### Requirement: Nextcloud Dashboard Widget API Integration

Pipelinq MUST register dashboard widgets with the Nextcloud Dashboard API (`OCP\Dashboard\IWidget`) so they appear in the platform-level dashboard and in LaunchPad.

#### Scenario: Registered Nextcloud dashboard widgets
@e2e exclude PHP OCP\Dashboard\IWidget registration
- WHEN Nextcloud loads dashboard widgets
- THEN Pipelinq MUST provide four `IWidget` implementations:
  - `ClientSearchWidget` -- searchable client list
  - `DealsOverviewWidget` -- open leads with title, client, value, and stage
  - `MyLeadsWidget` -- leads assigned to the current user
  - `RecentActivitiesWidget` -- recent CRM activity feed
- AND each widget MUST implement: `getId()` (returning a unique `pipelinq_*` identifier), `getTitle()` (translated via `IL10N`), `getOrder()`, `getIconClass()`, and `load()` (loading the widget's JavaScript entry point and CSS)

#### Scenario: Widget script loading
@e2e exclude webpack bundle loading; covered by smoke test
- WHEN a Nextcloud dashboard widget's `load()` method is called
- THEN it MUST register the widget's JavaScript bundle via `Util::addScript(APP_ID, APP_ID . '-{widgetName}')` (e.g., `pipelinq-dealsOverviewWidget`)
- AND it MUST load shared dashboard widget styles via `Util::addStyle(APP_ID, 'dashboardWidgets')`

---

### Requirement: NL Design System Theming

The dashboard MUST render correctly under NL Design System government themes via CSS custom properties.

#### Scenario: CSS variable usage for colors
@e2e exclude CSS implementation detail; covered by style tests
- WHEN the dashboard renders under any NL Design theme
- THEN all background colors MUST use Nextcloud CSS variables (`--color-background-dark`, `--color-background-hover`)
- AND all text colors MUST use Nextcloud CSS variables (`--color-text-maxcontrast`, `--color-error`)
- AND border radii MUST use `var(--border-radius)`
- AND no hardcoded color values MUST appear in structural layout styles (entity badges excepted as they use semantic CRM-specific colors)

---

### Requirement: Responsive Layout

The dashboard MUST adapt to different viewport sizes while maintaining usability.

#### Scenario: Widget grid responsiveness
- WHEN the viewport width decreases below the 12-column grid breakpoint
- THEN the `CnDashboardPage` grid MUST reflow widgets to stack vertically
- AND KPI cards MUST remain readable at narrow widths (horizontal layout via `CnStatsBlock` `horizontal` prop)
- AND scrollable widget content (My Work, Client Overview) MUST use `overflow: auto` to prevent layout overflow

#### Scenario: Widget content text overflow
@e2e exclude CSS overflow behavior; covered by visual regression
- WHEN widget content contains long text (client names, lead titles)
- THEN text MUST be truncated with ellipsis (`text-overflow: ellipsis; white-space: nowrap; overflow: hidden`)
- AND the full text MUST remain accessible (via browser-native title attribute or tooltip)

---

### Requirement: Accessibility

The dashboard MUST meet WCAG AA accessibility standards.

#### Scenario: Interactive element accessibility
- WHEN the dashboard renders interactive elements
- THEN the refresh button MUST have an `aria-label` attribute: "Refresh dashboard" (translatable)
- AND all clickable rows (My Work items, Client items) MUST be keyboard-navigable
- AND status bar colors MUST have sufficient contrast against the track background (`--color-background-dark`)

#### Scenario: Loading state communication
- WHEN the dashboard is loading data
- THEN the loading state MUST be visually indicated (spinning refresh icon, `CnDashboardPage` loading prop)
- AND the loading state MUST NOT block interaction with already-rendered content

---

### Requirement: Dashboard UI — documented operations

The dashboard screens implemented in this app MUST provide the operations enumerated in this change's tasks.md (for example `onClientCreated`, `onLeadCreated`, `onRequestCreated`, `refresh`, `mounted`, `recent`). Each listed method realises an observable part of dashboard screens and MUST behave as implemented in the current codebase.

**Feature tier**: V1

#### Scenario: Documented operations are available

@e2e exclude component/store method contract; page surface covered by real-UI spec-coverage tests + Vitest unit tests

- GIVEN the frontend component/store is loaded
- WHEN a caller invokes one of the documented operations for dashboard screens
- THEN the operation MUST execute and return a result consistent with the current implementation

---

### Requirement: Dashboard UI — results derived from current CRM state

Operations for dashboard screens MUST read their inputs from the relevant CRM entities/configuration and compute results from that live state (no hard-coded or stubbed responses). Derivations such as formatting, aggregation, filtering and validation MUST reflect the data present at call time.

**Feature tier**: V1

#### Scenario: Results reflect live state

@e2e exclude component/store method contract; page surface covered by real-UI spec-coverage tests + Vitest unit tests

- GIVEN CRM data backing dashboard screens
- WHEN a documented operation runs
- THEN its output MUST be derived from that data
- AND it MUST change when the underlying data changes

---

### Requirement: Dashboard UI — defensive handling of absent or invalid input

Operations for dashboard screens MUST tolerate missing, empty, or malformed input without throwing unhandled errors — returning empty or default results, or surfacing a validation outcome as implemented, rather than crashing the surrounding flow.

**Feature tier**: V1

#### Scenario: Missing input does not crash the flow

@e2e exclude component/store method contract; page surface covered by real-UI spec-coverage tests + Vitest unit tests

- GIVEN an operation for dashboard screens is called with absent or invalid input
- WHEN it executes
- THEN it MUST return a safe default or a validation result
- AND it MUST NOT raise an unhandled exception

### Requirement: Dashboard widget UI — documented operations

The dashboard widgets implemented in this app MUST provide the operations enumerated in this change's tasks.md (for example `fetchData`, `formatCurrency`, `objectStore`, `formatTime`, `onCreateLead`, `prospectStore`). Each listed method realises an observable part of dashboard widgets and MUST behave as implemented in the current codebase.

**Feature tier**: V1

#### Scenario: Documented operations are available

@e2e exclude component/store method contract; page surface covered by real-UI spec-coverage tests + Vitest unit tests

- GIVEN the frontend component/store is loaded
- WHEN a caller invokes one of the documented operations for dashboard widgets
- THEN the operation MUST execute and return a result consistent with the current implementation

---

### Requirement: Dashboard widget UI — results derived from current CRM state

Operations for dashboard widgets MUST read their inputs from the relevant CRM entities/configuration and compute results from that live state (no hard-coded or stubbed responses). Derivations such as formatting, aggregation, filtering and validation MUST reflect the data present at call time.

**Feature tier**: V1

#### Scenario: Results reflect live state

@e2e exclude component/store method contract; page surface covered by real-UI spec-coverage tests + Vitest unit tests

- GIVEN CRM data backing dashboard widgets
- WHEN a documented operation runs
- THEN its output MUST be derived from that data
- AND it MUST change when the underlying data changes

---

### Requirement: Dashboard widget UI — defensive handling of absent or invalid input

Operations for dashboard widgets MUST tolerate missing, empty, or malformed input without throwing unhandled errors — returning empty or default results, or surfacing a validation outcome as implemented, rather than crashing the surrounding flow.

**Feature tier**: V1

#### Scenario: Missing input does not crash the flow

@e2e exclude component/store method contract; page surface covered by real-UI spec-coverage tests + Vitest unit tests

- GIVEN an operation for dashboard widgets is called with absent or invalid input
- WHEN it executes
- THEN it MUST return a safe default or a validation result
- AND it MUST NOT raise an unhandled exception

### Requirement: Navi AI Analytics Widget (REQ-DASH-001)

The dashboard MUST include a Navi AI Analytics widget backed by `NaviController` and `NaviService`, accepting natural-language queries and returning results as inline charts or tables.

**Feature tier**: V1
**Schema.org**: `schema:AnalyzeAction`
**Backend**: `NaviController` + `NaviService`
**Frontend**: `NaviAnalyticsWidget.vue`

#### Scenario: Submit natural-language query

- GIVEN the user opens the Navi widget on the dashboard
- WHEN the user types a query such as "Hoeveel leads zijn er deze maand gewonnen?" and presses Enter
- THEN the frontend MUST POST to `/api/navi/query` with `{ query: "...", conversationId: "..." }`
- AND the backend MUST respond within 30 seconds with `{ resultType, chartData?, tableData?, textResponse, suggestedFollowUps[] }`
- AND the widget MUST render the result using `CnChartWidget` (for chart results) or `CnTableWidget` (for table results) or plain text (for text results)

#### Scenario: Conversational follow-up

- GIVEN the user has submitted an initial query and received a result
- WHEN the user types a follow-up query referencing the previous result
- THEN the frontend MUST include the `conversationId` in subsequent requests to maintain context
- AND the response MUST reflect the accumulated conversation context

#### Scenario: Empty result set

- GIVEN the user submits a valid query but no matching data exists
- WHEN NaviService processes the query
- THEN the response MUST include `resultType: "text"` with a human-readable message explaining no data was found
- AND the widget MUST display this message instead of an empty chart or table

#### Scenario: Invalid or ambiguous query

- GIVEN the user submits a query the backend cannot parse into a valid intent
- WHEN NaviService processes the query
- THEN the response MUST include `resultType: "text"` with a clarification request
- AND the frontend MUST display the clarification message and keep the input field active

#### Scenario: Navi widget in dashboard layout

- WHEN the user views the dashboard
- THEN the Navi widget MUST appear in the dashboard grid registered as `widget-id: "navi-analytics"`
- AND the widget MUST span 12 columns (full width) by default in the layout
- AND the widget MUST be reorderable via the `CnDashboardPage` drag-drop interface

---

### Requirement: Navi API Authorization (REQ-DASH-002)

The Navi API MUST enforce Nextcloud authentication and MUST NOT expose data outside the current user's OpenRegister access scope.

**Feature tier**: V1

#### Scenario: Unauthenticated request rejected

- GIVEN an unauthenticated request is made to `POST /api/navi/query`
- WHEN the Nextcloud auth middleware evaluates the request
- THEN the server MUST return HTTP 401
- AND the response body MUST contain `{ "message": "Unauthorized" }` (static, no stack trace)

#### Scenario: Query scoped to user's data

@e2e exclude proving isolation needs TWO Nextcloud users with disjoint OpenRegister ACLs; `tests/e2e/global-setup.ts` provisions a single account (ADMIN_USER, default `admin`) and the suite has no way to create a second. The reachable half — the endpoint refusing a caller with no session — is asserted end-to-end by the sibling scenario `unauthenticated-request-rejected` in `tests/e2e/spec-coverage/dashboard.spec.ts` (test "POST /api/navi/query is rejected with 401 and leaks nothing") and at unit level by `tests/Unit/Controller/NaviControllerTest.php::testQueryReturnsUnauthorizedWithoutSession`.

- GIVEN the user is authenticated
- WHEN NaviService dispatches OpenRegister queries
- THEN all `ObjectService` calls MUST use the standard multi-tenancy context
- AND the response MUST only contain objects the current user is authorized to read

---

### Requirement: Navi Suggested Follow-Ups (REQ-DASH-003)

The Navi widget MUST display suggested follow-up questions after each response to guide discovery.

**Feature tier**: V1

#### Scenario: Display follow-up chips

- GIVEN Navi has returned a result with a non-empty `suggestedFollowUps` array
- WHEN the result is rendered in the widget
- THEN the widget MUST display up to 3 follow-up suggestion chips below the result
- AND clicking a chip MUST pre-fill the query input with that suggestion and submit it automatically

#### Scenario: No suggested follow-ups

- GIVEN Navi returns a result where `suggestedFollowUps` is empty
- WHEN the result is rendered
- THEN no suggestion chips MUST appear (the chip area MUST be hidden, not empty/blank)

---

### Requirement: Unified Analytics Dashboard Panel (REQ-DASH-010)

The dashboard MUST include an "Analytics" panel (`AnalyticsDashboard.vue`) providing real-time cross-module KPIs and trend charts covering the full client lifecycle.

**Feature tier**: V1
**Backend**: `AnalyticsController` + `AnalyticsService`
**Frontend**: `AnalyticsDashboard.vue`

#### Scenario: Cross-module KPI overview

- GIVEN the user navigates to the dashboard
- WHEN the Analytics panel loads
- THEN the panel MUST fetch `GET /api/analytics/overview` and display the following KPIs using `CnStatsBlock`:
  - **Lead conversion rate** — percentage of leads with `status: "won"` over total leads in the selected period
  - **Average request resolution time** — mean duration between `requestedAt` and `completedAt` for resolved requests
  - **Contact moment volume** — count of `contactmoment` objects in the selected period
  - **Customer satisfaction score** — mean score from `surveyResponse` objects in the selected period (or "N/A" if none)
- AND each KPI MUST display a trend indicator (up/down arrow) comparing to the previous equal period

#### Scenario: Period selector

- WHEN the user views the Analytics panel
- THEN a period selector MUST be visible in the panel header using the `header-actions` slot pattern (ADR-018)
- AND the selector MUST offer: "Deze week", "Deze maand", "Dit kwartaal", "Dit jaar"
- AND changing the period MUST re-fetch `GET /api/analytics/overview?period={period}` and update all KPIs

#### Scenario: Trend chart — leads over time

- WHEN the Analytics panel renders
- THEN a line chart MUST display lead count and pipeline value over time using `CnChartWidget`
- AND the X-axis MUST represent time intervals appropriate to the selected period (days for week/month, weeks for quarter, months for year)
- AND the chart data MUST come from `GET /api/analytics/trends?metric=leads&period={period}`

#### Scenario: Trend chart — requests by category

- WHEN the Analytics panel renders
- THEN a bar chart MUST display request counts grouped by `category` using `CnChartWidget`
- AND the chart data MUST come from `GET /api/analytics/trends?metric=requests-by-category&period={period}`
- AND categories with zero requests in the period MUST be excluded from the chart

#### Scenario: Analytics panel widget registration

- WHEN the dashboard initializes
- THEN the Analytics panel MUST be registered as `widget-id: "unified-analytics"` in the `CnDashboardPage` widget definitions
- AND the widget MUST span 12 columns by default
- AND the widget MUST appear below the existing KPI cards row (gridY: 3 in default layout)

---

### Requirement: Analytics API Endpoints (REQ-DASH-011)

The `AnalyticsController` MUST provide aggregate endpoints consumed by the Analytics panel.

**Feature tier**: V1

#### Scenario: GET /api/analytics/overview

- GIVEN an authenticated user requests `GET /api/analytics/overview?period=month`
- WHEN `AnalyticsController` handles the request
- THEN the response MUST include HTTP 200 with a JSON body containing:
  - `leadConversionRate` (number, 0–100)
  - `avgRequestResolutionTime` (number, hours, or null if no resolved requests)
  - `contactMomentVolume` (integer)
  - `customerSatisfactionScore` (number, 1–5, or null if no survey responses)
  - `period` (string, echoed back)
  - `previousPeriod` (same fields for trend comparison)

#### Scenario: GET /api/analytics/trends

- GIVEN an authenticated user requests `GET /api/analytics/trends?metric=leads&period=month`
- WHEN `AnalyticsController` handles the request
- THEN the response MUST include HTTP 200 with `{ metric, period, series: [{ date, value }] }`
- AND the `date` field MUST be an ISO 8601 date string

#### Scenario: Unsupported metric returns 400

- GIVEN an authenticated user requests `GET /api/analytics/trends?metric=unknown`
- WHEN `AnalyticsController` handles the request
- THEN the server MUST return HTTP 400 with `{ "message": "Unsupported metric" }`
- AND the response MUST NOT include a stack trace or internal details

---

### Requirement: Funder Reporting Export Panel (REQ-DASH-020)

The dashboard MUST include a collapsible "Report Export" panel (`ReportExportPanel.vue`) allowing users to generate and download structured CRM performance reports.

**Feature tier**: V1
**Frontend**: `ReportExportPanel.vue`
**Reuses**: `ExportService` via `CnMassExportDialog`

#### Scenario: Configure and download a report

- GIVEN the user expands the Report Export panel
- WHEN the user selects: entity type (Leads / Requests / Contact Moments / Satisfaction), period (week/month/quarter/year), and format (CSV / Excel / JSON)
- AND clicks "Download Report"
- THEN the frontend MUST open `CnMassExportDialog` with the appropriate entity type and applied period filter
- AND the export MUST be performed by `ExportService` — no custom export controller is permitted

#### Scenario: Panel collapsed by default

- WHEN the dashboard loads
- THEN the Report Export panel MUST render in a collapsed state showing only the panel title and a brief description
- AND the user MUST be able to expand it by clicking the panel header

#### Scenario: Report export widget registration

- WHEN the dashboard initializes
- THEN the Report Export panel MUST be registered as `widget-id: "report-export"` in `CnDashboardPage`
- AND the widget MUST span 12 columns
- AND the widget MUST appear below the Analytics panel in the default layout

#### Scenario: Supported entity types in report

- WHEN the user opens the Report Export panel
- THEN the entity type selector MUST list at minimum:
  - "Leads" (queries `lead` schema)
  - "Verzoeken" (queries `request` schema)
  - "Contactmomenten" (queries `contactmoment` schema)
  - "Tevredenheidsscores" (queries `surveyResponse` schema)
- AND selecting an entity type MUST update the available field columns shown in `CnMassExportDialog`

---

### Requirement: Report Export Accessibility (REQ-DASH-021)

The Report Export panel MUST meet WCAG AA accessibility standards.

**Feature tier**: V1

#### Scenario: Keyboard-navigable controls

- WHEN the user navigates the Report Export panel using keyboard only
- THEN all controls (period selector, entity type selector, format picker, download button) MUST be reachable via Tab
- AND the expand/collapse toggle MUST respond to Enter and Space keys
- AND focus MUST not be trapped inside the panel

---

### Requirement: Dashboard Widget Layout — Extended Default (REQ-DASH-030)

The existing default dashboard layout MUST be extended to include the three new analytics widgets.

**Feature tier**: V1

#### Scenario: Updated default layout includes analytics widgets

- GIVEN a user has not customized their dashboard layout
- WHEN the dashboard renders
- THEN the default layout MUST include (in addition to existing widgets):
  - `navi-analytics` widget at gridY: 4, 12 columns, gridHeight: 6
  - `unified-analytics` widget at gridY: 3, 12 columns, gridHeight: 5
  - `report-export` widget at gridY: 10, 12 columns, gridHeight: 3
- AND all existing widget positions (KPI cards at row 1, chart + My Work at row 2, Client Overview at row 3) MUST remain unchanged

#### Scenario: Total widget count updated

- WHEN the dashboard initializes `CnDashboardPage`
- THEN the widget definitions array MUST contain exactly 10 widget definitions (7 existing + 3 new)
- AND all widgets MUST have `type: 'custom'` with matching `#widget-{id}` slot templates

---

### Requirement: No Permanently-Null Default Widgets

The default Operational dashboard SHALL NOT include a widget whose data source is known-empty for every install. The Satisfaction KPI (`SatisfactionKpiWidget`, widget id `satisfaction`) SHALL be removed from the default Operational dashboard definition (widget def, layout slot, and template mapping) until the `customer-satisfaction-closed-loop` change re-sources CSAT data — that change owns restoration; this requirement only removes the dead tile. The vacated grid space SHALL be reflowed so the layout has no hole, and a manifest note SHALL record the restoration owner. No placeholder or "coming soon" tile SHALL replace it.

**Feature tier**: MVP

#### Scenario: Operational dashboard renders no empty satisfaction tile

- GIVEN a functioning install with normal CRM activity
- WHEN the Operational dashboard loads
- THEN no Customer Satisfaction widget MUST be present
- AND every rendered KPI widget MUST be backed by a live data source

#### Scenario: Restoration ownership recorded

- WHEN `src/manifest.json` is inspected
- THEN a note MUST record that the satisfaction widget returns via `customer-satisfaction-closed-loop`

#### Scenario: Layout reflows without a hole

- WHEN the Operational dashboard renders after removal
- THEN the KPI row MUST show no empty grid slot where the widget was

## REMOVED Requirements

_(none)_

---

### Current Implementation Status

**Substantially implemented.** The core dashboard with KPI cards, charts, and My Work preview is fully functional. The delta requirements (Prospect Discovery widget, Product Revenue KPI) are partially implemented.

Implemented:
- **KPI Cards**: `src/views/Dashboard.vue` displays four KPI cards in the top row:
  - **Open Leads** -- count of leads in non-closed stages (uses `closedStageNames` computed from pipeline stages).
  - **Open Requests** -- count of requests with status `new` or `in_progress`.
  - **Pipeline Value** -- sum of `value` for leads in non-closed stages, formatted as EUR with locale.
  - **Overdue** -- count of overdue leads (past `expectedCloseDate`) + overdue requests (status new/in_progress with `requestedAt` older than 30 days). Warning styling when > 0.
- **Requests by Status chart**: Horizontal bar chart showing request distribution by status (new, in_progress, completed, rejected, converted) with color coding.
- **My Work widget**: Shows top 5 items assigned to the current user (leads + requests), sorted by overdue first, then priority, then due date. Links to `MyWork` view for full list.
- **Client Overview widget**: Shows the 5 most recent clients with name and info, linking to client detail.
- **KPI cards with zero values**: All KPIs display `0` when no data exists (not blank or error).
- **Empty state**: Welcome message when no data exists.
- **Quick actions**: "New Lead", "New Request", "New Client" buttons in header, each opening create dialogs.
- **Auto-refresh**: Dashboard data refreshes every 5 minutes.
- **Layout**: Uses `CnDashboardPage` with configurable grid layout (`DEFAULT_LAYOUT`): 4 KPI cards (3 cols each) top row, status chart + my work (6 cols each) second row, client overview (12 cols) third row.
- **Nextcloud Dashboard Widgets**: `lib/Dashboard/ClientSearchWidget.php`, `DealsOverviewWidget.php`, `MyLeadsWidget.php`, `RecentActivitiesWidget.php` -- registered as Nextcloud dashboard widgets (separate from the in-app dashboard).
- **Prospect Discovery widget**: `src/components/ProspectWidget.vue` and `ProspectCard.vue` exist but are NOT integrated into the main Dashboard.vue layout.
- **Product Revenue KPI**: `src/components/ProductRevenue.vue` exists but is NOT integrated into the Dashboard.vue layout as a KPI card.

NOT implemented:
- **Products KPI card** -- "Products" count card showing active products is not in the dashboard.
- **Prospect Discovery widget integration** -- the component exists but is not rendered in the dashboard layout.
- **Product Revenue KPI card integration** -- the component exists but is not rendered in the dashboard.
- **Delta indicators** (trend up/down compared to previous period) -- deferred to V1 per spec.
- **Lead value auto-calculation from line items** -- the dashboard sums `lead.value` directly, but does not recalculate from LeadProduct line items.

### Standards & References
- Nextcloud Dashboard Widget API (`OCP\Dashboard\IWidget`) -- used for Nextcloud-level dashboard widgets
- `CnDashboardPage` / `CnStatsBlock` from `@conduction/nextcloud-vue` -- shared dashboard grid and KPI components
- NL Design System -- CSS custom properties for government theming
- WCAG AA -- Nextcloud Vue components provide baseline accessibility
- Schema.org -- no direct standards apply to dashboard layout

### Specificity Assessment
- The spec is clear and specific for implementation. Scenarios are well-defined with concrete values.
- **Mostly implementable as-is** -- the remaining work is integrating existing components (ProspectWidget, ProductRevenue) into the dashboard layout.
- **Gap**: The spec does not define the "Products" KPI card calculation -- should it count all products or only `status: active` products?
- **Gap**: The Prospect Discovery widget spec says "collapsed by default" with expand/collapse behavior, but the exact placement and interaction with the grid layout system is not defined.

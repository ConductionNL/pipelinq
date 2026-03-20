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
- GIVEN a network error occurs during dashboard data fetching
- WHEN the dashboard fails to load
- THEN an error message MUST be displayed with a "Retry" button
- AND clicking "Retry" MUST re-invoke the full data fetch

---

### Requirement: KPI Cards Row

The dashboard MUST display a row of four KPI summary cards at the top of the page using `CnStatsBlock` components, providing headline metrics at a glance. Each card MUST suppress its title bar (`showTitle: false`) and render in horizontal orientation.

#### Scenario: Display open leads count
- WHEN the user views the dashboard
- THEN the "Open Leads" KPI card MUST display the count of leads whose `stage` name does NOT appear in any pipeline stage where `isClosed = true`
- AND the card MUST use the `TrendingUp` icon with `variant="primary"`
- AND clicking the card MUST navigate to the Leads view filtered by `status=open`

#### Scenario: Display open requests count
- WHEN the user views the dashboard
- THEN the "Open Requests" KPI card MUST display the count of requests with `status` equal to `new` or `in_progress`
- AND the card MUST use the `FileDocument` icon with `variant="primary"`
- AND clicking the card MUST navigate to the Requests view filtered by `status=open`

#### Scenario: Display pipeline total value
- WHEN the user views the dashboard
- THEN the "Pipeline Value" KPI card MUST display the sum of `value` for all leads in non-closed stages
- AND the value MUST be formatted as EUR currency with Dutch locale (e.g., "EUR 125.200")
- AND the card MUST use the `CurrencyEur` icon with `variant="success"`
- AND clicking the card MUST navigate to the Pipeline view

#### Scenario: Display overdue items count
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
- GIVEN leads and/or requests are assigned to the current user
- WHEN the user views the "My Work" widget
- THEN the widget MUST display up to 5 items (leads and requests combined)
- AND each item MUST show: an entity badge ("LEAD" or "REQ" with distinct colors), title, stage or status, and due date (formatted as `month day` in Dutch locale)
- AND items MUST be sorted by: overdue items first, then by priority order (`urgent` > `high` > `normal` > `low`), then by due date ascending

#### Scenario: Overdue item highlighting
- GIVEN an assigned lead has `expectedCloseDate` in the past, or an assigned request has `requestedAt` older than 30 days with status `new` or `in_progress`
- WHEN the item appears in the "My Work" widget
- THEN the item row MUST have a red-tinted background (`my-work-item--overdue`)
- AND the due date MUST be displayed in red with bold font weight

#### Scenario: View all link for overflow
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
- GIVEN no clients exist in the system
- WHEN the user views the "Client Overview" widget
- THEN the widget MUST display the message "No clients yet"

---

### Requirement: Product Revenue KPI Card

The dashboard MUST display a "Top Products by Pipeline Value" widget showing the top products by aggregated pipeline revenue from `LeadProduct` line items.

#### Scenario: Revenue by product display
- GIVEN leads exist with `LeadProduct` line items linked to products
- WHEN the user views the dashboard
- THEN a "Top Products" widget MUST display the top 3 products ranked by total pipeline value (sum of `total` from line items)
- AND each product MUST show: product name, number of associated leads, and total value formatted as EUR currency
- AND products with higher total value MUST appear first

#### Scenario: No products in pipeline
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
- WHEN the dashboard loads
- THEN the Prospect Discovery widget MUST be expandable/collapsible
- AND the collapsed state MUST show: widget title, number of prospects found, and top prospect's company name
- AND the user MUST be able to expand to see the full prospect list

---

### Requirement: Dashboard Data Refresh

The dashboard MUST keep its data current through automatic and manual refresh mechanisms.

#### Scenario: Automatic periodic refresh
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
- WHEN the dashboard fetches data
- THEN it MUST issue all API requests in parallel via `Promise.all` for: leads (limit 500), requests (limit 500), pipelines (limit 100), clients (limit 500), user's assigned leads (limit 200), and user's assigned requests (limit 200)
- AND each entity type MUST only be fetched if its schema is configured in `objectTypeRegistry`

---

### Requirement: Configurable Widget Layout

The dashboard layout MUST support user customization through the `CnDashboardPage` grid system.

#### Scenario: Layout change persistence
- GIVEN the user rearranges widgets in the dashboard
- WHEN the `layout-change` event fires from `CnDashboardPage`
- THEN the new layout MUST be captured in the component's `dashboardLayout` state
- AND each layout item MUST contain: `id`, `widgetId`, `gridX`, `gridY`, `gridWidth`, `gridHeight`, and optional `showTitle`

#### Scenario: Widget definitions
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

Pipelinq MUST register dashboard widgets with the Nextcloud Dashboard API (`OCP\Dashboard\IWidget`) so they appear in the platform-level dashboard and in MyDash.

#### Scenario: Registered Nextcloud dashboard widgets
- WHEN Nextcloud loads dashboard widgets
- THEN Pipelinq MUST provide four `IWidget` implementations:
  - `ClientSearchWidget` -- searchable client list
  - `DealsOverviewWidget` -- open leads with title, client, value, and stage
  - `MyLeadsWidget` -- leads assigned to the current user
  - `RecentActivitiesWidget` -- recent CRM activity feed
- AND each widget MUST implement: `getId()` (returning a unique `pipelinq_*` identifier), `getTitle()` (translated via `IL10N`), `getOrder()`, `getIconClass()`, and `load()` (loading the widget's JavaScript entry point and CSS)

#### Scenario: Widget script loading
- WHEN a Nextcloud dashboard widget's `load()` method is called
- THEN it MUST register the widget's JavaScript bundle via `Util::addScript(APP_ID, APP_ID . '-{widgetName}')` (e.g., `pipelinq-dealsOverviewWidget`)
- AND it MUST load shared dashboard widget styles via `Util::addStyle(APP_ID, 'dashboardWidgets')`

---

### Requirement: NL Design System Theming

The dashboard MUST render correctly under NL Design System government themes via CSS custom properties.

#### Scenario: CSS variable usage for colors
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

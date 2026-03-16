# Dashboard Specification (Delta)

## Purpose

Extends the existing dashboard specification to include the Prospect Discovery widget and a Product Revenue KPI card.

---

## ADDED Requirements

### Requirement: Prospect Discovery Widget

The dashboard MUST include a Prospect Discovery widget that displays companies matching the configured ICP, as specified in the prospect-discovery capability.

#### Scenario: Widget placement in dashboard layout
- WHEN the user views the dashboard
- THEN the Prospect Discovery widget MUST appear in the dashboard layout below the existing charts row
- AND the widget MUST span the full width of the content area
- AND the widget MUST NOT interfere with existing KPI cards, charts, or My Work preview

#### Scenario: Widget collapsed by default
- WHEN the dashboard loads
- THEN the Prospect Discovery widget MUST be expandable/collapsible
- AND the collapsed state MUST show: widget title, number of prospects found, and top prospect's company name
- AND the user MUST be able to expand to see the full prospect list

---

### Requirement: Product Revenue KPI Card

The dashboard MUST display a "Revenue by Product" KPI card showing the total pipeline value broken down by product.

#### Scenario: Revenue by product display
- GIVEN leads exist with LeadProduct line items
- WHEN the user views the dashboard
- THEN a "Top Products" KPI card MUST display the top 3 products by total pipeline value (sum of line item totals for non-closed leads)
- AND each product MUST show: product name and total value (formatted as currency)

#### Scenario: No products in pipeline
- GIVEN no leads have LeadProduct line items
- WHEN the user views the dashboard
- THEN the "Top Products" KPI card MUST display "No product data yet"

---

## MODIFIED Requirements

### Requirement: KPI Cards

The dashboard MUST display a row of KPI summary cards at the top of the page, providing headline metrics at a glance. MVP scope includes counts and totals; delta indicators are deferred to V1.

#### Scenario: Display open leads count
- WHEN the user views the dashboard
- THEN the "Open Leads" KPI card MUST display the count of leads in non-closed pipeline stages (isClosed = false)

#### Scenario: Display open requests count
- WHEN the user views the dashboard
- THEN the "Open Requests" KPI card MUST display the count of requests with status `new` or `in_progress`

#### Scenario: Display pipeline total value
- WHEN the user views the dashboard
- THEN the "Pipeline Value" KPI card MUST display the sum of lead values for leads in non-closed stages, formatted as currency (e.g., "EUR 125.200")
- AND lead values MUST reflect auto-calculated values from line items where applicable

#### Scenario: Display overdue items count
- WHEN the user views the dashboard
- THEN the "Overdue" KPI card MUST display the count of leads with `expectedCloseDate` in the past (in non-closed stages) plus requests with `requestedAt` older than 30 days and status `new` or `in_progress`
- AND if overdue count > 0, the card MUST use a warning visual style (red/orange accent)

#### Scenario: Display product count
- WHEN the user views the dashboard
- THEN a "Products" KPI card MUST display the count of active products in the catalog

#### Scenario: KPI cards with zero values
- WHEN no data exists (fresh installation)
- THEN all KPI cards MUST display `0` (not blank, not an error)

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
- WCAG AA -- Nextcloud Vue components provide baseline accessibility
- Schema.org -- no direct standards apply to dashboard layout

### Specificity Assessment
- The spec is clear and specific for implementation. Scenarios are well-defined with concrete values.
- **Mostly implementable as-is** -- the remaining work is integrating existing components (ProspectWidget, ProductRevenue) into the dashboard layout.
- **Gap**: The spec does not define the "Products" KPI card calculation -- should it count all products or only `status: active` products?
- **Gap**: The Prospect Discovery widget spec says "collapsed by default" with expand/collapse behavior, but the exact placement and interaction with the grid layout system is not defined.

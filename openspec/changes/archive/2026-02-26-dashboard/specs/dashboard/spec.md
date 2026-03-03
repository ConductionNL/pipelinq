# Dashboard Specification (Delta)

## Purpose

Implement the MVP tier of the dashboard specification, replacing the placeholder with real KPI cards, a status chart, My Work preview, quick actions, and proper data lifecycle.

## MODIFIED Requirements

### Requirement: KPI Cards [MVP] (REQ-DB-010)

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

#### Scenario: Display overdue items count
- WHEN the user views the dashboard
- THEN the "Overdue" KPI card MUST display the count of leads with `expectedCloseDate` in the past (in non-closed stages) plus requests with `requestedAt` older than 30 days and status `new` or `in_progress`
- AND if overdue count > 0, the card MUST use a warning visual style (red/orange accent)

#### Scenario: KPI cards with zero values
- WHEN no data exists (fresh installation)
- THEN all KPI cards MUST display `0` (not blank, not an error)

### Requirement: Requests by Status Chart [MVP] (REQ-DB-040)

The dashboard MUST display a chart showing the distribution of requests by status.

#### Scenario: Requests by status with data
- WHEN requests exist across different statuses
- THEN the "Requests by Status" chart MUST display each status as a horizontal bar
- AND each bar MUST show the status label, count, and use color coding consistent with the status badges

#### Scenario: Requests by status with no data
- WHEN no requests exist
- THEN the chart area MUST display an empty state message ("No requests yet")

### Requirement: My Work Preview [MVP] (REQ-DB-050)

The dashboard MUST display a preview of the current user's assigned work items, limited to the top 5, with a link to the full list views.

#### Scenario: My Work preview with items
- WHEN the current user has assigned leads and/or requests
- THEN the "My Work" section MUST display the top 5 items sorted by: overdue first, then priority (urgent > high > normal > low), then due date
- AND each item MUST show: entity type badge (Lead/Request), title, stage or status, due date
- AND overdue items MUST be visually highlighted

#### Scenario: My Work preview with no items
- WHEN the current user has no assigned items
- THEN the "My Work" section MUST display "No items assigned to you"

### Requirement: Quick Actions [MVP] (REQ-DB-070)

The dashboard MUST provide quick action buttons for creating new entities.

#### Scenario: Quick create buttons
- WHEN the user is on the dashboard
- THEN quick action buttons for "New Lead", "New Request", and "New Client" MUST be visible
- AND clicking each button MUST navigate to the respective creation form

#### Scenario: Quick actions placement
- WHEN viewing the dashboard layout
- THEN quick action buttons MUST be in the header area near the KPI cards
- AND the buttons MUST be keyboard accessible

### Requirement: Dashboard Data Refresh [MVP] (REQ-DB-080)

The dashboard MUST display current data and refresh when navigated to.

#### Scenario: Initial data load
- WHEN the user navigates to the dashboard
- THEN the system MUST fetch all dashboard data from the OpenRegister API
- AND the system MUST show loading indicators while data is being fetched

#### Scenario: Data refresh on return
- WHEN the user navigates away and returns to the dashboard
- THEN the system MUST re-fetch all dashboard data to reflect changes

#### Scenario: Error during data load
- WHEN the OpenRegister API is unavailable
- THEN the system MUST display an error message for affected sections
- AND sections that loaded successfully MUST still be displayed

### Requirement: Empty State (New Installation) [MVP] (REQ-DB-090)

The dashboard MUST handle fresh installations gracefully.

#### Scenario: Fresh installation dashboard
- WHEN no clients, leads, or requests exist
- THEN all KPI cards MUST show `0`
- AND charts MUST show empty states (not errors)
- AND the My Work preview MUST show "No items assigned to you"
- AND a welcome message SHOULD be displayed
- AND quick action buttons MUST be visible and functional

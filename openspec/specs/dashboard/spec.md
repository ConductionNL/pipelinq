# Dashboard Specification

## Purpose

The dashboard is the landing page of the Pipelinq app, providing an at-a-glance overview of CRM activity. It aggregates key metrics, pipeline health, recent activity, and a personal work preview to give users immediate context when they open the app. The dashboard is designed around the wireframe in DESIGN-REFERENCES.md section 3.1.

**Feature tier**: MVP (basic counts, quick actions), V1 (KPI cards, charts, activity feed, role-based views)

---

## Requirements

### REQ-DB-010: KPI Cards [MVP]

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

---

### REQ-DB-020: Pipeline Funnel Visualization [V1]

The dashboard SHOULD display a pipeline funnel chart showing lead distribution across stages and the overall conversion rate.

#### Scenario: Funnel chart with data
- GIVEN the default Sales Pipeline with leads distributed as: New (5), Contacted (4), Qualified (3), Proposal (2), Negotiation (1), Won (1)
- WHEN the user views the dashboard
- THEN the funnel chart MUST display each non-closed stage as a horizontal bar
- AND each bar MUST show: stage name, bar fill proportional to count, and the count number
- AND the chart MUST display the overall conversion rate (Won / total leads entered)
- AND the conversion rate MUST be formatted as a percentage (e.g., "Conversion: 20%")

#### Scenario: Funnel for selected pipeline
- GIVEN multiple pipelines exist
- WHEN the dashboard displays the funnel
- THEN it SHOULD default to the pipeline marked as default
- AND the user MAY be able to switch to a different pipeline via a dropdown selector

#### Scenario: Funnel with no leads
- GIVEN a pipeline with no leads
- WHEN the user views the funnel chart
- THEN the chart MUST display all stages with zero-width bars and count `0`
- AND the conversion rate MUST display "0%"

---

### REQ-DB-030: Leads by Source Chart [V1]

The dashboard SHOULD display a chart showing the distribution of leads by source.

#### Scenario: Leads by source with data
- GIVEN leads with sources: website (6), referral (4), phone (3), campaign (2)
- WHEN the user views the dashboard
- THEN the "Leads by Source" chart MUST display each source as a bar (or pie segment)
- AND each segment MUST show the source name and its count or percentage
- AND sources with zero leads SHOULD be omitted from the chart

#### Scenario: Leads by source with no data
- GIVEN no leads exist
- WHEN the user views the dashboard
- THEN the chart area MUST display an empty state message (e.g., "No lead data yet")
- AND the chart MUST NOT display an error

---

### REQ-DB-040: Requests by Status Chart [MVP]

The dashboard MUST display a chart showing the distribution of requests by status.

#### Scenario: Requests by status with data
- WHEN requests exist across different statuses
- THEN the "Requests by Status" chart MUST display each status as a horizontal bar
- AND each bar MUST show the status label, count, and use color coding consistent with the status badges

#### Scenario: Requests by status with no data
- WHEN no requests exist
- THEN the chart area MUST display an empty state message ("No requests yet")

---

### REQ-DB-050: My Work Preview [MVP]

The dashboard MUST display a preview of the current user's assigned work items, limited to the top 5, with a link to the full list views.

#### Scenario: My Work preview with items
- WHEN the current user has assigned leads and/or requests
- THEN the "My Work" section MUST display the top 5 items sorted by: overdue first, then priority (urgent > high > normal > low), then due date
- AND each item MUST show: entity type badge (Lead/Request), title, stage or status, due date
- AND overdue items MUST be visually highlighted

#### Scenario: My Work preview with no items
- WHEN the current user has no assigned items
- THEN the "My Work" section MUST display "No items assigned to you"

---

### REQ-DB-060: Recent Activity Feed [V1]

The dashboard SHOULD display a feed of the 10 most recent CRM events across all entities.

#### Scenario: Activity feed with events
- GIVEN recent events: lead stage change (2 min ago), request assigned (15 min ago), new lead created (1 hour ago), client updated (3 hours ago)
- WHEN the user views the dashboard
- THEN the "Recent Activity" section MUST display up to 10 events in reverse chronological order
- AND each event MUST show: event description, relative timestamp (e.g., "2 min ago", "1 hour ago"), and the entity name
- AND a "View all activity" link MUST be provided

#### Scenario: Activity feed event types
- GIVEN CRM activity happening across entities
- THEN the activity feed MUST include events for:
  - Lead created, lead stage changed, lead assigned
  - Request created, request status changed, request assigned, request converted to case
  - Client created, client updated
  - Notes added to any entity
- AND each event type MUST have a distinguishable icon or label

#### Scenario: Activity feed with no events
- GIVEN a fresh installation with no activity
- WHEN the user views the dashboard
- THEN the activity feed MUST display an empty state message (e.g., "No recent activity")

---

### REQ-DB-070: Quick Actions [MVP]

The dashboard MUST provide quick action buttons for creating new entities.

#### Scenario: Quick create buttons
- WHEN the user is on the dashboard
- THEN quick action buttons for "New Lead", "New Request", and "New Client" MUST be visible
- AND clicking each button MUST navigate to the respective creation form

#### Scenario: Quick actions placement
- WHEN viewing the dashboard layout
- THEN quick action buttons MUST be in the header area near the KPI cards
- AND the buttons MUST be keyboard accessible

---

### REQ-DB-080: Dashboard Data Refresh [MVP]

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

---

### REQ-DB-090: Empty State (New Installation) [MVP]

The dashboard MUST handle fresh installations gracefully.

#### Scenario: Fresh installation dashboard
- WHEN no clients, leads, or requests exist
- THEN all KPI cards MUST show `0`
- AND charts MUST show empty states (not errors)
- AND the My Work preview MUST show "No items assigned to you"
- AND a welcome message SHOULD be displayed
- AND quick action buttons MUST be visible and functional

---

### REQ-DB-100: Dashboard Role-Based Views [V1]

The dashboard SHOULD adapt its emphasis based on user role or app configuration to serve both sales-focused and service-focused teams.

#### Scenario: Sales-focused dashboard
- GIVEN the user primarily works with leads and pipelines
- WHEN the dashboard is configured for sales focus (or the user's pipelines are sales-oriented)
- THEN the pipeline funnel and leads-by-source charts SHOULD be prominently displayed
- AND the pipeline value KPI SHOULD be emphasized

#### Scenario: Service-focused dashboard
- GIVEN the user primarily works with requests
- WHEN the dashboard is configured for service focus
- THEN the requests-by-status chart SHOULD be prominently displayed
- AND the open requests count SHOULD be emphasized

#### Scenario: Default balanced view
- GIVEN no specific role configuration
- WHEN the user views the dashboard
- THEN the system MUST display all sections (leads and requests) with equal weight
- AND the layout MUST follow the wireframe in DESIGN-REFERENCES.md section 3.1

---

## UI Layout Reference

The dashboard layout follows the wireframe in DESIGN-REFERENCES.md section 3.1:

```
Row 1: [KPI Card: Open Leads] [KPI Card: Open Requests] [KPI Card: Pipeline Value] [KPI Card: Overdue Items]
Row 2: [Pipeline Funnel (left, 60%)]  [Recent Activity (right, 40%)]
Row 3: [My Work Preview (left, 50%)]  [Recent Activity continued (right, 50%)]
Row 4: [Leads by Source (left, 50%)]  [Requests by Status (right, 50%)]
```

- KPI cards MUST be responsive and stack on narrow viewports
- Charts MUST be responsive and resize gracefully
- The overall layout MUST follow Nextcloud's content area patterns
- All text MUST meet WCAG AA contrast requirements
- Charts MUST have accessible alternatives (e.g., data tables for screen readers)

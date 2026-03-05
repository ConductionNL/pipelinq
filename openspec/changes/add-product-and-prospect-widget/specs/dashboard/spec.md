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

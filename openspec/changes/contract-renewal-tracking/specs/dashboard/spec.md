# Dashboard — Recurring Revenue Delta

**Spec refs**: `dashboard`, `contract-renewal-tracking`

## ADDED Requirements

### Requirement: MRR KPI Card

The main dashboard MUST include an MRR KPI card showing current MRR, ARR, and the MRR delta versus the previous period (new minus churned recurring value), computed by the recurring-revenue roll-up.

**Feature tier**: MVP

#### Scenario: MRR card reflects contract changes

- GIVEN a dashboard showing MRR €2,000
- WHEN a new €500/month contract is activated
- THEN the MRR card MUST show €2,500 on next load with a positive delta indicator

---

### Requirement: Renewals Due Widget

The main dashboard MUST include a "Renewals due" widget listing `expiring` contracts ordered by endDate, each showing client, contract title, normalized monthly value, endDate, and notice deadline, deep-linking to the contract detail view. The widget MUST render an empty state when no contracts are in their renewal window.

**Feature tier**: MVP

#### Scenario: Expiring contracts listed by urgency

- GIVEN three `expiring` contracts with different end dates
- WHEN the dashboard loads
- THEN the widget MUST list them ordered by soonest endDate first
- AND clicking an entry MUST open that contract's detail view

#### Scenario: Empty state

- GIVEN no contracts in their renewal window
- WHEN the dashboard loads
- THEN the widget MUST show an explanatory empty state instead of an empty list

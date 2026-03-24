# Dashboard Specification (Delta)

## Purpose

Add customer satisfaction KPI card and NPS trend widget to the Pipelinq CRM dashboard.

---

## MODIFIED Requirements

### Requirement: Dashboard KPI Cards (Modified)

The dashboard KPI row SHOULD include a satisfaction score card alongside existing KPI cards.

#### Scenario: Satisfaction KPI card
- **GIVEN** the dashboard loads and surveys with responses exist
- **WHEN** the KPI cards render
- **THEN** a "Customer Satisfaction" KPI card SHOULD display the average satisfaction score
- **AND** clicking the card SHOULD navigate to the surveys section

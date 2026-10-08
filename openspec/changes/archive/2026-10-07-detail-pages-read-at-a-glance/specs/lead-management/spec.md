# lead-management Specification (delta)

## ADDED Requirements

### Requirement: The lead page has one deal block (REQ-DPG-010)

LeadDetail SHALL show the lead's fields in one data widget titled "Deal",
the win chance (the qualification score) among them. The deal value KPI
SHALL format in the reporting currency.

#### Scenario: A salesperson opens a lead

- GIVEN a lead worth 250000 with score 55
- WHEN the salesperson opens it
- THEN one block titled "Deal" shows the value, stage, win chance, close date and owner
- AND the deal value KPI reads "€250,000"

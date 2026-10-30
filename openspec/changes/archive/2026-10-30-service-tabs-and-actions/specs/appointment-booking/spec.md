# service-tabs-and-actions Delta: appointment-booking

## ADDED Requirements

### Requirement: A service reads in Overview and History tabs

The service page SHALL show the service's details, rules and steps in an Overview tab and its audit trail in a History tab, with the Actions menu at the end of the tab row.

#### Scenario: Reading what changed on a service

- **GIVEN** the service Aanvraag paspoort
- **WHEN** an admin opens it and picks Geschiedenis
- **THEN** the page shows who changed the service and when, and the Acties menu stays at the end of the tab row

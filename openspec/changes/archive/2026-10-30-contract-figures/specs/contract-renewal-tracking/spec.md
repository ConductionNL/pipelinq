# contract-renewal-tracking Delta

## ADDED Requirements

### Requirement: A contract page reads its figures

The app SHALL answer a contract's figures to a user who may access the contract: the monthly and annual value, the notice date and the days to the end date, and for the current year the amount sent to invoicing, the number of sends and the number of billing periods that start within the year and the contract's term. Every send to invoicing SHALL be added to the contract's invoice history.

#### Scenario: PqContract CON-2026-0031

- **GIVEN** an annual contract of € 42.000 from 1 January to 31 December 2026, 60 days' notice, sent to invoicing on 2 January 2026
- **WHEN** the page is read on 7 October 2026
- **THEN** the tiles read € 3.500 per month from € 42.000 a year, 85 days with notice date 1 November, and € 42.000 invoiced this year, 1 of 1 invoices sent

# index-page (delta)

## ADDED Requirements

### Requirement: A board-look list starts with the columns that fit
A board-look list SHALL start with at most six data columns. Every other column it declares SHALL start hidden and stay available in the Columns menu.

#### Scenario: Website submissions
- WHEN a user opens the website submissions list
- THEN it shows when, title, reporter, organisation, source and status
- AND the page and the handler are offered in the Columns menu

#### Scenario: Contracts
- WHEN a user opens the contracts list
- THEN it shows number, title, client, status, value per interval and end date
- AND the billing period is offered in the Columns menu

#### Scenario: Export jobs
- WHEN a user opens the export jobs list
- THEN the next run is offered in the Columns menu and not shown

## ADDED Requirements

### Requirement: A desk works the walk-in queue from one page (REQ-APT-BOARD-004)
The app MUST offer a walk-in queue page listing today's walk-in tickets oldest first, with Call next calling the customer who has waited longest, and per row Call in for a waiting customer, Help for a called one and Left for either.

#### Scenario: Call next
@e2e exclude Handler behaviour asserted in tests/vitest/walkInQueue.spec.js; the e2e instance has no walk-in tickets.
- **GIVEN** two waiting customers
- **WHEN** the employee picks Call next
- **THEN** the customer who arrived first MUST become called

#### Scenario: Help a called customer
@e2e exclude Handler behaviour asserted in tests/vitest/walkInQueue.spec.js.
- **GIVEN** a called customer
- **WHEN** the employee picks Help
- **THEN** the ticket MUST be served with the time it was served

### Requirement: The resource page reads as its board (REQ-APT-BOARD-005)
The resource page MUST show the resource type and status as labelled pills, a breadcrumb to Resources, and working hours and vacations side by side.

#### Scenario: A staff resource
@e2e exclude Presentational; verified on the PqResource pixel check.
- **GIVEN** an active staff resource
- **WHEN** an employee opens it
- **THEN** the pills MUST read Staff and Active in the reader's language

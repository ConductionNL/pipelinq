## ADDED Requirements

### Requirement: The cash shift list reads as its board (REQ-POS-BOARD-001)
The cash shift list MUST show the chips All, Open and To review with their counts, the columns Shift, Cashier, Status, Opened, Closed, Opening balance and Deposits (the number of cash drops of the shift), and MUST keep opening a shift through the server's open endpoint.

#### Scenario: The open shifts chip
@e2e exclude Declarative quick filter on the cashShift status, read by CnIndexPage self-fetch; the e2e instance has no cash shifts.
- **GIVEN** shifts that are open, closed and reconciled
- **WHEN** a cashier picks the Open chip
- **THEN** the list MUST show only the open shifts

#### Scenario: Opening a shift
@e2e exclude Unchanged behaviour of the open endpoint, covered by its PHP unit tests.
- **GIVEN** the cash shift list
- **WHEN** the cashier picks Open shift and declares a float
- **THEN** the shift MUST be created by the open endpoint and its page MUST open

### Requirement: The transactions and Z-report lists read as their boards (REQ-POS-BOARD-002)
The transactions list MUST show the chips All, Today, Parked and Refunded with counts and the created time; the Z-reports list MUST be titled Z-reports and show the chips All, Not booked and Failed, with the status as its label.

#### Scenario: Today's transactions
@e2e exclude Declarative quick filter on the created date; asserted on the built manifest.
- **GIVEN** transactions created today and earlier
- **WHEN** a cashier picks the Today chip
- **THEN** the list MUST show only the transactions created today

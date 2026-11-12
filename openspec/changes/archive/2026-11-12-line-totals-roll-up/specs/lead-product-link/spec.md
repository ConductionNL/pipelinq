## ADDED Requirements

### Requirement: The server keeps the lead value with its lines

When a lead line is created, changed or deleted, the system MUST write the lead's value as the sum of its line totals. A lead without lines MUST keep the value typed by hand.

#### Scenario: A line is added

- GIVEN a lead with no lines and no value
- WHEN a line with total 3.000 is added
- THEN the lead's value MUST be 3.000

#### Scenario: The last line is removed

- GIVEN a lead whose value is 3.000 from its one line
- WHEN that line is deleted
- THEN the lead's value MUST stay 3.000, for the user to change by hand

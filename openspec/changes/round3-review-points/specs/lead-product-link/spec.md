## ADDED Requirements

### Requirement: A line item is named after its product (REQ-R3-005)

A deal line item SHALL carry the name of its product as its object name, so
the deal's Related card lists the product, not "Lead Product" or a uuid.

#### Scenario: Add a line to a deal

@e2e exclude The template is asserted on the merged register in tests/Unit/Settings/ReviewRound3RegisterTest.php; OpenRegister resolves it on save, checked live.
- GIVEN a deal and the product "Adviesuur"
- WHEN the user adds a line for "Adviesuur"
- THEN the line item's name SHALL be "Adviesuur"

### Requirement: The line item count follows a new line (REQ-R3-006)

After a user adds a line item, the deal page SHALL show the new count without
a reload.

#### Scenario: The count after adding a line

@e2e exclude Asserted in tests/vitest/pageRefreshOnCreate.spec.js: a created line item broadcasts the page refresh the stats widget listens to.
- GIVEN a deal with no line items
- WHEN the user adds a line
- THEN the Line items count SHALL read 1

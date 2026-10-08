## ADDED Requirements

### Requirement: A pipelinq notification opens its record (REQ-R3-007)

Every notification rule pipelinq declares SHALL carry an "Open" action whose
target is the object's detail page.

#### Scenario: Open a changed client

@e2e exclude Asserted on the merged register in tests/Unit/Settings/ReviewRound3RegisterTest.php; OpenRegister resolves the target to pipelinq's deep link, checked live.
- GIVEN a client whose owner is told about a change
- WHEN the owner opens the notification's "Open" action
- THEN pipelinq SHALL show the client's detail page

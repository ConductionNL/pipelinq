# notifications delta: create-sales-group

## ADDED Requirements

### Requirement: The group the notification rules address exists (REQ-RAF-071)
pipelinq MUST create the Nextcloud group `sales`, display name "Sales", on
install and after every upgrade when it does not exist. When the group exists,
pipelinq MUST NOT change it, and pipelinq MUST NEVER add or remove its members.
Every group a pipelinq notification rule addresses (newContact, newLead,
leadWon, newEnquiry, newTicket) MUST be a group this step creates.

#### Scenario: An instance without the group
@e2e exclude Asserted in tests/Unit/Repair/CreateSalesGroupTest.php with the real IGroupManager contract mocked; a repair step has no browser surface.
- **GIVEN** an instance with no group `sales`
- **WHEN** pipelinq is installed or upgraded
- **THEN** the group `sales` MUST exist with display name "Sales"
- **AND** the log MUST say it was created
- **AND** it MUST have no members added by pipelinq

#### Scenario: An instance that already has the group
@e2e exclude Asserted in tests/Unit/Repair/CreateSalesGroupTest.php.
- **GIVEN** an instance whose group `sales` exists, with members
- **WHEN** pipelinq is upgraded
- **THEN** the group, its display name and its members MUST stay as they were

#### Scenario: A rule addresses a group nobody creates
@e2e exclude Asserted in tests/Unit/Repair/CreateSalesGroupTest.php on the merged register.
- **GIVEN** a developer adds a notification rule addressed to another group
- **WHEN** the unit tests run
- **THEN** they MUST fail until that group is created too

# notifications Specification

## Purpose
Tells the people responsible for a record when it changes. An update to a client, lead, ticket or task notifies the users in its owner and assignee fields through OpenRegister's notification rules, so pipelinq sends nothing imperatively.

## Requirements

### Requirement: An update notifies the object's owner and assignee (REQ-RAF-060)

When a client, lead, ticket or task is updated, the system SHALL send an in-app
notification to the users in its owner and assignee fields: `accountOwner` on a
client, `assignee` on a lead and a ticket, `assigneeUserId` on a task. The rules
SHALL use the canonical `x-openregister-notifications` dialect with a subject in
English and Dutch. The person who made the change MAY be notified too, until
OpenRegister can leave the actor out (Ruben, 7 October 2026). A schema without
an owner or assignee field gets no update rule.

#### Scenario: A colleague changes a client

- GIVEN a client whose account owner is Marieke
- WHEN Pieter changes the client's phone number
- THEN Marieke gets the notification "Client changed: <name>"

#### Scenario: A lead is reassigned

- GIVEN a lead
- WHEN its assignee is set to Joris and the lead is saved
- THEN Joris gets the notification "Lead changed: <title>"

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

### Requirement: pipelinq runs a library that shows the rule labels (REQ-RAF-072)
pipelinq MUST depend on a version of `@conduction/nextcloud-vue` whose
CnAppRoot declares the `notificationLabels` prop, so the labels REQ-RAF-070
asks for reach the notification preferences.

#### Scenario: A user opens their notification preferences
@e2e exclude Checked live on a local instance for this change; the label lookup itself is the library's notificationRuleLabel, tested in nextcloud-vue.
- **GIVEN** pipelinq runs @conduction/nextcloud-vue 2.73.1
- **WHEN** a user opens Notifications in the user settings
- **THEN** they MUST read "A new lead comes in"
- **AND** never `newLead`

### Requirement: A pipelinq notification opens its record (REQ-R3-007)

Every notification rule pipelinq declares SHALL carry an "Open" action whose
target is the object's detail page.

#### Scenario: Open a changed client

@e2e exclude Asserted on the merged register in tests/Unit/Settings/ReviewRound3RegisterTest.php; OpenRegister resolves the target to pipelinq's deep link, checked live.
- GIVEN a client whose owner is told about a change
- WHEN the owner opens the notification's "Open" action
- THEN pipelinq SHALL show the client's detail page

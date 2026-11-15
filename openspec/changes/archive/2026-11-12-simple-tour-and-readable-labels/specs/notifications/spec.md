# notifications delta: simple-tour-and-readable-labels

## ADDED Requirements

### Requirement: Notification preferences name each rule in words (REQ-RAF-070)
pipelinq MUST hand CnAppRoot a translated label for every notification rule
its register declares (`notificationLabels`, keyed `<schema>.<rule>`). Every
label MUST have an en and an nl catalogue entry. The notification preferences
in the user settings MUST show that label, never the rule key.

#### Scenario: A user reads their notification preferences
@e2e exclude Asserted in tests/vitest/notificationLabels.spec.js on the merged register; rendering the label is the library's CnNotificationPreferences (nextcloud-vue change notification-rule-labels-and-runtime-version).
- **GIVEN** pipelinq declares the rules `newLead` on lead and `clientUpdated` on client
- **WHEN** a user opens Notifications in the user settings
- **THEN** they MUST read "A new lead comes in" and "A client I own changes"
- **AND** never `newLead` or `clientUpdated`

#### Scenario: A rule added later
@e2e exclude Asserted in tests/vitest/notificationLabels.spec.js: a rule without a label fails the spec.
- **GIVEN** a developer adds a notification rule to the register
- **WHEN** the unit tests run
- **THEN** they MUST fail until the rule has a label with en and nl entries

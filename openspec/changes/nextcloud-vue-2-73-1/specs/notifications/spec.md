# notifications delta: nextcloud-vue-2-73-1

## ADDED Requirements

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

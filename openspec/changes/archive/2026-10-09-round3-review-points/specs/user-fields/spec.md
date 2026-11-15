## ADDED Requirements

### Requirement: A user field shows the user's display name (REQ-R3-002)

Wherever pipelinq shows a Nextcloud user field in a list column or in a data
widget, it SHALL show the user's display name, not the uid. A uid nobody
answers to SHALL stay visible as it is.

#### Scenario: A task assigned to a user

@e2e exclude Asserted in tests/vitest/userDisplayName.spec.js: the formatter, and every user field of every manifest column and data widget wired to it.
- GIVEN a task whose assignee is the user `cluade` with display name "claude"
- WHEN the user opens the task list or the task page
- THEN the assignee SHALL read "claude"

### Requirement: A new task records who created it (REQ-R3-003)

When a task is created without `createdBy`, pipelinq SHALL fill it with the
user who creates it. A value that is already given SHALL be kept.

#### Scenario: Create a task

@e2e exclude Asserted in tests/Unit/Listener/TaskCreatedByCreatingListenerTest.php with OpenRegister's real ObjectCreatingEvent.
- GIVEN a signed-in user `cluade`
- WHEN the user creates a task without a creator
- THEN the task's `createdBy` SHALL be `cluade`

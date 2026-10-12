# Delta: notifications-activity

## ADDED Requirements

### Requirement: A task notification opens the task in pipelinq
Every schema that sends a notification and has a pipelinq detail page MUST
declare a manifest deep link to that page, so OpenRegister links the
notification there and not to its generic object view.

#### Scenario: Task changed
@e2e exclude Asserted in tests/vitest/round5ContactCreateAndTaskLinks.spec.js and live on :8099.
- **GIVEN** a task assigned to a user is changed
- **WHEN** the user opens the "Task changed" notification
- **THEN** the link MUST be `/apps/pipelinq/tasks/<uuid>`

### Requirement: A task is named after its subject
A task's object name MUST be its subject, so the activity stream and the task
page heading name the task and not its uuid or its type.

#### Scenario: Task activity and heading
@e2e exclude Asserted in tests/vitest/round5ContactCreateAndTaskLinks.spec.js and live on :8099.
- **GIVEN** a task with the subject "Call back about the permit"
- **WHEN** the task is saved
- **THEN** the activity MUST read "Task Call back about the permit updated"
- **AND** the task page heading MUST read "Call back about the permit"

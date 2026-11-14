# Delta: repair-steps

## ADDED Requirements

### Requirement: Existing tasks are named after their subject without telling anyone
On upgrade, every task whose name is empty or still its uuid MUST be saved once
so OpenRegister names it after its subject. The save MUST NOT send a
notification, write an activity entry or write an audit trail row. A task that
already has a name MUST NOT be saved.

#### Scenario: A task without a name gets its subject
@e2e exclude Runs inside occ upgrade, which has no browser surface. Asserted in tests/Unit/Service/TaskNameBackfillServiceTest.php and live on :8099.
- **GIVEN** a task with the subject "Call back about the permit" whose name is its uuid
- **WHEN** the repair step runs
- **THEN** the task MUST be saved with its own data and its uuid
- **AND** its name MUST read "Call back about the permit"

#### Scenario: A task with a name is left alone
@e2e exclude Same reason. Asserted in tests/Unit/Service/TaskNameBackfillServiceTest.php.
- **GIVEN** a task whose name is not empty and not its uuid
- **WHEN** the repair step runs
- **THEN** the task MUST NOT be saved

#### Scenario: Nobody is told
@e2e exclude Same reason. Asserted in tests/Unit/Service/TaskNameBackfillServiceTest.php and live on :8099 through the notifications and activity APIs.
- **GIVEN** a task without a name, assigned to a user
- **WHEN** the repair step names it
- **THEN** the save MUST run inside OpenRegister's system scope, where it withholds the object update event
- **AND** the save MUST pass `silent: true`
- **AND** the assignee MUST NOT get a notification
- **AND** no activity entry MUST appear

#### Scenario: No silent path, no write
@e2e exclude Same reason. Asserted in tests/Unit/Service/TaskNameBackfillServiceTest.php.
- **GIVEN** an OpenRegister that cannot withhold the object update event, or a system scope that is not active at the write
- **WHEN** the repair step runs
- **THEN** no task MUST be saved
- **AND** the step MUST say why

#### Scenario: A second run changes nothing
@e2e exclude Same reason. Asserted in tests/Unit/Service/TaskNameBackfillServiceTest.php and live on :8099.
- **GIVEN** the repair step has named every task
- **WHEN** it runs again
- **THEN** no task MUST be saved

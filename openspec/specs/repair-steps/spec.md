# Repair steps

## Purpose

Repair steps run during `occ upgrade`. They are the only place this app rewrites
stored data outside a user request, and the environment they run in differs from
every other execution path in one way that silently changes the outcome.

## Requirements

### Requirement: REQ-RS-001 A repair step that writes to OpenRegister MUST run under a system identity

`occ upgrade` has **no user session**. OpenRegister resolves the acting user per
write, and with no session it resolves to `Anonymous` and refuses the write. A
repair step that calls `saveObject()` directly therefore migrates **nothing**.

This does not surface as a failure. `$output->warning()` does not fail an
upgrade, so the run finishes and prints `Update successful` while the migration
reports objects "skipped". The defect is indistinguishable from a step that
correctly found nothing to do.

A repair step that writes to OpenRegister MUST wrap its work in
`ObjectService::runAsSystem()`, which establishes the system identity for the
duration of the callback.

#### Scenario: Work is wrapped in the system identity when the capability is present

@e2e exclude — runs inside `occ upgrade`, which has no browser surface. Covered
by unit tests over the trait and its callers.

- **GIVEN** a repair step holding an OpenRegister `ObjectService`
- **WHEN** it performs work that writes objects
- **THEN** the work MUST be invoked inside `$objectService->runAsSystem(...)`
- **AND** the writes MUST be attributed to the system identity, not `Anonymous`

#### Scenario: The step still runs when OpenRegister is absent or older

@e2e exclude — same reason; this is an upgrade-time path.

- **GIVEN** the `ObjectService` is `null`, or is a build predating `runAsSystem()`
- **WHEN** the repair step runs
- **THEN** the work MUST still be executed directly, not skipped
- **AND** the step MUST NOT fatal on the missing method

The fall-through is deliberate. OpenRegister is an optional cross-app dependency
resolved from the container, and `runAsSystem` is a capability a given installed
version may not have. Guarding with `method_exists()` and running the work
anyway keeps the step correct on an instance where the writes do not need the
system identity, instead of turning an optional dependency into a hard one.

### Requirement: REQ-RS-002 A test fake MUST model the identity contract

A fake `ObjectService` that omits `runAsSystem()` cannot fail when production
code violates REQ-RS-001 — the guard falls through, the work runs, and the test
passes. A green suite over such a fake is evidence of nothing.

Where a repair step's tests supply a fake `ObjectService`, that fake MUST
implement `runAsSystem()` and MUST invoke the callback it is given.

#### Scenario: The fake invokes the callback rather than swallowing it

@e2e exclude — unit-level contract, no browser surface.

- **GIVEN** a test double standing in for OpenRegister's `ObjectService`
- **WHEN** `runAsSystem()` is called with a callback
- **THEN** the double MUST execute that callback
- **AND** a double that returns without invoking it MUST be treated as a defect
  in the test, because it makes a correct implementation look broken and a
  broken one look correct

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

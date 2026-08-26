# Repair steps

Repair steps run during `occ upgrade`. They are the only place this app rewrites
stored data outside a user request, and the environment they run in differs from
every other execution path in one way that silently changes the outcome.

## Requirement: REQ-RS-001 A repair step that writes to OpenRegister MUST run under a system identity

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

### Scenario: Work is wrapped in the system identity when the capability is present

@e2e exclude — runs inside `occ upgrade`, which has no browser surface. Covered
by unit tests over the trait and its callers.

- **GIVEN** a repair step holding an OpenRegister `ObjectService`
- **WHEN** it performs work that writes objects
- **THEN** the work MUST be invoked inside `$objectService->runAsSystem(...)`
- **AND** the writes MUST be attributed to the system identity, not `Anonymous`

### Scenario: The step still runs when OpenRegister is absent or older

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

## Requirement: REQ-RS-002 A test fake MUST model the identity contract

A fake `ObjectService` that omits `runAsSystem()` cannot fail when production
code violates REQ-RS-001 — the guard falls through, the work runs, and the test
passes. A green suite over such a fake is evidence of nothing.

Where a repair step's tests supply a fake `ObjectService`, that fake MUST
implement `runAsSystem()` and MUST invoke the callback it is given.

### Scenario: The fake invokes the callback rather than swallowing it

@e2e exclude — unit-level contract, no browser surface.

- **GIVEN** a test double standing in for OpenRegister's `ObjectService`
- **WHEN** `runAsSystem()` is called with a callback
- **THEN** the double MUST execute that callback
- **AND** a double that returns without invoking it MUST be treated as a defect
  in the test, because it makes a correct implementation look broken and a
  broken one look correct

# OpenRegister integration

## ADDED Requirements

### Requirement: The appointment resource is namespaced (REQ-ORI-043)

The bookable resource schema SHALL be `appointmentResource` and SHALL NOT be
`resource`.

A schema slug is global per organisation and `SchemaMapper::find()` matches
`LOWER(slug)`, so a bare `resource` was answered for by shillinq's as readily as
by this app's. shillinq's bookings subsystem is the larger claimant and keeps
the bare slug.

The rename SHALL NOT touch `resource` where it is a log-context key, nor the
ZGW NRC notification field `$notification['resource']`. The latter names a ZGW
resource type and is part of the NRC contract.

A repair step SHALL rename the row IN PLACE before the register import, scoped
to this app's own rows.

The config key SHALL remain `resource_schema`.

#### Scenario: The slug is renamed in place

- **GIVEN** an install carrying a pipelinq-owned `resource` schema
- **WHEN** the repair step runs
- **THEN** the row keeps its schema id, and so its shard table and objects.

#### Scenario: The NRC notification field is untouched

- **WHEN** an NRC notification is handled
- **THEN** its `resource` field is still read under that name.

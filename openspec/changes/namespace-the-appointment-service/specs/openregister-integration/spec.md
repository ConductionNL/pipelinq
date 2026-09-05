# OpenRegister integration

## ADDED Requirements

### Requirement: The appointment service is namespaced (REQ-ORI-046)

The bookable service schema SHALL be `appointmentService` and SHALL NOT be
`service`. shillinq keeps the bare slug; stackiq uses `catalogService`.

The three claiming schemas share `name` alone, so all three are renamed apart
rather than folded onto one owner.

The rename SHALL NOT touch `service` where it is a DI container key, a template
context key, a product type, a complaint category, a GDPR processing intent, or
a journey message type.

A repair step SHALL rename the row IN PLACE before the register import, scoped
to this app's own rows.

The config key SHALL remain `service_schema`.

#### Scenario: The slug is renamed in place

- **GIVEN** an install carrying a pipelinq-owned `service` schema
- **WHEN** the repair step runs
- **THEN** the row keeps its schema id, and so its shard table and objects.

#### Scenario: A product typed as a service is untouched

- **WHEN** catalogue items are classified by type
- **THEN** a product of type `service` is still recognised.

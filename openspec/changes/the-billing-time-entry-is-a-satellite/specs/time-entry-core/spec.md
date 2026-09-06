# Time entry core

## ADDED Requirements

### Requirement: The billing time entry is named for what it is (REQ-TEC-030)

The schema SHALL be `billingTimeEntry`, not `timeEntry`.

A schema slug is global per organisation and `SchemaMapper::find()` matches
`LOWER(slug)`, so three apps declaring a `timeEntry` meant whichever row was
reached first answered for all three: humaniq's HR booking, planninq's project
booking and this app's billing/WIP record.

humaniq owns the hours. This record is the BILLING side of one — client, lead,
billing category, approval, WIP sync, invoice batch — and SHALL carry a
`timeEntry` reference to the humaniq booking it bills.

That reference SHALL be a plain uuid string and SHALL NOT be a `$ref`.
humaniq's register is a different register, and ADR-062 rule 7 gives a
cross-register target no `$ref`.

The app-config KEY `timeEntry_schema` SHALL NOT move. It is live persisted
state, and the same split already applies to `klantLoyaltyAccount_schema`.

#### Scenario: The register declares the namespaced slug

- **WHEN** the register fragments are read
- **THEN** the schema key and its `slug` are both `billingTimeEntry`, and no
  `timeEntry` schema is declared.

#### Scenario: The billing line names the booking it bills

- **WHEN** the merged `billingTimeEntry` is inspected
- **THEN** it carries a `timeEntry` property of type string, format uuid, with
  no `$ref`.

### Requirement: The rename SHALL be migrated on an existing install (REQ-TEC-031)

A repair step SHALL rename the schema row's slug in place, before
`InitializeSettings` imports the register.

OpenRegister matches an existing schema by `(application, slug)` and CREATES a
new one when that misses, so renaming the slug in the shipped fragment alone
renames nothing: it orphans the old schema and every object already written
against it, silently, and the app reads an empty collection.

It SHALL be idempotent, and SHALL refuse rather than guess when both slugs
exist or the old slug is duplicated — either choice would decide which set of
objects to abandon.

#### Scenario: An existing install is renamed in place

- **GIVEN** a schema row with slug `timeEntry` under application `pipelinq`
- **WHEN** the repair step runs
- **THEN** that row's slug is `billingTimeEntry` and its objects stay attached.

#### Scenario: Both slugs present is refused

- **GIVEN** rows carrying both slugs
- **WHEN** the repair step runs
- **THEN** no row is written and the refusal is logged.

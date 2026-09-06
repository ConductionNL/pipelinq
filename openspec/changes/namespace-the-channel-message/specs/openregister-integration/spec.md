# OpenRegister integration

## ADDED Requirements

### Requirement: The channel message is namespaced (REQ-ORI-044)

The channel message schema SHALL be `channelMessage` and SHALL NOT be
`message`. hermiq is the messaging app and keeps the bare slug.

A schema slug is global per organisation and `SchemaMapper::find()` matches
`LOWER(slug)`, so a bare `message` was answered for by hermiq's as readily as by
this app's.

The rename SHALL NOT touch `messageTemplate` or `messageSendBudget`. Neither is
claimed by another app, and a prefix match would take both.

The rename SHALL NOT touch `message` where it is an exception message, a log
line, an i18n string, a notification body, or the lead schema's free-text
`message` property.

A repair step SHALL rename the row IN PLACE before the register import, scoped
to this app's own rows.

#### Scenario: The slug is renamed in place

- **GIVEN** an install carrying a pipelinq-owned `message` schema
- **WHEN** the repair step runs
- **THEN** the row keeps its schema id, and so its shard table and objects.

#### Scenario: The sibling slugs are untouched

- **WHEN** the object-type map is read
- **THEN** `messageTemplate` and `messageSendBudget` still carry their slugs.

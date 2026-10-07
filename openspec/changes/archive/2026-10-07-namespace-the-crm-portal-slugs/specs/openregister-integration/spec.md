# OpenRegister integration

## ADDED Requirements

### Requirement: The CRM portal slugs are namespaced (REQ-ORI-042)

The portal account schema SHALL be `crmPortalAccount` and the portal session
schema SHALL be `crmPortalSession`. Neither SHALL be `portalAccount` or
`portalSession`.

A schema slug is global per organisation and `SchemaMapper::find()` matches
`LOWER(slug)`, so both were answered for by portaliq's schemas as readily as by
this app's. portaliq owns the portal and keeps the bare slugs.

They SHALL be renamed apart and SHALL NOT be folded onto portaliq's. The two
account schemas describe different records about one person: portaliq's is an
OIDC identity projection, this app's is a local credential store. A shared email
address is a contact attribute, not an identifier of the record type.

A repair step SHALL rename each row IN PLACE before the register import, scoped
to this app's own rows.

The config keys SHALL remain `portalAccount_schema` and `portalSession_schema`.
They are live persisted state.

#### Scenario: Both slugs are renamed in place

- **GIVEN** an install carrying `portalAccount` and `portalSession`
- **WHEN** the repair step runs
- **THEN** each row keeps its schema id, and so its shard table and objects.

#### Scenario: The config keys do not move

- **WHEN** the imported schema ids are stored
- **THEN** they are written under `portalAccount_schema` and
  `portalSession_schema`.

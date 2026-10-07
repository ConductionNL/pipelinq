# contactmomenten Specification (delta)

---
status: proposed
---

## Purpose

A contact moment carries its direction as a field, points at a case by a
semantic reference, and is listed and logged on that case through a
pipelinq leaf. One schema for the fleet. Requested by the dossiq
competitor analysis, register row 6.2.

## ADDED Requirements

### Requirement: Direction is a first-class field (REQ-CMD-001)

The contact moment facet of `ticket` MUST carry `direction` (enum
`inbound`, `outbound`, `internal`, facetable), required when `ticketType =
contactmoment`. A repair step MUST fill it from `channelMetadata.direction`
or `channelMetadata.richting` on existing rows, mapping `inkomend` to
`inbound` and `uitgaand` to `outbound`, else `internal`, idempotently.

**Feature tier**: V1

#### Scenario: A contact moment without a direction is refused

- **WHEN** a contact moment is created with channel `telefoon` and no `direction`
- **THEN** OpenRegister rejects it naming `direction`
- @e2e exclude server validation; covered by PHPUnit on the register import

#### Scenario: Existing rows are migrated once

- **GIVEN** a contact moment with `channelMetadata.richting = inkomend` and no `direction`
- **WHEN** the repair step runs twice
- **THEN** the row carries `direction = inbound` after the first run and is unchanged by the second
- @e2e exclude repair step; covered by PHPUnit on `MigrateContactMomentDirection`

### Requirement: A contact moment references a case semantically (REQ-CMD-002)

`ticket.caseReference` MUST be an ADR-048 semantic reference to the
`case` type, allowed on `request` and `contactmoment`, and MUST NOT name
an app id or a concrete slug. Existing UUID values MUST stay valid.

**Feature tier**: V1

#### Scenario: A contact moment on a dossiq case resolves

- **GIVEN** dossiq supplies the `case` semantic type and a contact moment with `caseReference` = a case id
- **WHEN** the reference is resolved
- **THEN** the case's title renders on the contact moment without pipelinq naming dossiq
- @e2e exclude resolver contract; covered by PHPUnit with a stub provider

### Requirement: Contact moments are a data-provider leaf with append (REQ-CMD-003)

Pipelinq MUST register `pipelinq-contact-moments` (kind `data-provider`,
storage `app-local`) through `RegisterLeafProvidersEvent`. `list` MUST
return the host object's contact moments newest first with subject,
channel, direction, agent, time, outcome and summary. `create` MUST
append one through `TicketService` with the host as `caseReference` and
the caller as `agent`, MUST require `direction`, MUST refuse a caller
without read on the host, and MUST NOT call any action in the consuming
app (ADR-066 decision 2).

**Feature tier**: V1

#### Scenario: A handler logs a call on a case

- **GIVEN** dossiq places the leaf and a handler opens a case
- **WHEN** the handler logs an `inbound` `telefoon` contact moment
- **THEN** one contact moment ticket exists with `caseReference` = the case, `agent` = the handler, `direction = inbound`, and `list` returns it first
- e2e: `tests/e2e/contact-moments-leaf.spec.ts`

#### Scenario: A caller without read on the case is refused

- **GIVEN** a user who may not read the case
- **WHEN** that user calls `create`
- **THEN** the provider answers 403 and no ticket exists
- @e2e exclude authorization guard; covered by PHPUnit on `ContactMomentLeafProvider::create()`

### Requirement: A panel renders the contact moments on the host (REQ-CMD-004)

Pipelinq MUST register `pipelinq-contact-moments-panel` (kind
`render-surface`, `widget` and `tab` under one id). The tab MUST be the
contact moment list filtered to the host with Channel and Direction
facets; the widget MUST show the latest five and the quick-log form with
`direction` as a required choice. The `contactmomenten` list view MUST
gain a Direction facet.

**Feature tier**: V1

#### Scenario: The Communication tab shows both directions

- **GIVEN** a case with one `inbound` and one `outbound` contact moment
- **WHEN** a handler opens the tab and picks the `outbound` facet
- **THEN** only the outbound one is listed
- e2e: `tests/e2e/contact-moments-leaf.spec.ts`

#### Scenario: Descriptor and JS registration agree

- **GIVEN** both leaves are registered
- **WHEN** gate-24 inspects the app
- **THEN** each id has a descriptor and a JS registration with the complete render pair
- @e2e exclude parity is checked mechanically by gate-24

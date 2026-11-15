# Unify Ticket Supertype — Spec

**Spec refs**: `contactmomenten`, `klachtenregistratie`, `omnichannel-registratie`, `kcc-werkplek`, `sla-engine`, `cti-screenpop-adapter`, `zgw-api-bridge`, ADR-000, ADR-031, ADR-062
**Standards**: VNG Klantinteracties (`Contactmoment`), Schema.org (`Demand`, `Message`, `CommunicateAction`)

## ADDED Requirements

### Requirement: Ticket Supertype Schema

The system MUST register a single `ticket` schema in the pipelinq register with a
required `ticketType` discriminator (`request` | `complaint` | `contactmoment`)
and the union of fields from the former `request`, `complaint` and
`contactmoment` schemas, preserving each type's Schema.org identity via an
`x-schema-org-by-type` marker.

**Feature tier**: MVP

#### Scenario: Schema registration

- WHEN the register import runs
- THEN the `ticket` schema MUST exist with `ticketType` and the unioned fields
- AND its ID MUST be recorded in SchemaMapService and a `ticket_schema` config key

#### Scenario: Type-specific field relevance

- GIVEN a `ticket` with `ticketType=contactmoment`
- WHEN it is rendered
- THEN the contactmoment fields (channel, outcome, duration, channelMetadata, parentTicket) MUST be shown
- AND the request-only fields (pipeline, stage, queue, caseReference) MUST be hidden

---

### Requirement: Lossless Migration Of Existing Records

An idempotent repair step MUST copy every existing `request`, `complaint` and
`contactmoment` object into a `ticket` with the correct `ticketType`, mapping all
fields without data loss, and MUST leave the source objects intact until a
separate retirement change.

**Feature tier**: MVP

#### Scenario: Count parity after migration

- GIVEN N requests, M complaints and K contactmomenten exist
- WHEN the migration repair step runs
- THEN exactly N+M+K `ticket` objects MUST exist
- AND re-running the step MUST NOT create duplicates

#### Scenario: Contactmoment parent linkage preserved

- GIVEN a contactmoment whose `request` points at request R
- WHEN migration runs
- THEN the produced ticket MUST have `ticketType=contactmoment` and `parentTicket` equal to the new ticket UUID of R

#### Scenario: Task foreign key remapped

- GIVEN a task whose `requestId` points at request R
- WHEN migration runs
- THEN the task's `requestId` MUST be updated to R's new ticket UUID

#### Scenario: Attachments and threads survive

- GIVEN a complaint with a linked mail thread and an attached file
- WHEN migration runs
- THEN the mail-link and file-link MUST resolve against the produced ticket UUID

---

### Requirement: Unified Tickets Workspace

The three navigation entries (Tickets/Requests, Complaints, Contactmomenten) MUST
be replaced by a single **Tickets** entry backed by the `ticket` schema, with a
`ticketType` facet and a type-aware detail page.

**Feature tier**: MVP

#### Scenario: One nav entry with a type facet

- WHEN a user opens pipelinq
- THEN exactly one Tickets navigation entry MUST be present (no separate Complaints or Contactmomenten entry)
- AND the Tickets index MUST offer a `ticketType` facet filtering to request / complaint / contactmoment

#### Scenario: Contactmomenten reporting reads tickets

- WHEN the contactmomenten reporting page loads
- THEN its KPIs and channel distribution MUST be computed from `ticket` objects filtered to `ticketType=contactmoment`

---

### Requirement: Create Surfaces Write Tickets

The system MUST route every surface that previously created a `request`,
`complaint` or `contactmoment` — CTI screen-pop, omnichannel registration, the
SLA/klacht flow, notifications, and the ZGW bridge — to create a `ticket` with
the appropriate `ticketType` instead.

**Feature tier**: MVP

#### Scenario: Omnichannel registration creates a contactmoment ticket

- WHEN an agent registers a contact via the omnichannel form
- THEN a `ticket` with `ticketType=contactmoment` and the selected channel MUST be created

#### Scenario: Klacht flow creates a complaint ticket

- WHEN a klacht is registered
- THEN a `ticket` with `ticketType=complaint`, its `complaintCategory` and `slaDeadline` MUST be created

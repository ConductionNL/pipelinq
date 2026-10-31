# unify-ticket-supertype Specification

## Purpose
One ticket for every customer matter: a request, a complaint and a logged contact moment are the same object with a type, so one list, one detail page and one set of notifications serve all three, and each type shows only the fields that belong to it.

## Requirements

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

### Requirement: The website submissions are a lens over form tickets

pipelinq SHALL offer a page listing the tickets whose `source` names a form, newest first, with the lenses Not linked yet
(no client, the default), Linked (a client), Closed and All.

#### Scenario: A new catering request

- **GIVEN** the contact form created a ticket from Joke Smit of Bakkerij De Graaf, without a client
- **WHEN** a colleague opens Website submissions
- **THEN** the ticket is listed under Not linked yet with Joke Smit, Bakkerij De Graaf, website-contact and /contact

### Requirement: A colleague links a submission to a client and a contact in one step

On a ticket without a client, Link to a client SHALL show the submission as sent and SHALL let the colleague choose, for
the client, a new client named after the typed organisation or an existing client, and for the contact person, a new
contact from the typed name and e-mail or an existing contact, suggesting an existing client with the typed organisation
name and an existing contact with the typed e-mail. Confirming SHALL create what is new, link the ticket's `client` and
`contact`, and, when asked, create a lead for that client and contact in the default pipeline. Every write SHALL run as
the signed-in colleague.

#### Scenario: A new client, a known contact

- **GIVEN** no client named Bakkerij De Graaf and a contact with joke@bakkerijdegraaf.nl
- **WHEN** the colleague keeps "New client Bakkerij De Graaf", picks the suggested contact and asks for a lead
- **THEN** the client is created, the ticket points at it and at the existing contact, and a lead "Catering voor de opening van onze nieuwe winkel" exists for both

### Requirement: The Contact moments tab has a title row

The ticket's Contact moments tab SHALL draw a title row with the title, the line "{total} on this ticket, newest first" under it and Log contact as an outlined button with a phone icon beside it. Log contact SHALL not also be drawn under the table.

#### Scenario: A ticket with two contact moments

- **GIVEN** a ticket with two contact moments
- **WHEN** the user opens the Contactmomenten tab
- **THEN** the panel reads "Contactmomenten", "2 bij dit ticket, nieuwste eerst" and "Contact vastleggen" in its title row, and no button follows the table

### Requirement: The tickets list assigns a selection to one handler

The tickets list SHALL offer "Assign to" first in its bulk band. It SHALL open CnAssignDialog with the selection, a user search, a required reason and `bulkJob: { register: "pipelinq", schema: "ticket" }`, so OpenRegister's assign job writes `assignee` on each selected ticket after checking the user may update it.

#### Scenario: Three tickets go to Fatma Yildiz

- **GIVEN** three tickets selected on the tickets list
- **WHEN** the user clicks "Toewijzen aan", picks Fatma Yildiz, writes a reason and applies the rehearsed job
- **THEN** the three tickets name Fatma Yildiz as handler, each with the reason in its audit trail

#### Scenario: A ticket the user may not change

- **GIVEN** a selection with one ticket whose schema update rule does not include the user
- **WHEN** the job runs
- **THEN** that ticket is reported as refused and keeps its handler

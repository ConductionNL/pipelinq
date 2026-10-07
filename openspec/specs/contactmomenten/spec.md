# Contactmomenten Specification

**OpenSpec changes**: [vng-klantinteracties-leaf](../../changes/archive/2026-07-12-vng-klantinteracties-leaf/) _(archived 2026-07-12 — maps VNG `klantcontact` onto the `contactmoment` subtype of the unified `ticket` schema; see [vng-klantinteracties-leaf](../vng-klantinteracties-leaf/spec.md) for the VNG ↔ canonical contract)_

## Purpose

Contactmomenten (contact moments) provide the core CRUD, list/detail views, quick-log form, and client/request integration for logging every client interaction. This capability sits between `omnichannel-registratie` (channel-aware form adaptation) and `contactmomenten-rapportage` (reporting/KPIs), providing the foundational entity and UI layer that both depend on.

**Standards**: VNG Klantinteracties (`Contactmoment`, `KlantContactmoment`, `ObjectContactmoment`), Schema.org (`CommunicateAction`)
**Feature tier**: MVP (core CRUD, views, navigation), V1 (client timeline integration)

## Data Model

### Contactmoment Entity

| Property | Type | Schema.org | VNG Mapping | Required | Default |
|----------|------|------------|-------------|----------|---------|
| `subject` | string | `schema:about` | Contactmoment.onderwerp | Yes | -- |
| `summary` | string | `schema:description` | Contactmoment.tekst | No | -- |
| `channel` | enum: telefoon, email, balie, chat, social, brief | `schema:instrument` | Contactmoment.kanaal | Yes | -- |
| `outcome` | enum: afgehandeld, doorverbonden, terugbelverzoek, vervolgactie | `schema:result` | Contactmoment.resultaat | No | -- |
| `client` | reference (UUID) | `schema:recipient` | KlantContactmoment -> Klant | No | -- |
| `request` | reference (UUID) | `schema:object` | ObjectContactmoment -> Verzoek | No | -- |
| `agent` | string (Nextcloud user UID) | `schema:agent` | Contactmoment.medewerker | Auto | current user |
| `contactedAt` | datetime | `schema:startTime` | Contactmoment.registratiedatum | Auto | current timestamp |
| `duration` | string (ISO 8601 duration) | `schema:duration` | Contactmoment.gespreksduur | No | -- |
| `channelMetadata` | object | -- | -- | No | `{}` |
| `notes` | string | `schema:text` | Contactmoment.notitie | No | -- |

## Requirements

---

### Requirement: Contactmoment Entity Schema

The system MUST define a Contactmoment entity in the OpenRegister `pipelinq` register with the properties defined in the Data Model above, with `@type` set to `schema:CommunicateAction`.

**Feature tier**: MVP

#### Scenario: Contactmoment schema exists in register
@e2e exclude PHP repair step; covered by PHPUnit

- **WHEN** the Pipelinq app is installed or updated
- **THEN** the `pipelinq` register MUST contain a `contactmoment` schema
- AND the schema MUST have `@type` set to `schema:CommunicateAction`
- AND all required properties (`subject`, `channel`) MUST be defined with validation rules

#### Scenario: Contactmoment validates required fields
@e2e exclude server validation; covered by PHPUnit

- **WHEN** a contactmoment is created without a `subject` or `channel`
- **THEN** OpenRegister MUST reject the object with a validation error
- AND the error MUST indicate which required fields are missing

---

### Requirement: Contactmoment Creation

The system MUST allow creating contactmomenten via the OpenRegister API. The `agent` and `contactedAt` fields MUST be auto-populated.

**Feature tier**: MVP

#### Scenario: Create a contactmoment with minimal fields

- **WHEN** a user creates a contactmoment with subject "Vraag over vergunning" and channel "telefoon"
- **THEN** the system MUST create an OpenRegister object in the `pipelinq` register with the `contactmoment` schema
- AND `agent` MUST be set to the current Nextcloud user UID
- AND `contactedAt` MUST be set to the current timestamp
- AND `channelMetadata` MUST default to `{}`

#### Scenario: Create a contactmoment linked to a client and request
@e2e exclude requires client and request seed data

- **WHEN** a user creates a contactmoment with subject "Status update bouwvergunning", channel "email", client UUID "abc-123", and request UUID "def-456"
- **THEN** the contactmoment MUST store both reference UUIDs
- AND the contactmoment MUST appear in both the client's and the request's linked contactmomenten

#### Scenario: Create a contactmoment with channel metadata
@e2e exclude requires form interaction and data

- **WHEN** a user creates a contactmoment with channel "telefoon" and channelMetadata `{"gespreksduur": "PT4M23S", "richting": "inkomend"}`
- **THEN** the channelMetadata object MUST be stored as-is on the contactmoment
- AND the `duration` field MUST accept ISO 8601 duration format

---

### Requirement: Contactmoment Update and Deletion

The system MUST allow updating and deleting contactmomenten. Only the creating agent or an admin MUST be able to delete.

**Feature tier**: MVP

#### Scenario: Update a contactmoment summary
@e2e exclude requires existing contactmoment record

- **WHEN** a user updates an existing contactmoment to add summary "Burger vraagt naar status bouwvergunning, doorverwezen naar afdeling VTH"
- **THEN** the summary field MUST be updated on the OpenRegister object
- AND the modification timestamp MUST be updated

#### Scenario: Delete a contactmoment
@e2e exclude requires existing record

- **WHEN** the agent who created a contactmoment deletes it
- **THEN** the contactmoment MUST be removed from OpenRegister
- AND it MUST no longer appear in client timelines, request views, or the contactmomenten list

#### Scenario: Non-creator cannot delete
@e2e exclude RBAC; covered by PHPUnit

- **WHEN** a user who is not the creating agent and not an admin attempts to delete a contactmoment
- **THEN** the system MUST reject the deletion with a permission error

---

### Requirement: Contactmomenten List View

The system MUST provide a list view at `/contactmomenten` showing all contactmomenten with search, filter, sort, and pagination.

**Feature tier**: MVP

#### Scenario: Display contactmomenten list

- **WHEN** a user navigates to `/contactmomenten`
- **THEN** the system MUST display a table of contactmomenten with columns: subject, channel, client name, agent, contactedAt, outcome
- AND results MUST be sorted by `contactedAt` descending (most recent first) by default
- AND the list MUST show 20 items per page with pagination controls

#### Scenario: Search contactmomenten
@e2e exclude requires existing data

- **WHEN** a user enters "vergunning" in the search field
- **THEN** the system MUST filter contactmomenten where `subject` or `summary` contains "vergunning"
- AND results MUST update as the user types (debounced at 300ms)

#### Scenario: Filter by channel
@e2e exclude requires data with multiple channels

- **WHEN** a user selects filter channel "telefoon"
- **THEN** only contactmomenten with `channel: "telefoon"` MUST be displayed
- AND the filter MUST support multiple channel selection

#### Scenario: Filter by date range
@e2e exclude requires data with dates

- **WHEN** a user selects a date range from "2024-01-01" to "2024-01-31"
- **THEN** only contactmomenten with `contactedAt` within that range MUST be displayed

#### Scenario: Filter by agent
@e2e exclude requires data with multiple agents

- **WHEN** a user selects filter agent "sales1"
- **THEN** only contactmomenten where `agent` is "sales1" MUST be displayed

---

### Requirement: Contactmoment Detail View

The system MUST provide a detail view for individual contactmomenten showing all fields and linked entities.

**Feature tier**: MVP

#### Scenario: Display contactmoment details
@e2e exclude requires existing record

- **WHEN** a user clicks on a contactmoment in the list view
- **THEN** the system MUST navigate to the contactmoment detail view
- AND the view MUST display: subject, summary, channel (with icon), outcome, agent (with avatar), contactedAt (formatted), duration, notes, and channelMetadata
- AND if a client is linked, the client name MUST be shown as a clickable link to the client detail view
- AND if a request is linked, the request title MUST be shown as a clickable link to the request detail view

#### Scenario: Edit contactmoment from detail view
@e2e exclude requires existing record

- **WHEN** a user clicks "Edit" on the contactmoment detail view
- **THEN** the view MUST switch to edit mode with all fields editable
- AND the user MUST be able to save or cancel the edit

---

### Requirement: Quick-Log Form

The system MUST provide a reusable quick-log form component for creating contactmomenten with optional pre-filled context.

**Feature tier**: MVP

#### Scenario: Quick-log from contactmomenten list

- **WHEN** a user clicks "Nieuw contactmoment" on the contactmomenten list view
- **THEN** the quick-log form MUST open with no pre-filled fields
- AND the form MUST show: subject (required), channel (required), client (optional, with search), request (optional, with search), summary, outcome, notes

#### Scenario: Quick-log from client detail
@e2e exclude requires existing client

- **WHEN** a user clicks "Log contactmoment" on a client detail view for client "Jan de Vries"
- **THEN** the quick-log form MUST open with the client field pre-filled with "Jan de Vries" (UUID)
- AND the user MUST be able to change the pre-filled client if needed

#### Scenario: Quick-log from request detail
@e2e exclude requires existing request

- **WHEN** a user clicks "Log contactmoment" on a request detail view for request "Bouwvergunning aanvraag" linked to client "Gemeente Utrecht"
- **THEN** the quick-log form MUST open with both the request and client fields pre-filled
- AND the user MUST be able to change the pre-filled values if needed

#### Scenario: Quick-log saves and refreshes context
@e2e exclude requires existing entity

- **WHEN** a user submits the quick-log form from a client detail view
- **THEN** the contactmoment MUST be created in OpenRegister
- AND the client detail timeline MUST refresh to show the new contactmoment
- AND a success toast notification MUST be displayed

---

### Requirement: Contactmomenten Pinia Store

The system MUST provide a Pinia store that handles all contactmoment CRUD operations via the OpenRegister API. Uses `createObjectStore` from `@conduction/nextcloud-vue` with the `contactmoment` object type registered in `initializeStores()`.

**Feature tier**: MVP

#### Scenario: Store fetches contactmomenten list
@e2e exclude Pinia store unit test; covered by Jest

- **WHEN** the contactmomenten list view mounts
- **THEN** the store MUST call the OpenRegister API with the `pipelinq` register and `contactmoment` schema
- AND the store MUST support pagination parameters (page, limit)
- AND the store MUST support filter parameters (channel, agent, dateFrom, dateTo, search)

#### Scenario: Store creates a contactmoment
@e2e exclude Pinia store unit test; covered by Jest

- **WHEN** the quick-log form is submitted
- **THEN** the store MUST POST to the OpenRegister API to create the object
- AND on success, the store MUST add the new contactmoment to the local state
- AND on failure, the store MUST surface the error message to the form

#### Scenario: Store fetches contactmomenten for a specific client
@e2e exclude Pinia store unit test; covered by Jest

- **WHEN** the client detail view requests contactmomenten for client UUID "abc-123"
- **THEN** the store MUST query OpenRegister with filter `client=abc-123`
- AND the results MUST be available as a computed property filtered by client ID

---

### Requirement: Navigation Integration

The system MUST add "Contactmomenten" as a top-level navigation item in the Pipelinq sidebar.

**Feature tier**: MVP

#### Scenario: Navigation item present

- **WHEN** a user opens Pipelinq
- **THEN** the sidebar MUST show "Contactmomenten" as a navigation item with a phone/message icon
- AND clicking it MUST navigate to `/contactmomenten`

#### Scenario: Navigation item shows count badge

- **WHEN** there are unresolved contactmomenten (no outcome set) assigned to the current user today
- **THEN** the navigation item MUST display a count badge with the number of unresolved items

---

### Requirement: ContactmomentService Backend

The system MUST provide a `ContactmomentService` PHP service that handles permission-checked deletion of contactmomenten.

**Feature tier**: MVP

#### Scenario: Delete by creating agent
@e2e exclude RBAC; covered by PHPUnit

- **WHEN** the agent who created a contactmoment requests deletion
- **THEN** the service MUST delete the contactmoment from OpenRegister
- AND return success

#### Scenario: Delete by admin
@e2e exclude RBAC; covered by PHPUnit

- **WHEN** an admin user requests deletion of any contactmoment
- **THEN** the service MUST delete the contactmoment regardless of agent
- AND return success

#### Scenario: Delete by non-creator non-admin rejected
@e2e exclude RBAC; covered by PHPUnit

- **WHEN** a user who is not the creating agent and not an admin requests deletion
- **THEN** the service MUST throw an exception with HTTP 403
- AND the contactmoment MUST NOT be deleted

---

### Requirement: ContactmomentController API

The system MUST provide a `ContactmomentController` with a delete endpoint at `DELETE /api/contactmomenten/{id}`.

**Feature tier**: MVP

#### Scenario: Delete endpoint returns 200 on success
@e2e exclude API contract; covered by Newman

- **WHEN** an authorized user calls `DELETE /api/contactmomenten/{id}`
- **THEN** the controller MUST return HTTP 200 with `{ "success": true }`

#### Scenario: Delete endpoint returns 403 on unauthorized
@e2e exclude API auth; covered by Newman

- **WHEN** a non-authorized user calls `DELETE /api/contactmomenten/{id}`
- **THEN** the controller MUST return HTTP 403 with error message

### Requirement: Contact communications and sync — documented operations

The contact linkage, contactmoment service and email sync implemented in this app MUST provide the operations enumerated in this change's tasks.md (for example `getLinkedContactsUids`, `getObjectService`, `buildEmailLinkData`, `extractDomain`, `getLastSyncTime`, `getSyncAccounts`). Each listed method realises an observable part of contact linkage, contactmoment service and email sync and MUST behave as implemented in the current codebase.

**Feature tier**: V1

#### Scenario: Documented operations are available

@e2e exclude backend service/controller method contract; covered by PHPUnit

- GIVEN the backend service/controller is loaded
- WHEN a caller invokes one of the documented operations for contact linkage, contactmoment service and email sync
- THEN the operation MUST execute and return a result consistent with the current implementation

---

### Requirement: Contact communications and sync — results derived from current CRM state

Operations for contact linkage, contactmoment service and email sync MUST read their inputs from the relevant CRM entities/configuration and compute results from that live state (no hard-coded or stubbed responses). Derivations such as formatting, aggregation, filtering and validation MUST reflect the data present at call time.

**Feature tier**: V1

#### Scenario: Results reflect live state

@e2e exclude component/store method contract; page surface covered by real-UI spec-coverage tests + Vitest unit tests

- GIVEN CRM data backing contact linkage, contactmoment service and email sync
- WHEN a documented operation runs
- THEN its output MUST be derived from that data
- AND it MUST change when the underlying data changes

---

### Requirement: Contact communications and sync — defensive handling of absent or invalid input

Operations for contact linkage, contactmoment service and email sync MUST tolerate missing, empty, or malformed input without throwing unhandled errors — returning empty or default results, or surfacing a validation outcome as implemented, rather than crashing the surrounding flow.

**Feature tier**: V1

#### Scenario: Missing input does not crash the flow

@e2e exclude component/store method contract; page surface covered by real-UI spec-coverage tests + Vitest unit tests

- GIVEN an operation for contact linkage, contactmoment service and email sync is called with absent or invalid input
- WHEN it executes
- THEN it MUST return a safe default or a validation result
- AND it MUST NOT raise an unhandled exception

### Requirement: Contact 360 UI — documented operations

The contact detail, relationships and quick-log screens implemented in this app MUST provide the operations enumerated in this change's tasks.md (for example `doSearch`, `importContact`, `onSearch`, `closeDialog`, `confirmRemove`, `editRelationship`). Each listed method realises an observable part of contact detail, relationships and quick-log screens and MUST behave as implemented in the current codebase.

**Feature tier**: V1

#### Scenario: Documented operations are available

@e2e exclude component/store method contract; page surface covered by real-UI spec-coverage tests + Vitest unit tests

- GIVEN the frontend component/store is loaded
- WHEN a caller invokes one of the documented operations for contact detail, relationships and quick-log screens
- THEN the operation MUST execute and return a result consistent with the current implementation

---

### Requirement: Contact 360 UI — results derived from current CRM state

Operations for contact detail, relationships and quick-log screens MUST read their inputs from the relevant CRM entities/configuration and compute results from that live state (no hard-coded or stubbed responses). Derivations such as formatting, aggregation, filtering and validation MUST reflect the data present at call time.

**Feature tier**: V1

#### Scenario: Results reflect live state

@e2e exclude component/store method contract; page surface covered by real-UI spec-coverage tests + Vitest unit tests

- GIVEN CRM data backing contact detail, relationships and quick-log screens
- WHEN a documented operation runs
- THEN its output MUST be derived from that data
- AND it MUST change when the underlying data changes

---

### Requirement: Contact 360 UI — defensive handling of absent or invalid input

Operations for contact detail, relationships and quick-log screens MUST tolerate missing, empty, or malformed input without throwing unhandled errors — returning empty or default results, or surfacing a validation outcome as implemented, rather than crashing the surrounding flow.

**Feature tier**: V1

#### Scenario: Missing input does not crash the flow

@e2e exclude component/store method contract; page surface covered by real-UI spec-coverage tests + Vitest unit tests

- GIVEN an operation for contact detail, relationships and quick-log screens is called with absent or invalid input
- WHEN it executes
- THEN it MUST return a safe default or a validation result
- AND it MUST NOT raise an unhandled exception

### Requirement: Inbound Contactmoment notification carries a distinct body and covers the chat channel

The inbound-Contactmoment notification rules on the `contactmoment` schema SHALL declare a distinct notification body (`message`) in addition to the title (`subject`), and SHALL cover the `chat` inbound channel in addition to `telefoon` and `email`. The `subject` SHALL state the event (the notification TITLE) and the `message` SHALL state the context plus the open-in-Nextcloud call-to-action (the notification BODY), both as i18n nl/en strings. The rules SHALL be expressed purely in the schema-register JSON (`kind: config`, ADR-031) and SHALL use the `message` field defined by the `openregister-notification-body` change, with the `incomingChat` rule mirroring `incomingEmail` (same `originApp: "pipelinq"`, `["nc-notification", "web-push"]` channels, agent + `sales`-group recipients, and the relation-resolved `object-detail` "Open client" action).

**Standards**: VNG Klantinteracties (`Contactmoment` → `KlantContactmoment` → `Klant`), Schema.org (`CommunicateAction`)
**Feature tier**: V1 (notification on inbound interaction)

#### Scenario: Notification title and body are distinct

- **WHEN** an inbound `telefoon` `contactmoment` is created and the `incomingCall` rule dispatches a notification
- **THEN** the notification title is rendered from `subject` ("Incoming call from {{client}}") and the notification body is rendered from `message` ("{{client}} is a contact in Pipelinq. Open it in Nextcloud?"), so the two lines are not identical

#### Scenario: Incoming email notification has its own body wording

- **WHEN** an inbound `email` `contactmoment` is created
- **THEN** the `incomingEmail` notification title is "Incoming email from {{client}}" and its body is the email-specific `message` ("{{client}} is a contact in Pipelinq. Open the email in Nextcloud?")

#### Scenario: Incoming chat notifies the agent with an Open-client button

- **WHEN** a `contactmoment` object is created with `channel == "chat"` and a populated `client` relation and `agent` field
- **THEN** the engine dispatches the `incomingChat` notification to the `agent` user and the `sales` group on the `nc-notification` and `web-push` channels, with `originApp: "pipelinq"`, the title "Incoming chat from {{client}}", the body "{{client}} is a contact in Pipelinq. Open the conversation in Nextcloud?", and a single primary "Open client" / "Klant openen" action whose `object-detail` target resolves to the related Client object server-side

#### Scenario: Body falls back to the title when no message is declared

- **WHEN** a rule (e.g. another schema's rule) without a `message` field dispatches a notification under the `openregister-notification-body` engine
- **THEN** the body falls back to the `subject`, so rules that have not adopted `message` keep working unchanged

#### Scenario: Unrouted inbound chat still reaches the sales team

- **WHEN** an inbound `chat` `contactmoment` is created with no `agent` set (e.g. by a chat-widget integration before routing)
- **THEN** the `field: agent` recipient resolves to nobody but the `groups: sales` fallback recipient still receives the `incomingChat` notification, so the inbound chat is not silently dropped

#### Scenario: i18n present on every title, body, and action label

- **WHEN** the `contactmoment` notification block is saved
- **THEN** each of `incomingCall`, `incomingEmail`, and `incomingChat` declares an nl/en `subject`, an nl/en `message`, and at most 2 `actions` whose labels carry nl/en text

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

A contact moment MUST hold an ordered set of ADR-048 semantic references to the `case`
type. The set MUST hold at least one entry and MUST NOT hold the same reference twice.
An existing single value MUST migrate to a one-element set without loss, and the
migration MUST be idempotent. The `request` facet MUST keep the single reference.

The set is carried by `ticket.caseReferences` rather than by widening
`ticket.caseReference` in place. `caseReference` is ONE property on ONE schema that
three facets share, and `request` must keep it a single string, so a property that is
a string for one facet and an array for another cannot be declared. On a contact
moment `caseReference` therefore stays readable as the primary reference and is kept
in step with it on every write, so every existing reader keeps working and no
consumer has to learn two shapes at once.

**Feature tier**: V1

#### Scenario: One call about three cases is one record

- **GIVEN** a contact moment recording one telephone call
- **WHEN** it is filed on three cases
- **THEN** one contact moment exists
- **AND** its `caseReferences` set holds the three references.
- e2e: `tests/e2e/contact-moment-several-cases.spec.ts`

#### Scenario: An existing contact moment migrates without loss

- **GIVEN** a contact moment whose `caseReference` is a single reference
- **WHEN** the migration runs
- **THEN** its `caseReferences` is a set holding that one reference
- **AND** running the migration again changes nothing.
- @e2e exclude repair step; covered by PHPUnit on `WidenContactMomentCaseReference`

#### Scenario: A duplicate reference is refused

- **GIVEN** a contact moment already filed on a case
- **WHEN** a write adds the same reference again
- **THEN** the write is refused
- **AND** the set is unchanged.
- @e2e exclude server validation; covered by PHPUnit on `TicketService::save()`

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

### Requirement: The contact moment panel SHALL show the party's indicators (REQ-CMI-001)

The panel that renders contact moments on a host object SHALL also render the
indicators held by the party those contact moments belong to, resolved live per
REQ-PFI-003. Indicators SHALL be shown before the moments, so a KCC agent taking a
call reads the flag before they speak.

An indicator declaring `requiresAcknowledgement` SHALL be presented so that the
handler confirms they have seen it, and the confirmation SHALL be recorded with the
handler and the time.

This extends the panel specified by `contact-moments-on-pipelinq-schema`, which is a
declared dependency of this change. Its four existing requirements are unchanged.

#### Scenario: An aggression flag is read before the call
- **GIVEN** a party carrying "agressie-registratie" and three contact moments
- **WHEN** the panel renders
- **THEN** the indicator appears above the moments
- @e2e exclude covered by PHPUnit on the panel and the indicator resolution

#### Scenario: An acknowledgement is recorded
- **GIVEN** an indicator declaring `requiresAcknowledgement`
- **WHEN** a handler confirms it
- **THEN** the confirmation is stored with the handler and the time
- @e2e exclude covered by PHPUnit on `PartyIndicatorService::acknowledge()`

#### Scenario: A blocked party is said at the point of writing
- **GIVEN** a party carrying an indicator asserting `blocksOutbound`
- **WHEN** a handler starts an outbound contact moment from the panel
- **THEN** the panel reports the block and names the indicator, and the append is
  refused
- @e2e exclude covered by PHPUnit on the blocking answer

### Requirement: One reference is the primary one, and it is named (REQ-CMS-002)

A contact moment MUST carry `primaryCaseReference`, and its value MUST be a member of
its `caseReferences` set. A surface that can show only one case MUST show the primary.
The app MUST NOT answer that question by position in the set.

**Feature tier**: V1

#### Scenario: The primary survives a reorder

- **GIVEN** a contact moment on three cases with the second as primary
- **WHEN** the set is reordered
- **THEN** the primary is still the same case.
- @e2e exclude a reorder is a write shape, not a surface; covered by PHPUnit on `ContactMomentFilingService::primaryOf()`

#### Scenario: A primary outside the set is refused

- **GIVEN** a contact moment on cases A and B
- **WHEN** a write sets the primary to case C
- **THEN** the write is refused.
- @e2e exclude server validation; covered by PHPUnit on `TicketService::save()`

### Requirement: A case lists the contact moments it is a member of (REQ-CMS-003)

The contact moments leaf MUST answer, for a host case, every contact moment whose
`caseReferences` set contains that case. The query MUST be bounded per ADR-058 and MUST
NOT scan every ticket.

**Feature tier**: V1

#### Scenario: Each of three cases sees the one call

- **GIVEN** one contact moment filed on cases A, B and C
- **WHEN** the leaf renders on case B
- **THEN** it lists that contact moment once.
- e2e: `tests/e2e/contact-moment-several-cases.spec.ts`

### Requirement: Filing onto a further case is an act, not a copy (REQ-CMS-004)

The app MUST offer an act that appends a case reference to an existing contact moment,
and it MUST record who performed it and when. The act MUST NOT create a second contact
moment and MUST NOT alter the contact moment's content.

**Feature tier**: V1

#### Scenario: Filing a call onto a second case creates no second record

- **GIVEN** a contact moment on case A
- **WHEN** a handler files it onto case B
- **THEN** the number of contact moments is unchanged
- **AND** the set holds A and B
- **AND** the append names the handler and the moment it happened.
- e2e: `tests/e2e/contact-moment-several-cases.spec.ts`

### Requirement: A shared contact moment says so before it is edited (REQ-CMS-005)

Where a contact moment is on more than one case, the surface rendering it MUST say so
and MUST name the other cases. A case the reader is not permitted to see MUST be
reported as a count rather than by title.

**Feature tier**: V1

#### Scenario: The reader is warned before editing

- **GIVEN** a contact moment on cases A and B
- **WHEN** a handler opens it from case A
- **THEN** the surface says it is also on case B.
- e2e: `tests/e2e/contact-moment-several-cases.spec.ts`

#### Scenario: A case the reader may not see is counted, not named

- **GIVEN** a contact moment on case A and on case B, which the reader may not see
- **WHEN** the handler opens it from case A
- **THEN** the surface says it is also on one other case
- **AND** it does not render case B's title.
- @e2e exclude a second reader is needed; covered by PHPUnit on `ContactMomentFilingService::sharedMarker()`

### Requirement: A contact moment cannot be left with no case (REQ-CMS-006)

The act that removes a case reference MUST refuse when it would empty the set, and MUST
refuse to remove the primary unless the same call names a new primary from the
remaining members.

**Feature tier**: V1

#### Scenario: The last reference cannot be removed

- **GIVEN** a contact moment on one case
- **WHEN** a handler tries to unfile it
- **THEN** the act is refused
- **AND** the refusal says a contact moment has to stay on at least one case.
- e2e: `tests/e2e/contact-moment-several-cases.spec.ts`

#### Scenario: Removing the primary requires naming the next one

- **GIVEN** a contact moment on cases A and B with A as primary
- **WHEN** a handler unfiles A without naming a new primary
- **THEN** the act is refused
- **AND** the same call naming B as primary succeeds.
- @e2e exclude two acts in one call; covered by PHPUnit on `ContactMomentFilingService::unfileFromCase()`

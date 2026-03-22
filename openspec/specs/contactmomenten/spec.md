# Contactmomenten Specification

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

- **WHEN** the Pipelinq app is installed or updated
- **THEN** the `pipelinq` register MUST contain a `contactmoment` schema
- AND the schema MUST have `@type` set to `schema:CommunicateAction`
- AND all required properties (`subject`, `channel`) MUST be defined with validation rules

#### Scenario: Contactmoment validates required fields

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

- **WHEN** a user creates a contactmoment with subject "Status update bouwvergunning", channel "email", client UUID "abc-123", and request UUID "def-456"
- **THEN** the contactmoment MUST store both reference UUIDs
- AND the contactmoment MUST appear in both the client's and the request's linked contactmomenten

#### Scenario: Create a contactmoment with channel metadata

- **WHEN** a user creates a contactmoment with channel "telefoon" and channelMetadata `{"gespreksduur": "PT4M23S", "richting": "inkomend"}`
- **THEN** the channelMetadata object MUST be stored as-is on the contactmoment
- AND the `duration` field MUST accept ISO 8601 duration format

---

### Requirement: Contactmoment Update and Deletion

The system MUST allow updating and deleting contactmomenten. Only the creating agent or an admin MUST be able to delete.

**Feature tier**: MVP

#### Scenario: Update a contactmoment summary

- **WHEN** a user updates an existing contactmoment to add summary "Burger vraagt naar status bouwvergunning, doorverwezen naar afdeling VTH"
- **THEN** the summary field MUST be updated on the OpenRegister object
- AND the modification timestamp MUST be updated

#### Scenario: Delete a contactmoment

- **WHEN** the agent who created a contactmoment deletes it
- **THEN** the contactmoment MUST be removed from OpenRegister
- AND it MUST no longer appear in client timelines, request views, or the contactmomenten list

#### Scenario: Non-creator cannot delete

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

- **WHEN** a user enters "vergunning" in the search field
- **THEN** the system MUST filter contactmomenten where `subject` or `summary` contains "vergunning"
- AND results MUST update as the user types (debounced at 300ms)

#### Scenario: Filter by channel

- **WHEN** a user selects filter channel "telefoon"
- **THEN** only contactmomenten with `channel: "telefoon"` MUST be displayed
- AND the filter MUST support multiple channel selection

#### Scenario: Filter by date range

- **WHEN** a user selects a date range from "2024-01-01" to "2024-01-31"
- **THEN** only contactmomenten with `contactedAt` within that range MUST be displayed

#### Scenario: Filter by agent

- **WHEN** a user selects filter agent "sales1"
- **THEN** only contactmomenten where `agent` is "sales1" MUST be displayed

---

### Requirement: Contactmoment Detail View

The system MUST provide a detail view for individual contactmomenten showing all fields and linked entities.

**Feature tier**: MVP

#### Scenario: Display contactmoment details

- **WHEN** a user clicks on a contactmoment in the list view
- **THEN** the system MUST navigate to the contactmoment detail view
- AND the view MUST display: subject, summary, channel (with icon), outcome, agent (with avatar), contactedAt (formatted), duration, notes, and channelMetadata
- AND if a client is linked, the client name MUST be shown as a clickable link to the client detail view
- AND if a request is linked, the request title MUST be shown as a clickable link to the request detail view

#### Scenario: Edit contactmoment from detail view

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

- **WHEN** a user clicks "Log contactmoment" on a client detail view for client "Jan de Vries"
- **THEN** the quick-log form MUST open with the client field pre-filled with "Jan de Vries" (UUID)
- AND the user MUST be able to change the pre-filled client if needed

#### Scenario: Quick-log from request detail

- **WHEN** a user clicks "Log contactmoment" on a request detail view for request "Bouwvergunning aanvraag" linked to client "Gemeente Utrecht"
- **THEN** the quick-log form MUST open with both the request and client fields pre-filled
- AND the user MUST be able to change the pre-filled values if needed

#### Scenario: Quick-log saves and refreshes context

- **WHEN** a user submits the quick-log form from a client detail view
- **THEN** the contactmoment MUST be created in OpenRegister
- AND the client detail timeline MUST refresh to show the new contactmoment
- AND a success toast notification MUST be displayed

---

### Requirement: Contactmomenten Pinia Store

The system MUST provide a Pinia store that handles all contactmoment CRUD operations via the OpenRegister API. Uses `createObjectStore` from `@conduction/nextcloud-vue` with the `contactmoment` object type registered in `initializeStores()`.

**Feature tier**: MVP

#### Scenario: Store fetches contactmomenten list

- **WHEN** the contactmomenten list view mounts
- **THEN** the store MUST call the OpenRegister API with the `pipelinq` register and `contactmoment` schema
- AND the store MUST support pagination parameters (page, limit)
- AND the store MUST support filter parameters (channel, agent, dateFrom, dateTo, search)

#### Scenario: Store creates a contactmoment

- **WHEN** the quick-log form is submitted
- **THEN** the store MUST POST to the OpenRegister API to create the object
- AND on success, the store MUST add the new contactmoment to the local state
- AND on failure, the store MUST surface the error message to the form

#### Scenario: Store fetches contactmomenten for a specific client

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

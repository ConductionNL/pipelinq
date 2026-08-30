# Entity Notes Specification

## Purpose

Enable internal notes and communication history on all Pipelinq entity detail pages, and expose an activity REST API for programmatic access to interaction records. Driven by market demand for omnichannel customer communication management (925 demand score, 308 tender mentions) and activity instance REST API query support (125 demand score, 25 tender mentions).

## Entities Used

| Entity | Source | Role in this spec |
|--------|--------|-------------------|
| `contactmoment` | ADR-000 / omnichannel-registratie | Displayed as communication history on entity pages |
| `client` | ADR-000 | Entity type that receives notes and history panels |
| `contact` | ADR-000 | Entity type that receives notes and history panels |
| `lead` | ADR-000 | Entity type that receives notes and history panels |
| `request` | ADR-000 | Entity type that receives notes and history panels |

OpenRegister built-in `notes` field is used for entity notes — no additional schema property is introduced.

---

## ADDED Requirements

### REQ-ENT-001: Entity Notes via CnObjectSidebar

All Pipelinq entity detail pages MUST display a Notes tab via `CnObjectSidebar`, allowing agents to create, view, and delete internal notes.

#### Scenario ENT-001-A: View notes on an entity
- GIVEN the user opens a client, contact, lead, or request detail page
- WHEN the entity has one or more internal notes
- THEN the Notes tab in the sidebar MUST display all notes in reverse chronological order
- AND each note MUST show: author display name, relative timestamp, and note text
- AND the Notes tab indicator MUST reflect the number of notes

#### Scenario ENT-001-B: Add a note to an entity
- GIVEN the user is viewing an entity detail page with the sidebar open
- WHEN the user types a note in the Notes input field and submits
- THEN a new note MUST be saved to the entity's built-in `notes` field via OpenRegister
- AND the note MUST appear immediately in the Notes list
- AND the input field MUST be cleared after submission

#### Scenario ENT-001-C: Delete own note
- GIVEN the user has authored a note on an entity
- WHEN the user clicks the delete action on that note
- THEN the note MUST be removed from the entity's notes
- AND the note MUST disappear from the Notes list
- AND notes authored by other users MUST NOT show a delete action for the current user

#### Scenario ENT-001-D: Empty notes state
- GIVEN an entity has no notes
- WHEN the user opens the Notes tab in the sidebar
- THEN an empty state message MUST be displayed
- AND the note creation input MUST still be available

#### Scenario ENT-001-E: Notes available on all entity types
- GIVEN any of: a client detail page, a contact detail page, a lead detail page, a request detail page
- THEN the Notes tab MUST be present and functional in the `CnObjectSidebar`

---

### REQ-ENT-002: Communication History Panel

Entity detail pages for clients, contacts, leads, and requests MUST display an inline Communication History panel showing linked `contactmoment` objects.

#### Scenario ENT-002-A: Communication history visible on entity page
- GIVEN a client, contact, lead, or request has one or more linked contactmomenten
- WHEN the user views the entity detail page
- THEN a "Communication History" section MUST appear below the entity fields
- AND each contactmoment MUST display: channel icon, subject, agent name, date/time
- AND entries MUST be ordered reverse-chronologically (newest first)

#### Scenario ENT-002-B: Navigate to contactmoment detail
- GIVEN a communication history entry is visible on an entity page
- WHEN the user clicks the entry
- THEN the user MUST be navigated to the full contactmoment detail page
- AND the browser URL MUST use path format `/apps/pipelinq/contactmomenten/{uuid}` (NOT hash format)

#### Scenario ENT-002-C: Empty communication history
- GIVEN an entity has no linked contactmomenten
- WHEN the user views the entity detail page
- THEN the Communication History section MUST display an empty state message
- AND the section MUST still be visible (no hidden section)

#### Scenario ENT-002-D: Pagination of communication history
- GIVEN an entity has more than 10 linked contactmomenten
- WHEN the user views the Communication History panel
- THEN pagination controls MUST be present
- AND each page MUST display at most 10 entries by default

#### Scenario ENT-002-E: Communication History not shown in edit mode
- GIVEN the user is in edit mode on an entity detail page
- THEN the Communication History panel MUST NOT be rendered
- AND it MUST reappear when the user exits edit mode

---

### REQ-ENT-003: Activity REST API

A REST API endpoint MUST be available for querying activity instances (notes and contactmomenten) for any Pipelinq entity.

#### Scenario ENT-003-A: Query all activity for an entity
- GIVEN the user is authenticated
- WHEN a GET request is made to `/api/activity/{entityType}/{entityId}`
- THEN the response MUST return HTTP 200
- AND the response MUST include `total`, `page`, `pages`, and `results` fields
- AND `results` MUST contain a chronologically ordered list of activity items
- AND each item MUST include: `type`, `id`, `subject`, `channel` (if contactmoment), `agent`, `timestamp`

#### Scenario ENT-003-B: Filter by activity type
- GIVEN an entity has both notes and contactmomenten
- WHEN a GET request is made with `?type=contactmomenten`
- THEN the response MUST contain only contactmoment items
- WHEN a GET request is made with `?type=notes`
- THEN the response MUST contain only note items
- WHEN a GET request is made with `?type=all` or no type parameter
- THEN both notes and contactmomenten MUST be returned

#### Scenario ENT-003-C: Pagination support
- GIVEN an entity has more than 20 activity items
- WHEN a GET request is made with `?_page=2&_limit=10`
- THEN the response MUST return items 11–20
- AND `page`, `pages`, and `total` MUST reflect correct pagination state

#### Scenario ENT-003-D: Invalid entity type
- GIVEN an unknown entity type is provided
- WHEN a GET request is made to `/api/activity/unknown/{entityId}`
- THEN the response MUST return HTTP 400
- AND the response MUST include `{"message": "Invalid entity type"}`
- AND no stack trace or internal path MUST appear in the response

#### Scenario ENT-003-E: Authorization
- GIVEN the user is not authenticated
- WHEN a GET request is made to `/api/activity/{entityType}/{entityId}`
- THEN the response MUST return HTTP 401 or redirect to Nextcloud login
- AND no entity data MUST be returned

---

## Accessibility Requirements

Per ADR-010-nl-design:
- The `CommunicationHistory.vue` component MUST be keyboard-navigable (table rows focusable via Tab)
- Channel icons MUST have `aria-label` with the channel name (color must not be the sole conveyor)
- Empty state messages MUST use semantic HTML (not `aria-hidden`)
- The component MUST work at 768px viewport width (tablet breakpoint)

## i18n Requirements

Per ADR-007-i18n:
- All user-visible strings in `CommunicationHistory.vue` MUST use `this.t('pipelinq', 'key')` with English keys
- Dutch translations MUST be added to `l10n/nl.json`
- Required translation keys: `Communication History`, `No communication history yet`, `Refresh`, `Channel`, `Subject`, `Agent`, `Date`, `Invalid entity type`

## Security Requirements

Per ADR-005-security:
- `ActivityController` endpoints are `@NoAdminRequired` — accessible to all authenticated Nextcloud users
- Entity type MUST be validated server-side against an explicit allowlist (`['client', 'contact', 'lead', 'request']`)
- API responses MUST NOT include stack traces, internal paths, or SQL queries
- Audit trails use `$user->getUID()` — NEVER `$user->getDisplayName()`

# Request Management Specification

## Status: implemented

## Purpose

Request management handles the intake and tracking of requests (verzoeken) — service inquiries that come in before they become formal cases. Requests are the bridge between Pipelinq (CRM) and Procest (case management). A request flows through a status lifecycle from intake to resolution or conversion, can optionally be placed on a pipeline alongside leads, and can be converted into a Procest case.

**Standards**: Schema.org (`Demand`), VNG Verzoeken API (`Verzoek`)

## Data Model

### Request Entity

| Property | Type | Schema.org | VNG Mapping | Required | Default |
|----------|------|------------|-------------|----------|---------|
| `title` | string | `schema:name` | Verzoek.tekst | Yes | -- |
| `description` | string | `schema:description` | Verzoek.tekst | No | -- |
| `client` | reference | `schema:customer` | KlantVerzoek -> Klant | No | -- |
| `contact` | reference | -- | KlantVerzoek -> Contactpersoon | No | -- |
| `status` | enum | `schema:actionStatus` | Verzoek.status | Yes | `new` |
| `priority` | enum: low, normal, high, urgent | -- | -- | No | `normal` |
| `category` | string | `schema:category` | VerzoekProduct | No | -- |
| `requestedAt` | datetime | `schema:dateCreated` | registratiedatum | Auto | current timestamp |
| `channel` | string | `schema:availableChannel` | -- | No | -- |
| `pipeline` | reference | -- | -- | No | -- |
| `stage` | reference | -- | -- | No | -- |
| `stageOrder` | integer | -- | -- | No | 0 |
| `assignedTo` | string (user UID) | `schema:agent` | -- | No | -- |
| `caseReference` | reference | -- | -- | No | -- |

### Status Lifecycle

| Status | Dutch | Description |
|--------|-------|-------------|
| `new` | Nieuw | Just received, not yet triaged |
| `in_progress` | In behandeling | Being processed |
| `completed` | Afgehandeld | Successfully completed |
| `rejected` | Afgewezen | Rejected/declined |
| `converted` | Omgezet naar zaak | Converted to a case in Procest |

### Allowed Status Transitions

| From | Allowed To |
|------|------------|
| `new` | `in_progress`, `rejected`, `completed` |
| `in_progress` | `completed`, `rejected`, `converted` |
| `completed` | (terminal -- no transitions allowed) |
| `rejected` | (terminal -- no transitions allowed) |
| `converted` | (terminal -- no transitions allowed) |

---

## Requirements

### Requirement: Request CRUD [MVP]

The system MUST support creating, reading, updating, and deleting request records. Each request MUST have a `title`. The `status` MUST default to `new` when not explicitly provided. All properties defined in the data model MUST be persisted via OpenRegister.

#### Scenario: Create a minimal request
- **WHEN** a user submits a new request with title "Aanvraag omgevingsvergunning"
- **THEN** the system MUST create an OpenRegister object with `@type` set to `schema:Demand`
- **THEN** the `status` MUST be set to `new`
- **THEN** `requestedAt` MUST be set to the current UTC timestamp
- **THEN** `priority` MUST default to `normal`

#### Scenario: Create a request linked to a client
- **WHEN** the user creates a request and selects client "Gemeente Utrecht"
- **THEN** the request `client` field MUST store a reference to the client object via `schema:customer`
- **THEN** the request MUST appear in the client's detail view under "Requests"

#### Scenario: Create a request with all optional fields
- **WHEN** user creates a request with title "Website redesign inquiry", description "Client wants a full redesign", priority `high`, category "IT Services", and channel "email"
- **THEN** the system MUST store all provided fields on the OpenRegister object including channel
- **THEN** all fields MUST be retrievable via the API

#### Scenario: Validation - title is required
- **WHEN** user submits a request without a `title` (empty string or missing)
- **THEN** the system MUST reject the creation with a validation error
- **THEN** the error message MUST indicate that `title` is required

#### Scenario: Update request fields
- **WHEN** the user updates the description, priority, category, or channel of a request with status `new`
- **THEN** the system MUST persist the changes to the OpenRegister object

#### Scenario: Delete a request
- **WHEN** the user deletes a request with status `new` or `in_progress`
- **THEN** the system MUST remove the OpenRegister object
- **THEN** the request MUST no longer appear in list views

#### Scenario: Delete a converted request is blocked
- **WHEN** the user attempts to delete a request with status `converted` and a `caseReference`
- **THEN** the system MUST prevent deletion
- **THEN** the system MUST display an error that converted requests with active case links cannot be deleted

---

### Requirement: Request Status Lifecycle [MVP]

The system MUST enforce allowed status transitions as defined in the transition table. The frontend MUST present only valid transitions for the current status.

#### Scenario: Valid transition from new to in_progress
- **WHEN** user changes a request's status from `new` to `in_progress`
- **THEN** the system MUST update the status

#### Scenario: Valid transition from in_progress to completed
- **WHEN** user changes a request's status from `in_progress` to `completed`
- **THEN** the system MUST update the status to `completed` (terminal)

#### Scenario: Valid transition from in_progress to rejected
- **WHEN** user changes a request's status from `in_progress` to `rejected`
- **THEN** the system MUST update the status to `rejected` (terminal)

#### Scenario: Invalid transition new to converted
- **WHEN** user attempts to change a request's status from `new` to `converted`
- **THEN** the system MUST reject the transition with a validation error listing allowed transitions

#### Scenario: Invalid transition from terminal status
- **WHEN** user attempts to change a request's status from `completed` to `in_progress`
- **THEN** the system MUST reject the transition indicating `completed` is terminal

#### Scenario: Quick status change from list view
- **WHEN** user clicks a status dropdown on a request row in the list view
- **THEN** the system MUST update the request status without navigating to detail view
- **THEN** only allowed transitions from the current status MUST be presented

---

### Requirement: Request List View [MVP]

The system MUST provide a full list view with search, sort, filter, and pagination. The current basic table MUST be enhanced with filtering controls and sortable columns.

#### Scenario: Default list display
- **WHEN** user navigates to the request list with 15 requests
- **THEN** the system MUST display a paginated table with columns: title, status (badge), priority (badge), assignee, channel, requestedAt
- **THEN** default sort MUST be `requestedAt` descending

#### Scenario: Filter by status
- **WHEN** user filters by status `new`
- **THEN** only requests with status `new` MUST be shown
- **THEN** the filter selection MUST persist until cleared

#### Scenario: Filter by priority
- **WHEN** user filters by priority `urgent`
- **THEN** only urgent requests MUST be shown

#### Scenario: Filter by assignee
- **WHEN** user filters by assignee "Jan de Vries"
- **THEN** only requests assigned to Jan MUST be shown

#### Scenario: Filter by channel
- **WHEN** user filters by channel `email`
- **THEN** only email-channel requests MUST be shown

#### Scenario: Combine multiple filters
- **WHEN** user applies status `in_progress` AND priority `high`
- **THEN** only requests matching BOTH criteria MUST be shown

#### Scenario: Search requests by keyword
- **WHEN** user searches for "maintenance"
- **THEN** the system MUST match against both `title` and `description` fields case-insensitively

#### Scenario: Sort by column
- **WHEN** user clicks the "Priority" column header
- **THEN** the list MUST sort by priority (urgent > high > normal > low)
- **THEN** clicking again MUST reverse the sort order

#### Scenario: Pagination
- **WHEN** user views the list with 50 requests and page size 25
- **THEN** the system MUST display 25 requests with navigation to page 2

---

### Requirement: Request Detail View [MVP]

The system MUST provide a detail view with proper layout including core info, client link, pipeline position, assignment, and activity timeline. The current basic form MUST be replaced with a structured detail layout.

#### Scenario: View request core information
- **WHEN** user navigates to a request detail view
- **THEN** the system MUST display: title, description, status (badge), priority (badge), channel, category, requestedAt, and assignee

#### Scenario: View request with linked client
- **WHEN** user views a request linked to client "Gemeente Utrecht"
- **THEN** the system MUST display the client name as a clickable link to the client detail view
- **THEN** the system MUST display available client contact information

#### Scenario: View request pipeline position
- **WHEN** user views a request on the "Service Pipeline" at stage "In Progress"
- **THEN** the system MUST display the pipeline name and current stage
- **THEN** the system MUST display a visual stage progression indicator
- **THEN** the system MUST provide a "Move to next stage" action

#### Scenario: Navigate from detail to related entities
- **WHEN** user clicks the client name on the request detail view
- **THEN** the system MUST navigate to the client detail view
- **THEN** there MUST be a way to navigate back to the request detail

---

### Requirement: Request Assignment [MVP]

The system MUST allow assigning a request to a Nextcloud user via a user picker dropdown.

#### Scenario: Assign a request to a user
- **WHEN** user assigns a request to "Jan de Vries" via the user picker
- **THEN** the `assignedTo` field MUST be set to Jan's Nextcloud user UID

#### Scenario: Reassign a request
- **WHEN** user reassigns a request from "Jan de Vries" to "Maria van Dijk"
- **THEN** the `assignedTo` field MUST be updated to Maria's UID

#### Scenario: Unassign a request
- **WHEN** user removes the assignment
- **THEN** the `assignedTo` field MUST be cleared (null)

---

### Requirement: Request Priority [MVP]

The system MUST support four priority levels with visual indicators in all views.

#### Scenario: Set priority during creation
- **WHEN** user creates a request with priority `urgent`
- **THEN** the request MUST be stored with priority `urgent`
- **THEN** the priority MUST be visually distinguished with a color-coded badge

#### Scenario: Change priority
- **WHEN** user changes a request's priority from `normal` to `high`
- **THEN** the system MUST update the priority

#### Scenario: Priority visual indicators
- **WHEN** the request list displays requests of different priorities
- **THEN** `urgent` MUST be visually prominent (red indicator)
- **THEN** `high` MUST be distinguishable from `normal`
- **THEN** `low` MUST be distinguishable from `normal`

---

### Requirement: Request Channel Tracking [V1]

The system MUST support tracking the intake channel. Channel values come from SystemTag-based admin settings (already implemented). The `channel` field is present in the OpenRegister schema.

#### Scenario: Set channel during creation
- **WHEN** user creates a request and selects channel "phone" from the dropdown
- **THEN** the request MUST store `channel` as "phone" in OpenRegister

#### Scenario: Channel dropdown uses admin-configured values
- **WHEN** user opens the channel dropdown on the request form
- **THEN** the options MUST come from the request channels store (SystemTag-based)

---

### Requirement: Request Category/Product Classification [V1]

The system MUST support categorizing requests by product or service type. Categories are free-text strings that MAY be pre-populated from admin configuration.

#### Scenario: Set category during creation
- GIVEN a user creating a request
- WHEN they enter category "IT Services"
- THEN the request MUST store the category value

#### Scenario: Filter requests by category
- GIVEN requests with various categories
- WHEN the user filters the request list by category "IT Services"
- THEN only requests with that category MUST be shown

---

### Requirement: Request-to-Case Conversion [V1]

The system MUST support converting a request to a case in Procest.

#### Scenario: Convert request to case
- **WHEN** user clicks "Convert to case" on a request with status `in_progress`
- **THEN** the system MUST create a case in Procest with the request title as case title
- **THEN** the request status MUST change to `converted`
- **THEN** the Procest case reference MUST be stored in `caseReference`
- **THEN** if case creation fails, the request status MUST NOT change

#### Scenario: Conversion displays case link
- **WHEN** user views a converted request
- **THEN** the system MUST display a link to the associated Procest case
- **THEN** the status MUST show as "Converted to case"

#### Scenario: Convert from invalid status
- **WHEN** user attempts to convert a request with status `new`
- **THEN** the system MUST prevent the action indicating conversion is only available from `in_progress`

#### Scenario: Converted request is read-only
- **WHEN** user attempts to edit a request with status `converted`
- **THEN** the system MUST prevent modification of core fields
- **THEN** the system MUST display a notice that the request has been converted

---

### Requirement: Request on Pipeline [MVP]

A request MUST be optionally placeable on a pipeline. When on a pipeline, the request has a `stage`, `stageOrder`, and appears on the kanban board.

#### Scenario: Place request on pipeline
- **WHEN** user places a request on the "Service Pipeline" at stage "New"
- **THEN** the request MUST appear as a card on the pipeline kanban board

#### Scenario: Request without pipeline
- **WHEN** user views a request not on any pipeline
- **THEN** the pipeline section MUST show "Not on pipeline" or be hidden
- **THEN** the request MUST still be fully functional with status-based workflow

#### Scenario: Request card on mixed pipeline
- **WHEN** the pipeline kanban board displays a mixed pipeline
- **THEN** request cards MUST be visually distinguishable from lead cards ([REQ] badge)
- **THEN** request cards MUST show: title, status, priority, assignee
- **THEN** request cards MUST NOT show a monetary value field

---

### Requirement: Request Validation Rules [MVP]

The system MUST enforce validation rules for request data integrity.

#### Scenario: Title must not be empty
- **WHEN** a request create or update has an empty title
- **THEN** the system MUST reject with a validation error

#### Scenario: Status must follow transition rules
- **WHEN** a status change violates the transition table
- **THEN** the system MUST reject with specific error listing allowed transitions

#### Scenario: Priority must be a valid value
- **WHEN** priority is not one of `low`, `normal`, `high`, `urgent`
- **THEN** the system MUST reject with a validation error

#### Scenario: Client reference must be valid
- **WHEN** a client reference does not exist in OpenRegister
- **THEN** the system MUST reject with "Referenced client not found"

---

## ADDED Requirements

### Requirement: Request-Contact Linking [MVP]

The system MUST support linking a request to a specific contact person at the client organization. The `contact` field in the request schema stores a UUID reference to a contact object. When a request is linked to both a client and a contact, the contact MUST belong to that client.

#### Scenario: Link request to a contact person
- **GIVEN** a request linked to client "Gemeente Utrecht"
- **WHEN** the user selects contact person "Jan de Vries" from the contact picker
- **THEN** the request `contact` field MUST store the UUID reference to Jan's contact object
- **THEN** the contact person's name, email, and phone MUST be displayed on the request detail view

#### Scenario: Contact picker is filtered by selected client
- **GIVEN** a request form with client "Gemeente Utrecht" selected
- **WHEN** the user opens the contact person picker
- **THEN** only contact persons linked to "Gemeente Utrecht" MUST be shown
- **THEN** if no client is selected, the contact picker MUST be disabled with placeholder "Select a client first"

#### Scenario: Contact is cleared when client changes
- **GIVEN** a request linked to client "Gemeente Utrecht" and contact "Jan de Vries"
- **WHEN** the user changes the client to "Provincie Zuid-Holland"
- **THEN** the `contact` field MUST be cleared (null)
- **THEN** the contact picker MUST refresh to show contacts for the new client

#### Scenario: View contact details from request detail
- **GIVEN** a request with a linked contact person
- **WHEN** user views the request detail
- **THEN** the contact person's name MUST be displayed as a clickable link to the contact detail view
- **THEN** direct communication details (email, phone) MUST be shown inline

---

### Requirement: Request Notes and Activity Timeline [MVP]

The system MUST support adding notes to requests via the Nextcloud ICommentsManager and displaying an activity timeline showing status changes, assignment changes, and user notes. This leverages the existing `EntityNotes` component and `ActivityService`.

#### Scenario: Add a note to a request
- **GIVEN** a user viewing request "Aanvraag omgevingsvergunning" detail view
- **WHEN** the user types "Wachten op aanvullende documenten" in the notes textarea and clicks "Add note"
- **THEN** the note MUST be persisted via the Pipelinq notes API (`/api/notes/pipelinq_request/{id}`)
- **THEN** the note MUST appear immediately in the notes list with the author name and timestamp
- **THEN** the note MUST show a relative timestamp (e.g., "Just now", "5 minutes ago")

#### Scenario: Delete own note
- **GIVEN** a user viewing a request with their own note
- **WHEN** the user clicks "Delete" on their note
- **THEN** the note MUST be removed from the notes list
- **THEN** notes authored by other users MUST NOT show a delete button

#### Scenario: Activity timeline shows status changes
- **GIVEN** a request that was changed from `new` to `in_progress` by user "admin"
- **WHEN** user views the request's activity stream (via Nextcloud Activity app)
- **THEN** the timeline MUST show an entry: "admin changed status of request 'Title' to In progress"
- **THEN** the activity event MUST be published via `ActivityService::publishStatusChanged()`

#### Scenario: Activity timeline shows assignment changes
- **GIVEN** a request reassigned from "Jan" to "Maria" by user "admin"
- **WHEN** user views the request's activity stream
- **THEN** the timeline MUST show: "admin assigned request 'Title' to Maria"
- **THEN** the assigned user (Maria) MUST receive a Nextcloud notification via `NotificationService`

---

### Requirement: Request SLA Tracking [Enterprise]

The system SHOULD support tracking service level agreement (SLA) response and resolution times for requests. SLA targets are defined per category or globally and enable monitoring of service quality.

#### Scenario: SLA response time tracking
- **GIVEN** a request created at 2026-03-20 09:00 with SLA response target of 4 business hours
- **WHEN** the request status changes from `new` to `in_progress` at 2026-03-20 12:30
- **THEN** the system MUST record the response time as 3.5 hours
- **THEN** the response time MUST be displayed on the request detail view as "3h 30m (within SLA)"
- **THEN** the SLA indicator MUST show a green badge

#### Scenario: SLA response time breached
- **GIVEN** a request created at 2026-03-20 09:00 with SLA response target of 4 business hours
- **WHEN** the request remains in status `new` at 2026-03-20 14:00
- **THEN** the system MUST flag the request as "SLA breached" with a red indicator
- **THEN** the request MUST appear in a "Breached SLA" filter on the list view
- **THEN** an overdue notification MUST be sent to the assigned user (if any) and the team lead

#### Scenario: SLA resolution time tracking
- **GIVEN** a request created at 2026-03-20 09:00 with SLA resolution target of 5 business days
- **WHEN** the request status changes to `completed` at 2026-03-23 16:00
- **THEN** the system MUST record the total resolution time as approximately 3 business days
- **THEN** the resolution time MUST be displayed on the request detail view

#### Scenario: SLA targets per category
- **GIVEN** admin has configured SLA targets: "IT Services" = 2h response / 3d resolution, "HR" = 4h response / 5d resolution
- **WHEN** a request is created with category "IT Services"
- **THEN** the SLA countdown MUST use the "IT Services" targets (2h/3d)
- **THEN** if no category is set, the system MUST fall back to global SLA defaults

---

### Requirement: Bulk Request Operations [V1]

The system MUST support performing actions on multiple requests at once from the list view. Bulk actions reduce repetitive work for request handlers processing a high volume of incoming requests.

#### Scenario: Select multiple requests for bulk status change
- **GIVEN** user views the request list with checkboxes enabled (selectable mode)
- **WHEN** user selects 5 requests with status `new` and clicks "Change status" from the bulk actions bar
- **THEN** the system MUST display a status picker showing only transitions valid for ALL selected requests
- **THEN** upon selecting `in_progress`, all 5 requests MUST have their status updated to `in_progress`
- **THEN** a success notification MUST indicate "5 requests updated"

#### Scenario: Bulk assignment
- **GIVEN** user selects 3 unassigned requests from the list
- **WHEN** user clicks "Assign" from the bulk actions bar and selects user "Maria van Dijk"
- **THEN** all 3 requests MUST have their `assignee` field set to Maria's UID
- **THEN** Maria MUST receive a single notification summarizing the 3 assigned requests

#### Scenario: Bulk delete
- **GIVEN** user selects 4 requests, 3 with status `new` and 1 with status `converted`
- **WHEN** user clicks "Delete" from the bulk actions bar
- **THEN** the system MUST display a confirmation dialog listing the 3 deletable requests
- **THEN** the system MUST warn that 1 converted request will be skipped
- **THEN** upon confirmation, only the 3 non-converted requests MUST be deleted

#### Scenario: Bulk actions bar visibility
- **GIVEN** user is on the request list view
- **WHEN** no requests are selected
- **THEN** the bulk actions bar MUST be hidden
- **WHEN** 1 or more requests are selected
- **THEN** the bulk actions bar MUST appear showing: "X selected" count, Status change, Assign, Delete buttons

---

### Requirement: Request Templates [V1]

The system SHOULD support pre-defined request templates to standardize common request types. Templates pre-fill the title, description, category, priority, and optionally the pipeline/stage, reducing data entry time and ensuring consistency.

#### Scenario: Create a request from a template
- **GIVEN** admin has configured a template "Melding openbare ruimte" with title pattern "Melding: {locatie}", category "Openbare Ruimte", priority `normal`, channel "website"
- **WHEN** user clicks "New request" and selects the template "Melding openbare ruimte"
- **THEN** the request form MUST be pre-filled with the template values
- **THEN** the user MUST be able to modify any pre-filled field before saving
- **THEN** the `title` placeholder MUST show "Melding: {locatie}" prompting the user to fill in the location

#### Scenario: Admin manages templates
- **GIVEN** admin navigates to Pipelinq settings
- **WHEN** admin opens the "Request Templates" section
- **THEN** admin MUST be able to create, edit, and delete templates
- **THEN** each template MUST define: name (internal label), title (default value or pattern), description, category, priority, channel, and optionally a default pipeline

#### Scenario: Template selection during quick create
- **GIVEN** user opens the quick-create request dialog from the pipeline kanban board
- **WHEN** the dialog loads and templates are available
- **THEN** a template selector MUST appear at the top of the form
- **THEN** selecting a template MUST populate the form fields without replacing user-entered data in fields already modified

---

### Requirement: Request Reporting and KPIs [V1]

The system MUST provide reporting capabilities for request management performance. KPIs include request volume, average handling time, status distribution, and assignment workload. Reports leverage the existing `MetricsRepository` and `MetricsFormatter` services.

#### Scenario: Request status distribution on dashboard
- **GIVEN** the Pipelinq dashboard is loaded with 50 requests in various statuses
- **WHEN** user views the dashboard
- **THEN** the system MUST display a status distribution chart showing count per status (new: 12, in_progress: 18, completed: 15, rejected: 3, converted: 2)
- **THEN** the chart MUST use the standard status colors (blue, amber, green, red, purple)

#### Scenario: Request volume over time
- **GIVEN** the system has request data spanning 3 months
- **WHEN** user views the request reporting section
- **THEN** the system MUST display a line chart showing new requests created per week
- **THEN** the chart MUST support toggling between daily, weekly, and monthly intervals

#### Scenario: Average handling time KPI
- **GIVEN** 20 completed requests exist with varying durations from `new` to `completed`
- **WHEN** user views the request KPI cards on the dashboard
- **THEN** the system MUST display the average handling time across all completed requests
- **THEN** the KPI card MUST show trend direction (improving or declining) compared to the previous period

#### Scenario: Assignment workload distribution
- **GIVEN** 30 requests are distributed across 5 assignees
- **WHEN** user views the reporting section
- **THEN** the system MUST display a bar chart showing open request count per assignee
- **THEN** unassigned requests MUST appear as a separate bar labeled "Unassigned"

#### Scenario: Prometheus metrics for requests
- **GIVEN** the Prometheus metrics endpoint is queried
- **WHEN** the `/metrics` endpoint is called
- **THEN** the system MUST expose `pipelinq_requests_total` gauge with status and pipeline labels
- **THEN** the system MUST expose `pipelinq_requests_handling_time_seconds` histogram

---

### Requirement: Request Search and Faceted Filtering [MVP]

The system MUST support full-text search across request fields and faceted filtering using OpenRegister's `facetable` schema properties. The request schema marks `status`, `priority`, `assignee`, `category`, `channel`, and `stage` as facetable.

#### Scenario: Faceted filter counts
- **GIVEN** the request list displays 40 requests
- **WHEN** the filter sidebar loads
- **THEN** the system MUST show facet counts for each status value (e.g., "New (12)", "In progress (18)")
- **THEN** the system MUST show facet counts for priority, channel, and category

#### Scenario: Facet selection narrows results and updates counts
- **GIVEN** user views the request list with 40 total requests
- **WHEN** user clicks the "In progress" facet under Status
- **THEN** the list MUST filter to show only 18 in-progress requests
- **THEN** the remaining facets (priority, channel, category) MUST update their counts to reflect the filtered set
- **THEN** the status facet "In progress" MUST show as selected with an option to clear

#### Scenario: Full-text search combined with facets
- **GIVEN** user has filtered requests by status "new"
- **WHEN** user types "vergunning" in the search box
- **THEN** the results MUST show only `new` requests whose title or description contains "vergunning"
- **THEN** the result count and facet counts MUST update accordingly

#### Scenario: Clear all filters
- **GIVEN** user has 3 active facet filters and a search term
- **WHEN** user clicks "Clear all filters"
- **THEN** all facet selections and the search term MUST be reset
- **THEN** the full unfiltered list MUST be displayed

---

### Requirement: Request-to-Case Conversion with Data Mapping [V1]

When converting a request to a Procest case, the system MUST map request fields to case fields following the VNG Verzoek-to-Zaak relationship pattern. This extends REQ-RM-090 with detailed field mapping and error handling.

#### Scenario: Field mapping during conversion
- **GIVEN** a request with title "Aanvraag kapvergunning", description "Boom op Marktplein 5", category "Vergunningen", client "Gemeente Utrecht", contact "Jan de Vries"
- **WHEN** user clicks "Convert to case"
- **THEN** the system MUST create a Procest case with:
  - case title = request title ("Aanvraag kapvergunning")
  - case description = request description
  - case type = request category ("Vergunningen") mapped to a Procest zaaktype if available
  - case initiator = request client reference
  - case contact = request contact reference
- **THEN** the Procest case MUST store a back-reference to the originating request ID

#### Scenario: Conversion pre-check dialog
- **GIVEN** a request with status `in_progress` and all required fields populated
- **WHEN** user clicks "Convert to case"
- **THEN** the system MUST display a confirmation dialog showing the field mapping preview
- **THEN** the dialog MUST list: case title, case type, linked client, linked contact
- **THEN** the user MUST confirm before the case is created

#### Scenario: Conversion fails due to missing Procest app
- **GIVEN** the Procest app is not installed or not enabled on the Nextcloud instance
- **WHEN** user clicks "Convert to case"
- **THEN** the system MUST display an error: "Procest app is not available. Install and enable Procest to convert requests to cases."
- **THEN** the request status MUST remain `in_progress`

#### Scenario: View conversion history
- **GIVEN** a converted request with `caseReference` pointing to Procest case #42
- **WHEN** user views the request detail
- **THEN** the system MUST display a "Converted Case" card showing the case title, case status, and a clickable link
- **THEN** clicking the link MUST navigate to `/apps/procest/cases/42`

---

### Requirement: Request Pipeline Kanban View [MVP]

Requests placed on a pipeline MUST appear on the kanban board alongside leads. Request-specific kanban interactions include drag-and-drop stage changes, quick status updates, and visual differentiation from leads.

#### Scenario: Drag request card between stages
- **GIVEN** the "Service Pipeline" kanban board with request "Aanvraag vergunning" at stage "New"
- **WHEN** user drags the request card to stage "In Progress"
- **THEN** the request `stage` MUST update to "In Progress"
- **THEN** the request `stageOrder` MUST update to the target stage's order value
- **THEN** the request `status` MUST remain unchanged (pipeline stage and request status are independent)

#### Scenario: Request card displays key information
- **GIVEN** a request with title "Melding overlast", priority `high`, assignee "Maria", status `in_progress`
- **WHEN** the request card renders on the kanban board
- **THEN** the card MUST display: [REQ] badge (color-coded), title "Melding overlast", priority badge (amber for high), assignee avatar for "Maria"
- **THEN** the card MUST NOT display a monetary value field (unlike lead cards)

#### Scenario: Quick actions on request kanban card
- **GIVEN** user hovers over a request card on the kanban board
- **WHEN** the card action menu appears
- **THEN** the menu MUST include: "View details", "Change status", "Assign to", "Convert to case" (if status is `in_progress`)
- **THEN** selecting "Change status" MUST show only valid transitions per the lifecycle rules

#### Scenario: Auto-assign default pipeline on creation
- **GIVEN** a default service pipeline exists with stages "New", "In Progress", "Completed"
- **WHEN** user creates a new request without selecting a pipeline
- **THEN** the request MUST be automatically placed on the default pipeline at the first open stage ("New")
- **THEN** the request MUST appear on the kanban board immediately

---

### Requirement: Request Notification and Assignment Alerts [V1]

The system MUST send Nextcloud notifications when requests are created, assigned, reassigned, or have their status changed. This leverages the existing `ObjectEventHandlerService`, `ObjectEventDispatcher`, and `NotificationService`.

#### Scenario: Notification on request creation with assignment
- **GIVEN** user "admin" creates a request "Storing waterleiding" and assigns it to "Jan"
- **WHEN** the request is saved
- **THEN** Jan MUST receive a Nextcloud notification: "You have been assigned request 'Storing waterleiding'"
- **THEN** the notification MUST include a deep link to the request detail view
- **THEN** an activity event MUST be published: "admin created request 'Storing waterleiding'"

#### Scenario: Notification on reassignment
- **GIVEN** request "Storing waterleiding" is currently assigned to "Jan"
- **WHEN** user "admin" reassigns the request to "Maria"
- **THEN** Maria MUST receive a notification: "You have been assigned request 'Storing waterleiding'"
- **THEN** Jan MUST receive a notification: "Request 'Storing waterleiding' has been reassigned to Maria"
- **THEN** an activity event MUST be published via `ActivityService::publishAssignmentChanged()`

#### Scenario: Notification on status change to terminal
- **GIVEN** request "Storing waterleiding" is assigned to "Jan" with status `in_progress`
- **WHEN** user "admin" changes the status to `completed`
- **THEN** Jan MUST receive a notification: "Request 'Storing waterleiding' has been completed"
- **THEN** an activity event MUST be published via `ActivityService::publishStatusChanged()`

#### Scenario: Notification suppressed for self-actions
- **GIVEN** user "Jan" is assigned to request "Storing waterleiding"
- **WHEN** Jan changes the request status to `completed` himself
- **THEN** Jan MUST NOT receive a notification about his own action
- **THEN** the activity event MUST still be published for the activity stream

---

### Requirement: Request Quick Create from Client Detail [MVP]

The system MUST support creating a request directly from a client's detail view, pre-linking the request to that client. This provides a natural workflow where a service agent receiving a call can create a request without leaving the client context.

#### Scenario: Create request from client detail
- **GIVEN** user views the detail page of client "Gemeente Utrecht"
- **WHEN** user clicks "New request" in the client's requests section
- **THEN** the system MUST open the `RequestCreateDialog` overlay
- **THEN** the client field MUST be pre-populated with "Gemeente Utrecht" (using the `preLinkedClient` prop)
- **THEN** the contact picker MUST show only contacts belonging to "Gemeente Utrecht"

#### Scenario: Created request appears in client's request list
- **GIVEN** user creates request "Aanvraag vergunning" from client "Gemeente Utrecht" detail view
- **WHEN** the request is saved successfully
- **THEN** the request MUST immediately appear in the client's "Requests" section on the detail view
- **THEN** the system MUST emit the `created` event with the new request ID

#### Scenario: Cancel quick create returns to client detail
- **GIVEN** user opened the request create dialog from client "Gemeente Utrecht" detail view
- **WHEN** user clicks "Cancel" or closes the dialog
- **THEN** the dialog MUST close
- **THEN** the user MUST remain on the client detail view with no changes

---

## UI Layout Reference

### Request List View

The request list MUST follow the standard Pipelinq list pattern:

- Table with columns: Priority indicator, Title, Status (badge), Channel, Category, Assigned To, Requested At
- Quick status change dropdown on each row
- Action menu per row: View, Edit, Assign, Delete
- Filter bar above table: status, priority, assignee, channel (V1)
- Faceted filter sidebar with counts for facetable fields
- Search input for keyword search
- Pagination controls
- Selectable rows with bulk actions bar (V1)

### Request Detail View

The request detail MUST follow the standard Pipelinq detail pattern (see DESIGN-REFERENCES.md section 3.4 adapted for requests):

- Left column: Core info (title, description, status, priority, channel, category, requestedAt), Client section (linked client with contact info), Contact person section (linked contact with direct communication details)
- Right column: Pipeline progress (if on pipeline), Assignment section, Status change actions, SLA indicators (Enterprise)
- Bottom: Notes section via EntityNotes component, Activity timeline with status changes, assignments
- Top bar: Edit button, actions menu (Convert to case, Delete)

### Request Card (Pipeline Kanban)

When displayed on a pipeline kanban board, request cards MUST show:

```
[REQ] badge (color-coded)
Request Title
Priority badge (if not normal)
Due date (if available) + Assignee avatar
Overdue warning (if applicable)
```

---

### Current Implementation Status

**Implemented:**
- **REQ-RM-010 (Request CRUD):** Fully implemented. Request schema defined in `lib/Settings/pipelinq_register.json` with `@type: schema:Demand`. Properties include title, description, client, contact, status, priority, category, requestedAt, pipeline, stage, stageOrder, assignee, channel, caseReference. CRUD via OpenRegister API.
- **REQ-RM-020 (Request Status Lifecycle):** Fully implemented in `src/services/requestStatus.js`:
  - Status transitions: `new -> [in_progress, rejected, completed]`, `in_progress -> [completed, rejected, converted]`, terminal states: `completed`, `rejected`, `converted`.
  - `getAllowedTransitions()`, `isValidTransition()`, `isTerminalStatus()` functions.
  - Status labels (Dutch): New, In progress, Completed, Rejected, Converted to case.
  - Status colors: blue (new), amber (in_progress), green (completed), red (rejected), purple (converted).
- **REQ-RM-030 (Request List View):** Implemented in `src/views/requests/RequestList.vue` using `CnIndexPage` from `@conduction/nextcloud-vue`. Default sort by `requestedAt` descending. Quick status change dropdown on each row. Status and priority badges.
- **REQ-RM-040 (Request Detail View):** Implemented in `src/views/requests/RequestDetail.vue` using `CnDetailPage`. Shows status/priority badges, status transition dropdown, edit button, "Convert to case" button (conditional), delete button (conditional). Converted request notice with case link. Client link section. Pipeline progress indicator with stage visualization and "Move to next stage" button.
- **REQ-RM-050 (Request Assignment):** Implemented with user picker (NcSelect fetching from OCS users API). Assignment, reassignment, and unassignment supported.
- **REQ-RM-060 (Request Priority):** Four priority levels (low, normal, high, urgent) with color-coded labels. Priority labels and colors in `requestStatus.js`.
- **REQ-RM-070 (Channel Tracking):** Default channels (phone, email, website, counter, post) created via SystemTags in repair step. `RequestChannelController` and `requestChannels.js` store manage channel options. Channel field present in request schema and form.
- **REQ-RM-090 (Request-to-Case Conversion):** Partially implemented. "Convert to case" button visible when `canConvert` is true (status `in_progress`). Converted requests show read-only notice. Status transition to `converted` is defined. However, actual Procest case creation is stubbed with TODO comment — `caseReference` is not populated yet.
- **REQ-RM-100 (Request on Pipeline):** Request schema includes `pipeline`, `stage`, `stageOrder` fields. Requests appear on pipeline kanban board with [REQ] badge. Request cards show title, status, priority, assignee (no value field). Auto-assignment to default pipeline on creation is implemented in `RequestForm.vue`.
- **REQ-RM-110 (Validation Rules):** Frontend validation via `requestStatus.js` for allowed transitions. Priority validation via `isValidPriority()`. Title required validation in `RequestForm.vue`. Server-side validation via OpenRegister schema constraints (minLength, enum).
- **REQ-RM-130 (Notes/Activity):** Notes implemented via `EntityNotes` component using `NotesService` (ICommentsManager). Activity events published via `ActivityService` for create, status change, and assignment events. `ObjectEventHandlerService` triggers events on OpenRegister object changes.
- **REQ-RM-200 (Pipeline Kanban):** Implemented via `PipelineBoard.vue` and `PipelineCard.vue`. Request cards show [REQ] badge. Drag-and-drop between stages supported. Auto-assign to default pipeline on creation.
- **REQ-RM-210 (Notifications):** Implemented via `NotificationService` and `ObjectEventDispatcher`. Notifications sent on creation with assignment and on reassignment.
- **REQ-RM-220 (Quick Create from Client):** Implemented via `RequestCreateDialog.vue` with `preLinkedClient` prop. Dialog emits `created` event on success.

**Not yet implemented:**
- **REQ-RM-030 (List View) - Faceted Filtering:** Faceted filtering with counts is not implemented in the `CnIndexPage`-based list. Schema properties are marked `facetable` but the frontend does not render a faceted filter sidebar.
- **REQ-RM-080 (Category/Product Classification):** Category is free-text only. No admin-configurable pre-populated list of categories.
- **REQ-RM-090 / REQ-RM-190 (Request-to-Case Conversion):** Procest integration is not functional. The `convertToCase()` method has a TODO for case creation and `caseReference` is never populated. `viewCase()` navigation is empty.
- **REQ-RM-120 (Request-Contact Linking):** The `contact` field exists in the schema but the `RequestForm.vue` does not include a contact person picker. The detail view does not display the linked contact.
- **REQ-RM-140 (SLA Tracking):** Not implemented. No SLA target configuration, response/resolution time tracking, or breach notifications.
- **REQ-RM-150 (Bulk Operations):** List is selectable (`selectable: true` in `CnIndexPage`) but no bulk actions bar or bulk operation handlers exist.
- **REQ-RM-160 (Request Templates):** Not implemented. No template configuration in admin settings or template selection in the creation flow.
- **REQ-RM-170 (Reporting/KPIs):** `MetricsRepository` has `getLeadCounts()` but no equivalent `getRequestCounts()` method. No request-specific dashboard charts.
- **REQ-RM-180 (Faceted Filtering):** Schema fields are marked as `facetable` but the frontend does not use OpenRegister's facet API.

**Partial implementations:**
- Quick status change from list view works but the list filtering capabilities are limited compared to the spec's requirements.
- Pipeline kanban view works for request cards but quick actions menu (hover actions) is not implemented.
- Notifications work for creation and assignment but status change notifications to the assignee are not verified.

### Standards & References
- **Schema.org:** `Demand` type for requests (same as leads).
- **VNG Verzoeken API:** `Verzoek` entity mapping is documented in the data model table. Properties map to VNG fields (Verzoek.tekst, Verzoek.status, registratiedatum, KlantVerzoek).
- **Common Ground:** Request-to-case conversion bridges CRM and case management (Procest).
- **WCAG AA:** Priority badges use color AND text labels for accessibility. Status badges use contrasting white text on colored background.

### Specificity Assessment
- The spec covers 15 requirements spanning MVP, V1, and Enterprise tiers.
- Entity model is fully defined in `pipelinq_register.json` with all properties including `contact` and `channel`.
- Status lifecycle is rigorously specified with explicit transition table and validation rules.
- Notes/activity infrastructure is implemented and leverages Nextcloud's ICommentsManager and Activity IManager.
- **Key gaps for V1:** Procest case creation integration, faceted filtering, contact person picker in form, bulk operations, and request-specific reporting.
- **Key gaps for Enterprise:** SLA tracking requires new data model fields (target times, breach timestamps) and a background job for breach detection.
- **Open questions:**
  - Should the category field use a predefined list or free text? The spec says "free-text strings that MAY be pre-populated."
  - Should deleted requests be soft-deleted (archived) or hard-deleted?
  - The spec says converted requests cannot be deleted, but can they be archived?
  - Should SLA tracking use business hours only or calendar hours? Spec assumes business hours.

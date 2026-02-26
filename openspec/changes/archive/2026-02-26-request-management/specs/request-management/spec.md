## MODIFIED Requirements

### REQ-RM-010: Request CRUD [MVP]

The system MUST support creating, reading, updating, and deleting request records. Each request MUST have a `title`. The `status` MUST default to `new` when not explicitly provided. The `channel` field MUST be added to the OpenRegister schema to support persistence.

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

### REQ-RM-020: Request Status Lifecycle [MVP]

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

### REQ-RM-030: Request List View [MVP]

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

### REQ-RM-040: Request Detail View [MVP]

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

### REQ-RM-050: Request Assignment [MVP]

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

### REQ-RM-060: Request Priority [MVP]

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

### REQ-RM-070: Request Channel Tracking [V1]

The system MUST support tracking the intake channel. Channel values come from SystemTag-based admin settings (already implemented). The `channel` field MUST be added to the OpenRegister schema.

#### Scenario: Set channel during creation
- **WHEN** user creates a request and selects channel "phone" from the dropdown
- **THEN** the request MUST store `channel` as "phone" in OpenRegister

#### Scenario: Channel dropdown uses admin-configured values
- **WHEN** user opens the channel dropdown on the request form
- **THEN** the options MUST come from the request channels store (SystemTag-based)

### REQ-RM-090: Request-to-Case Conversion [V1]

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

### REQ-RM-100: Request on Pipeline [MVP]

A request MAY optionally be placed on a pipeline. When on a pipeline, the request has a `stage`, `stageOrder`, and appears on the kanban board.

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

### REQ-RM-110: Request Validation Rules [MVP]

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

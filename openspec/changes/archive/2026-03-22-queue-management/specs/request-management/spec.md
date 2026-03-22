## ADDED Requirements

### Requirement: Request Queue Field [Enterprise]

The request entity SHALL support a `queue` reference field linking the request to a queue. This field is optional; requests can exist without being in a queue.

#### Scenario: Request with queue reference
- **WHEN** a request is assigned to queue "Vergunningen"
- **THEN** the request's `queue` field SHALL store the queue's UUID
- **THEN** the request SHALL appear in the queue's item list view

#### Scenario: Request without queue
- **WHEN** a request has no queue assigned
- **THEN** the `queue` field SHALL be null
- **THEN** the request SHALL function normally with its existing status lifecycle and pipeline placement

#### Scenario: Queue field in request list view
- **WHEN** the request list is displayed
- **THEN** a "Queue" column SHALL be available (hideable) showing the queue title or "--" if unqueued

#### Scenario: Queue field in request detail view
- **WHEN** the request detail view is displayed for a queued request
- **THEN** the queue name SHALL be displayed as a link to the queue detail view
- **THEN** a "Change queue" action SHALL be available

#### Scenario: Assign to queue from request detail
- **WHEN** an agent clicks "Change queue" on a request detail view
- **THEN** a dropdown SHALL show all active queues
- **THEN** selecting a queue SHALL update the request's `queue` field

## MODIFIED Requirements

### Requirement: Request CRUD [MVP]

The system MUST support creating, reading, updating, and deleting request records. Each request MUST have a `title`. The `status` MUST default to `new` when not explicitly provided. The `channel` field MUST be added to the OpenRegister schema to support persistence. The `queue` field SHALL be supported as an optional reference to a queue entity.

#### Scenario: Create a minimal request
- **WHEN** a user submits a new request with title "Aanvraag omgevingsvergunning"
- **THEN** the system MUST create an OpenRegister object with `@type` set to `schema:Demand`
- **THEN** the `status` MUST be set to `new`
- **THEN** `requestedAt` MUST be set to the current UTC timestamp
- **THEN** `priority` MUST default to `normal`
- **THEN** `queue` SHALL default to null

#### Scenario: Create a request linked to a client
- **WHEN** the user creates a request and selects client "Gemeente Utrecht"
- **THEN** the request `client` field MUST store a reference to the client object via `schema:customer`
- **THEN** the request MUST appear in the client's detail view under "Requests"

#### Scenario: Create a request with all optional fields
- **WHEN** user creates a request with title "Website redesign inquiry", description "Client wants a full redesign", priority `high`, category "IT Services", channel "email", and queue "Algemene Zaken"
- **THEN** the system MUST store all provided fields on the OpenRegister object including channel and queue
- **THEN** all fields MUST be retrievable via the API

#### Scenario: Validation - title is required
- **WHEN** user submits a request without a `title` (empty string or missing)
- **THEN** the system MUST reject the creation with a validation error
- **THEN** the error message MUST indicate that `title` is required

#### Scenario: Update request fields
- **WHEN** the user updates the description, priority, category, channel, or queue of a request with status `new`
- **THEN** the system MUST persist the changes to the OpenRegister object

#### Scenario: Delete a request
- **WHEN** the user deletes a request with status `new` or `in_progress`
- **THEN** the system MUST remove the OpenRegister object
- **THEN** the request MUST no longer appear in list views

#### Scenario: Delete a converted request is blocked
- **WHEN** the user attempts to delete a request with status `converted` and a `caseReference`
- **THEN** the system MUST prevent deletion
- **THEN** the system MUST display an error that converted requests with active case links cannot be deleted

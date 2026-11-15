# Request Management

Service intake and request tracking before conversion to formal cases. Requests bridge the gap between CRM (Pipelinq) and case management (Procest).

## Specs

- `openspec/specs/request-management/spec.md`

## Features

### Request CRUD (MVP)

Full create, read, update, and delete for request records. Requests represent service inquiries or intake items linked to clients.

- Request list view with search, sort, and filter
- Request detail view with client link and status information
- Fields: title, description, status, priority, assignedTo, client, dueDate
- Client linking with navigation

### Request Status Lifecycle (MVP)

Requests follow a defined status flow: `new` → `in_progress` → `completed` / `rejected` / `converted`. Status changes are tracked for audit purposes.

### Request Priority (MVP)

Four-level priority system (low, normal, high, urgent) for triage and workload management.

### Request Assignment (MVP)

Requests can be assigned to users. Assigned requests appear in the user's My Work view sorted by priority and due date.

### Request on Pipeline (MVP)

Requests can optionally be placed on a pipeline board alongside leads for visual workflow management in the kanban view.

### Request Validation Rules (MVP)

- Title is required
- Status must be one of the allowed values
- Priority must be one of the allowed values

### Error Handling (MVP)

- Structured error feedback with retry actions in list view
- Error toasts on save/delete failures
- Orphaned client reference handling (`[Deleted client]` placeholder)

### What the resident sees

A request ticket shows a section "What the resident sees". It is the request
as the resident portal shows it: number, subject, category, status, date,
description, your message to the customer and the resident's own replies, and
your name only when the portal settings show the handler's name. When portaliq
is installed and the ticket belongs to an organisation, a second panel shows
what that organisation's contact reads in portaliq, including the message to
the customer. One line says everything else on the ticket stays internal, and
"Show internal fields" lists those fields by name.

The message to the customer is the one field written for the resident: save it
and the section shows it at once. Complaint and contact moment tickets have no
section, because the portal does not show them.

The section reads `GET /apps/pipelinq/api/tickets/{id}/resident-view`, which
answers `{bespoke, portaliq, internalFields}` for a ticket you may read and 404
for one you may not. Both panels are built by the portal's own code, so the
preview cannot drift from what the resident reads.

### Planned (V1)

- Request channel tracking (phone, email, web, counter)
- Request category/product classification
- Request-to-case conversion (bridge to Procest)

### Request Queue Assignment (Enterprise)

Requests can be assigned to work queues for structured workload distribution. Queue assignment is optional and independent of pipeline placement.

- Queue field on request entity (UUID reference)
- Queue name displayed in request list view as column
- Queue link and "Change queue" dropdown in request detail view
- Routing suggestion panel shown when request is queued with a category

### Planned (Enterprise)

- SLA tracking (response/resolution time)
- Configurable pipeline stages

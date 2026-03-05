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

### Planned (V1)

- Request channel tracking (phone, email, web, counter)
- Request category/product classification
- Request-to-case conversion (bridge to Procest)

### Planned (Enterprise)

- SLA tracking (response/resolution time)
- Automated assignment rules
- Configurable pipeline stages

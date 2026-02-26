## Why

Pipelinq currently has a basic request detail form but lacks a proper list view, status lifecycle enforcement, assignment, filtering, and pipeline integration. Requests are the bridge between CRM (Pipelinq) and case management (Procest) — without proper request management, users cannot track service inquiries through intake to resolution or conversion. The request-management spec defines MVP and V1 requirements that need full implementation.

## What Changes

- Build a complete request list view with table, search, filtering (status, priority, assignee), sorting, and pagination
- Implement status lifecycle enforcement with allowed transitions (new → in_progress → completed/rejected/converted)
- Add request assignment to Nextcloud users via user picker
- Add priority visual indicators (color-coded badges) across list and detail views
- Rebuild the request detail view with proper layout: core info, client link, pipeline position, activity timeline
- Add quick status change from list view rows
- Wire channel dropdown to SystemTag-based request channels (admin-settings V1, already implemented)
- Add request cards to pipeline kanban boards (visually distinct from lead cards)
- Add request-to-case conversion flow (V1) — creates a Procest case and sets status to `converted`
- Add category field support with filtering (V1)
- Enforce validation rules: required title, valid status transitions, valid priority values, valid client references

## Capabilities

### New Capabilities

_(none — request-management spec already exists)_

### Modified Capabilities

- `request-management`: Implementing all MVP requirements (REQ-RM-010 through REQ-RM-060, REQ-RM-100, REQ-RM-110) and V1 requirements (REQ-RM-070, REQ-RM-080, REQ-RM-090). The existing spec is complete; this change implements it.
- `pipeline`: Request cards on kanban boards need visual distinction from lead cards. Pipeline must support `request` entity type.

## Impact

- **Frontend**: New `RequestList.vue` component, rebuilt `RequestDetail.vue`, new `RequestCard.vue` for kanban, updated pipeline kanban to render request cards
- **Backend**: New `RequestService.php` for status lifecycle validation and request-to-case conversion. Updated `ObjectController` or new `RequestController` for request-specific business logic
- **Stores**: New/updated `request.js` Pinia store with filtering, pagination, and status transition logic
- **Routes**: Request-specific API routes for status transitions and case conversion
- **Dependencies**: Procest app (optional) for request-to-case conversion; OpenRegister for object storage
- **Admin Settings**: Channel dropdown already wired via SystemTag (implemented in admin-settings change)

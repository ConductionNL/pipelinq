# Collaboration

Internal notes and activity tracking across all CRM entities, enabling team communication and audit trails.

## Specs

- `openspec/specs/entity-notes/spec.md`
- `openspec/specs/notifications-activity/spec.md`

## Features

### Entity Notes (MVP)

Internal notes/comments on all Pipelinq entities (clients, contacts, leads, requests) using Nextcloud's ICommentsManager. Enables team collaboration and context tracking directly on CRM records.

- Notes CRUD: create, read, and delete notes on any entity
- Notes display: chronological list on entity detail views
- Supported entity types: `pipelinq_client`, `pipelinq_contact`, `pipelinq_lead`, `pipelinq_request`
- Automatic cleanup: notes are deleted when the parent entity is deleted

### Comment Cleanup (MVP)

When an entity is deleted, all associated comments are cleaned up via a DELETE call to the notes API. This prevents orphaned comments from accumulating.

### Planned (V1) — Notifications

Real-time notifications for CRM events, integrated with Nextcloud's notification system:

- Assignment notifications (when a lead/request is assigned to you)
- Status change notifications (when a lead/request status changes)
- Per-category notification preferences (users choose what to receive)
- Notification rendering with CRM-specific formatting

### Planned (V1) — Activity Stream

Team-visible activity timeline publishing CRM events to Nextcloud's activity stream:

- Lead created/updated/stage changed
- Request created/status changed
- Client/contact modifications
- Unified timeline visible across the team

### Planned (V1)

- User mentions in notes
- Email logging (link Mail messages to entities)

### Planned (Enterprise)

- Email templates for standardized communications
- Mass email/campaigns

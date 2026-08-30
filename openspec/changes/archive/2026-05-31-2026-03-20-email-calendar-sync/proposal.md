> SUPERSEDED 2026-05-31: feature implemented; archived twin archive/2026-03-21-email-calendar-sync. Archived as already-delivered.

# Proposal: email-calendar-sync

## Problem

Pipelinq has no integration with Nextcloud Mail or Calendar. Emails are not linked to CRM contacts. Calendar events for follow-ups are not synced. All communication context must be manually tracked, creating a significant gap in client relationship visibility. 46% of active tenders require communication capabilities, meaning over half of Pipelinq's sales-facing use cases are blocked.

Sales representatives must switch between Nextcloud Mail and Pipelinq to reconstruct conversation history. Follow-up meetings are scheduled in Nextcloud Calendar with no CRM context. There is no way to see all communication with a client from the client detail view.

## Solution

Implement email and calendar sync with:
1. **EmailLink and CalendarLink schemas** for storing sync metadata in OpenRegister
2. **EmailSyncJob** background job for periodic email matching (every 5 minutes)
3. **EmailSyncService** for email-to-contact matching by address and domain
4. **CalendarSyncService** for bidirectional calendar event sync and follow-up creation
5. **Sync settings UI** for per-user mail account selection and privacy controls

## Features

| Feature | Description |
|---------|-------------|
| Email sync background job | ITimedJob running every 5 minutes, syncing new emails from selected mail accounts |
| Email-to-contact matching | Match inbound/outbound emails to clients, contacts, leads, and requests by email address |
| Domain-to-organization matching | Match email domain to client organizations, skipping public domains (gmail, outlook, etc.) |
| Calendar event sync | Create Nextcloud Calendar events from CRM entity context; match existing events to entities by attendee email |
| Per-user sync configuration | UI for selecting which mail accounts to sync, with privacy controls per entity type |
| Sync status display | Show sync status and linked email/calendar count on entity detail views |

## Scope

- EmailLink and CalendarLink schemas in OpenRegister (`pipelinq_register.json`)
- Email sync background job (ITimedJob, every 5 minutes)
- Email-to-contact matching by email address
- Domain-to-organization matching (with public domain blocklist)
- Calendar event creation from entity context
- Calendar event-to-entity matching by attendee email addresses
- Per-user sync configuration (mail account selection, privacy controls)
- Sync status display on client, contact, lead, and request detail views
- Email timeline component for displaying linked emails per entity

## Out of Scope

- "Link to Pipelinq" action in Nextcloud Mail (requires Mail app changes — V2)
- Email template quick-send from entity context (V2)
- Sync monitoring admin dashboard (V2)
- Full email body storage in OpenRegister (body accessed on-demand from Nextcloud Mail)
- Outbox/send integration (read-only sync only for V1)

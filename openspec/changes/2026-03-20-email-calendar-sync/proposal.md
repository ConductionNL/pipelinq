# Proposal: email-calendar-sync

## Problem

Pipelinq has no integration with Nextcloud Mail or Calendar. Emails are not linked to CRM contacts. Calendar events for follow-ups are not synced. All communication context must be manually tracked. 46% of tenders require communication capabilities.

## Solution

Implement email and calendar sync with:
1. **EmailLink and CalendarLink schemas** for storing sync metadata
2. **EmailSyncJob** background job for periodic email matching
3. **EmailSyncService** for email-to-contact matching by address and domain
4. **CalendarSyncService** for bidirectional calendar event sync
5. **Sync settings UI** for per-user mail account selection and privacy controls

## Scope

- EmailLink and CalendarLink schemas in OpenRegister
- Email sync background job (ITimedJob, every 5 minutes)
- Email-to-contact matching by email address
- Domain-to-organization matching
- Calendar event creation from entity context
- Per-user sync configuration
- Sync status display

## Out of scope

- "Link to Pipelinq" action in Nextcloud Mail (requires Mail app changes)
- Email template quick-send (V2)
- Sync monitoring admin dashboard (V2)

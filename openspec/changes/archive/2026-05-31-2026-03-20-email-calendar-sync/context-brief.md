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



## Design

# Design: email-calendar-sync

## Architecture

### Data Model (OpenRegister Schemas)

#### emailLink
- `messageId` (string, required) — Email message ID
- `subject` (string) — Email subject line
- `sender` (string) — Sender email address
- `recipients` (array of string) — Recipient addresses
- `date` (string, format: date-time) — Email date
- `threadId` (string) — Email thread ID for grouping
- `linkedEntityType` (string, required, enum: client/contact/lead/request, facetable)
- `linkedEntityId` (string, required, format: uuid)
- `direction` (string, enum: inbound/outbound, facetable)
- `syncSource` (string) — Mail account ID
- `excluded` (boolean, default: false) — Excluded from sync
- `deleted` (boolean, default: false) — Source email deleted

#### calendarLink
- `eventUid` (string, required) — Calendar event UID
- `title` (string) — Event title
- `startDate` (string, format: date-time) — Event start
- `endDate` (string, format: date-time) — Event end
- `attendees` (array of string) — Attendee email addresses
- `linkedEntityType` (string, required, enum: client/contact/lead/request, facetable)
- `linkedEntityId` (string, required, format: uuid)
- `status` (string, enum: scheduled/completed/cancelled, facetable)
- `createdFrom` (string, enum: pipelinq/calendar)

### Backend

#### EmailSyncService (`lib/Service/EmailSyncService.php`)
- `matchEmailToEntities(string $senderEmail, array $recipientEmails): array`
- `matchDomainToOrganization(string $domain): ?array`
- `isPublicDomain(string $domain): bool`

#### EmailSyncJob (`lib/BackgroundJob/EmailSyncJob.php`)
ITimedJob running every 5 minutes, syncing new emails.

#### CalendarSyncService (`lib/Service/CalendarSyncService.php`)
- `createFollowUpEvent(string $entityType, string $entityId, array $eventData): array`
- `matchEventToEntities(array $attendeeEmails): array`

### Frontend

#### SyncSettings.vue (`src/views/sync/SyncSettings.vue`)
Per-user sync configuration UI.

## Files Changed

### New Files
- `lib/Service/EmailSyncService.php`
- `lib/Service/CalendarSyncService.php`
- `lib/BackgroundJob/EmailSyncJob.php`
- `src/views/sync/SyncSettings.vue`
- `src/components/EmailTimeline.vue`

### Modified Files
- `lib/Settings/pipelinq_register.json` — Add emailLink, calendarLink schemas
- `src/router/index.js` — Add sync settings route



## Tasks

# Tasks: email-calendar-sync

## 1. Data Model
- [x] 1.1 Add `emailLink` schema to `pipelinq_register.json`
- [x] 1.2 Add `calendarLink` schema to `pipelinq_register.json`
- [x] 1.3 Update register's schemas list

## 2. Backend Services
- [x] 2.1 Create `lib/Service/EmailSyncService.php`
- [x] 2.2 Create `lib/Service/CalendarSyncService.php`
- [x] 2.3 Create `lib/BackgroundJob/EmailSyncJob.php`

## 3. Frontend
- [x] 3.1 Create `src/views/sync/SyncSettings.vue`
- [x] 3.2 Create `src/components/EmailTimeline.vue`
- [x] 3.3 Add sync settings route to `src/router/index.js`

## 4. Verification
- [ ] 4.1 Run `npm run build` and verify no errors
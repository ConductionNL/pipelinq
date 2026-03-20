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

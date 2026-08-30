# Tasks: email-calendar-sync

## 1. Data Model

- [x] 1.1 Add `emailLink` schema to `lib/Settings/pipelinq_register.json` with all properties from design.md (messageId, subject, sender, recipients, date, threadId, linkedEntityType, linkedEntityId, direction, syncSource, excluded, deleted)
- [x] 1.2 Add `calendarLink` schema to `lib/Settings/pipelinq_register.json` with all properties from design.md (eventUid, title, startDate, endDate, attendees, linkedEntityType, linkedEntityId, status, createdFrom, notes)
- [x] 1.3 Add `emailLink` and `calendarLink` to the register's `schemas` array in `pipelinq_register.json`

## 2. Backend Services

- [x] 2.1 Create `lib/Service/EmailSyncService.php` implementing:
  - `matchEmailToEntities(string $senderEmail, array $recipientEmails): array` — query OpenRegister for clients, contacts, leads, requests matching by email field
  - `matchDomainToOrganization(string $domain): ?array` — extract domain, skip public domains, query client organizations
  - `isPublicDomain(string $domain): bool` — check against blocklist (gmail.com, outlook.com, hotmail.com, yahoo.com, icloud.com)
- [x] 2.2 Create `lib/Service/CalendarSyncService.php` implementing:
  - `createFollowUpEvent(string $entityType, string $entityId, array $eventData): array` — create Nextcloud Calendar event, store calendarLink object
  - `matchEventToEntities(array $attendeeEmails): array` — reuse EmailSyncService address matching for attendees
- [x] 2.3 Create `lib/BackgroundJob/EmailSyncJob.php` as `ITimedJob` (interval: 300 seconds / 5 minutes) implementing:
  - Fetch users with sync enabled from user config
  - For each user and enabled mail account, fetch messages since last sync
  - Call EmailSyncService::matchEmailToEntities() per message
  - Create emailLink objects for each match (skip if messageId already exists)
  - Update last sync timestamp

## 3. Frontend Components

- [x] 3.1 Create `src/views/sync/SyncSettings.vue` with:
  - List of available Nextcloud Mail accounts from IMailManager
  - Per-account sync toggle (enabled/disabled)
  - Entity type privacy controls (checkboxes per type: clients, contacts, leads, requests)
  - "Sync now" manual trigger button
  - Last sync timestamp display
- [x] 3.2 Create `src/components/EmailTimeline.vue` with:
  - Chronological list (reverse order) of emailLink objects for a given entity UUID
  - Each row: direction badge (inbound/outbound), sender, subject, date
  - Click handler to open email in Nextcloud Mail (using `syncSource` + `messageId`)
  - "Exclude from sync" action per email row
  - Empty state: "No emails synced yet"
  - Props: `entityType` (string), `entityId` (string UUID)

## 4. Routing and Navigation

- [x] 4.1 Add sync settings route to `src/router/index.js`: path `/settings/sync`, component `SyncSettings.vue`
- [x] 4.2 Add "Sync instellingen" navigation entry to `src/navigation/MainMenu.vue` under settings section

## 5. Entity Detail Integration

- [x] 5.1 Add `EmailTimeline` component to `ClientDetail.vue` with `entityType="client"` and the client's UUID
- [x] 5.2 Add `EmailTimeline` component to `ContactDetail.vue` with `entityType="contact"` and the contact's UUID
- [x] 5.3 Add `EmailTimeline` component to lead detail view (if exists) with `entityType="lead"`
- [x] 5.4 Add calendar event list and "Plan follow-up" button to entity detail views using calendarLink data
- [x] 5.5 Add `EntityTimeline` or calendar section to request detail view if applicable

## 6. Verification

- [x] 6.1 Run `npm run build` and verify no compilation errors
- [x] 6.2 Verify `emailLink` and `calendarLink` schemas are correctly registered in the OpenRegister instance
- [x] 6.3 Verify EmailSyncJob is registered and scheduled (check `appinfo/info.xml` background jobs section)
- [x] 6.4 Manually trigger a sync and verify emailLink objects are created in OpenRegister
- [x] 6.5 Verify SyncSettings.vue renders at `/settings/sync` without errors
- [x] 6.6 Verify EmailTimeline.vue renders correctly on a client or contact detail with linked emails

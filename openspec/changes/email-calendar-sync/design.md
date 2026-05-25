# Design: email-calendar-sync

## Overview

This change adds email and calendar integration to Pipelinq by implementing background sync services that index communications from Nextcloud Mail and Calendar into the existing `emailLink` and `calendarLink` OpenRegister schemas. No new schemas are introduced — both schemas are already defined in `lib/Settings/pipelinq_register.json` per ADR-000.

The integration is read-first: emails and calendar events are indexed and linked to existing CRM entities. Write operations (creating calendar events) are scoped to follow-up scheduling from entity context only.

---

## Architecture

### Data Layer

Both schemas are already defined in ADR-000. This change only adds seed data and wires the sync services to populate them.

#### emailLink (existing schema)

| Property | Type | Required | Notes |
|----------|------|----------|-------|
| messageId | string | Yes | Unique email ID from Nextcloud Mail — used for deduplication |
| subject | string | No | Email subject line |
| sender | string | No | Sender email address |
| recipients | array | No | Recipient email addresses |
| date | string | No | ISO 8601 date |
| threadId | string | No | Thread ID for grouping conversations |
| linkedEntityType | string | Yes | `client`, `contact`, `lead`, or `request` |
| linkedEntityId | string | Yes | UUID of the linked CRM entity |
| direction | string | No | `inbound` or `outbound` |
| syncSource | string | No | Nextcloud Mail account ID |
| excluded | boolean | No | Agent-marked as excluded from future sync |
| deleted | boolean | No | Source email deleted in mail client |

#### calendarLink (existing schema)

| Property | Type | Required | Notes |
|----------|------|----------|-------|
| eventUid | string | Yes | Calendar event UID — used for deduplication |
| title | string | No | Event title |
| startDate | string | No | ISO 8601 datetime |
| endDate | string | No | ISO 8601 datetime |
| attendees | array | No | Attendee email addresses |
| linkedEntityType | string | Yes | `client`, `contact`, `lead`, or `request` |
| linkedEntityId | string | Yes | UUID of the linked CRM entity |
| status | string | No | `scheduled`, `completed`, or `cancelled` |
| createdFrom | string | No | `pipelinq` (created from CRM) or `calendar` (synced in) |
| notes | string | No | Post-event notes or agenda |

---

## Backend

### EmailSyncService (`lib/Service/EmailSyncService.php`)

Core matching and indexing logic for email synchronisation.

**Dependencies:**
- `OCA\OpenRegister\Service\ObjectService` — Create/query `emailLink` objects
- `OCP\Mail\IMailManager` — Access Nextcloud Mail messages
- `OCP\IUserManager` — Enumerate users for per-user sync
- `OCP\IAppConfig` — Read per-user sync settings
- `OCA\Pipelinq\Service\SchemaMapService` — Resolve register/schema slugs

**Methods:**

| Method | Signature | Purpose |
|--------|-----------|---------|
| `syncUserEmails` | `syncUserEmails(string $userId): int` | Fetch new emails for user, create emailLink objects. Returns count created. |
| `matchEmailToEntities` | `matchEmailToEntities(string $address): array` | Return list of `[entityType, entityId]` pairs matching the address. Checks contact.email and client.email. |
| `matchDomainToOrganization` | `matchDomainToOrganization(string $domain): ?array` | Return `[entityType, entityId]` for an organization matching the email domain. Returns null if public domain. |
| `isPublicDomain` | `isPublicDomain(string $domain): bool` | Return true for gmail.com, outlook.com, hotmail.com, yahoo.com, and other public email providers. |
| `isDuplicate` | `isDuplicate(string $messageId): bool` | Check whether an emailLink with this messageId already exists. |

### CalendarSyncService (`lib/Service/CalendarSyncService.php`)

Calendar event indexing and follow-up event creation.

**Dependencies:**
- `OCA\OpenRegister\Service\ObjectService` — Create/query `calendarLink` objects
- `OCP\Calendar\ICalendarQuery` — Query Nextcloud Calendar events
- `OCP\Calendar\IManager` — Access calendar manager
- `OCP\IUserManager` — Enumerate users
- `OCP\IAppConfig` — Read per-user sync settings
- `OCA\Pipelinq\Service\SchemaMapService` — Resolve register/schema slugs

**Methods:**

| Method | Signature | Purpose |
|--------|-----------|---------|
| `syncUserCalendar` | `syncUserCalendar(string $userId): int` | Fetch recent calendar events for user, match to CRM entities, create calendarLink objects. Returns count created. |
| `matchAttendeesToEntities` | `matchAttendeesToEntities(array $attendeeEmails): array` | Return list of matching entity references from attendee email addresses. |
| `createFollowUpEvent` | `createFollowUpEvent(string $entityType, string $entityId, array $eventData, string $userId): array` | Create a calendar event in the user's primary calendar and return the created calendarLink object. |
| `isDuplicate` | `isDuplicate(string $eventUid): bool` | Check whether a calendarLink for this eventUid already exists. |

### EmailSyncJob (`lib/BackgroundJob/EmailSyncJob.php`)

Periodic background job that triggers email sync for all users who have sync enabled.

- **Type**: `OCP\BackgroundJob\TimedJob`
- **Interval**: 5 minutes (300 seconds)
- **Dependencies**: `EmailSyncService`, `CalendarSyncService`, `IUserManager`, `LoggerInterface`
- **Behaviour**: Iterates active users, calls `EmailSyncService::syncUserEmails()` and `CalendarSyncService::syncUserCalendar()` for each, logs counts and errors. Continues on per-user errors (does not abort full run).

### EmailSyncController (`lib/Controller/EmailSyncController.php`)

REST API for per-user sync configuration.

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| `GET` | `/api/sync/email/settings` | User | Return current sync settings for authenticated user |
| `POST` | `/api/sync/email/settings` | User | Save sync settings (account, enabled, excludedAddresses) |
| `POST` | `/api/sync/email/trigger` | User | Manually trigger a sync run for the current user |
| `GET` | `/api/sync/email/status` | User | Return last sync timestamp, counts, and any error messages |

**Dependencies**: `EmailSyncService`, `CalendarSyncService`, `IUserSession`, `IAppConfig`, `IL10N`

---

## Frontend

### EmailTimelineCard.vue (`src/components/sync/EmailTimelineCard.vue`)

Displays linked emails for an entity on detail views. Uses the `emailLink` object store.

- Shows chronological list of linked emails: direction icon, subject, sender/recipient, date
- "Open in Mail" button links to the message in Nextcloud Mail (`generateUrl('/apps/mail')`)
- "Exclude" button marks an emailLink as excluded (sets `excluded: true`)
- Empty state: "No emails linked yet. Sync runs every 5 minutes."
- Props: `entityType` (string), `entityId` (string)
- Used on: `ClientDetail.vue`, `ContactDetail.vue`, `LeadDetail.vue`, `RequestDetail.vue`

### CalendarEventCard.vue (`src/components/sync/CalendarEventCard.vue`)

Displays linked calendar events for an entity and allows scheduling new follow-ups.

- Shows upcoming and past events: title, start/end datetime, attendees, status badge
- "Schedule follow-up" button opens a dialog (`FollowUpEventDialog.vue`) pre-filled with entity context
- Status badge using `CnStatusBadge` with colours for scheduled/completed/cancelled
- Props: `entityType` (string), `entityId` (string)
- Used on: `LeadDetail.vue`, `RequestDetail.vue`, `ClientDetail.vue`

### FollowUpEventDialog.vue (`src/components/sync/FollowUpEventDialog.vue`)

Dialog for creating a follow-up calendar event from entity context.

- Fields: title, start date/time, end date/time, notes, attendees (pre-filled from linked contacts)
- On submit: calls `POST /api/sync/calendar/events` with entity reference
- Uses `CnFormDialog` pattern (not a custom dialog — WCAG compliant)

### SyncSettingsSection.vue (`src/components/sync/SyncSettingsSection.vue`)

Per-user sync configuration section inside `UserSettings.vue` (rendered inside `NcAppSettingsDialog`).

- Mail account selector (populated from Nextcloud Mail accounts list)
- Sync enabled toggle
- Excluded email addresses (freetext, comma-separated)
- Last sync status display (timestamp, count, errors)
- "Sync now" button calling `POST /api/sync/email/trigger`
- All strings via `this.t('pipelinq', 'key')` per ADR-007

---

## Reuse Analysis

Per ADR-012, the following OpenRegister and platform services are reused directly — no custom rebuilding:

| Capability | Reused From | Usage |
|------------|-------------|-------|
| Object CRUD | `ObjectService.saveObject()`, `findObjects()` | Create and query emailLink / calendarLink objects |
| Deduplication | `ObjectService.searchObjects()` with `messageId` filter | Prevent duplicate emailLink creation |
| Pinia store | `createObjectStore('email-link')`, `createObjectStore('calendar-link')` | Frontend state for both entity types |
| Audit trail | Automatic via OpenRegister | All emailLink/calendarLink changes are tracked |
| List views | `CnIndexPage` + `useListView` | If dedicated email/calendar list pages are added |
| Status badge | `CnStatusBadge` | Calendar event status display |
| Object sidebar | `CnObjectSidebar` | Not used directly — EmailTimelineCard is a detail card, not a sidebar tab |
| Background jobs | `OCP\BackgroundJob\TimedJob` | EmailSyncJob extends this directly |
| Notifications | `NotificationService` | Notify user of sync errors (beyond scope of this change — wired but not triggered) |

**No overlap found** with existing OpenRegister services for email or calendar sync — Pipelinq has no prior email/calendar integration code.

---

## Seed Data

Per the company-wide seed data requirement (ADR-001 company rules), the following seed objects are included in `lib/Settings/pipelinq_register.json` under `components.objects[]` with `x-openregister.type: "mock"`.

### emailLink Seed Objects

```json
[
  {
    "@self": {
      "register": "pipelinq",
      "schema": "email-link",
      "slug": "email-link-001"
    },
    "messageId": "<20260310.083412.abc123@mail.conduction.nl>",
    "subject": "Offerte traject gemeentelijk CRM systeem",
    "sender": "j.devries@gemeente-utrecht.nl",
    "recipients": ["verkoop@conduction.nl"],
    "date": "2026-03-10T08:34:12+01:00",
    "threadId": "thread-gem-crm-2026",
    "linkedEntityType": "lead",
    "linkedEntityId": "00000000-0000-0000-0000-000000000101",
    "direction": "inbound",
    "syncSource": "verkoop@conduction.nl",
    "excluded": false,
    "deleted": false
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "email-link",
      "slug": "email-link-002"
    },
    "messageId": "<20260310.141822.def456@mail.conduction.nl>",
    "subject": "RE: Offerte traject gemeentelijk CRM systeem",
    "sender": "verkoop@conduction.nl",
    "recipients": ["j.devries@gemeente-utrecht.nl"],
    "date": "2026-03-10T14:18:22+01:00",
    "threadId": "thread-gem-crm-2026",
    "linkedEntityType": "lead",
    "linkedEntityId": "00000000-0000-0000-0000-000000000101",
    "direction": "outbound",
    "syncSource": "verkoop@conduction.nl",
    "excluded": false,
    "deleted": false
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "email-link",
      "slug": "email-link-003"
    },
    "messageId": "<20260315.092045.ghi789@mail.conduction.nl>",
    "subject": "Klacht over verwerking aanvraag vergunning",
    "sender": "p.bakker@bakker-installaties.nl",
    "recipients": ["klantenservice@conduction.nl"],
    "date": "2026-03-15T09:20:45+01:00",
    "threadId": "thread-klacht-bak-001",
    "linkedEntityType": "client",
    "linkedEntityId": "00000000-0000-0000-0000-000000000201",
    "direction": "inbound",
    "syncSource": "klantenservice@conduction.nl",
    "excluded": false,
    "deleted": false
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "email-link",
      "slug": "email-link-004"
    },
    "messageId": "<20260318.160300.jkl012@mail.conduction.nl>",
    "subject": "Bevestiging demo afspraak 25 maart",
    "sender": "verkoop@conduction.nl",
    "recipients": ["m.vanderberg@stichtingwelzijn.nl"],
    "date": "2026-03-18T16:03:00+01:00",
    "threadId": "thread-demo-welzijn-03",
    "linkedEntityType": "contact",
    "linkedEntityId": "00000000-0000-0000-0000-000000000301",
    "direction": "outbound",
    "syncSource": "verkoop@conduction.nl",
    "excluded": false,
    "deleted": false
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "email-link",
      "slug": "email-link-005"
    },
    "messageId": "<20260320.103512.mno345@mail.conduction.nl>",
    "subject": "Vraag over implementatietijdlijn",
    "sender": "a.janssen@reisbureauklein.nl",
    "recipients": ["projecten@conduction.nl"],
    "date": "2026-03-20T10:35:12+01:00",
    "threadId": "thread-impl-reisbureau-01",
    "linkedEntityType": "request",
    "linkedEntityId": "00000000-0000-0000-0000-000000000401",
    "direction": "inbound",
    "syncSource": "projecten@conduction.nl",
    "excluded": false,
    "deleted": false
  }
]
```

### calendarLink Seed Objects

```json
[
  {
    "@self": {
      "register": "pipelinq",
      "schema": "calendar-link",
      "slug": "calendar-link-001"
    },
    "eventUid": "pipelinq-demo-2026-03-25T140000@conduction.nl",
    "title": "Demo Pipelinq CRM — Gemeente Utrecht",
    "startDate": "2026-03-25T14:00:00+01:00",
    "endDate": "2026-03-25T15:00:00+01:00",
    "attendees": ["j.devries@gemeente-utrecht.nl", "verkoop@conduction.nl"],
    "linkedEntityType": "lead",
    "linkedEntityId": "00000000-0000-0000-0000-000000000101",
    "status": "completed",
    "createdFrom": "pipelinq",
    "notes": "Demo verliep positief. Follow-up offerte inplannen."
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "calendar-link",
      "slug": "calendar-link-002"
    },
    "eventUid": "pipelinq-followup-2026-04-02T100000@conduction.nl",
    "title": "Vervolgafspraak implementatieplan — Stichting Welzijn Noord",
    "startDate": "2026-04-02T10:00:00+02:00",
    "endDate": "2026-04-02T11:30:00+02:00",
    "attendees": ["m.vanderberg@stichtingwelzijn.nl", "projecten@conduction.nl"],
    "linkedEntityType": "lead",
    "linkedEntityId": "00000000-0000-0000-0000-000000000102",
    "status": "scheduled",
    "createdFrom": "pipelinq",
    "notes": "Bespreek integratie met bestaand zaaksysteem."
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "calendar-link",
      "slug": "calendar-link-003"
    },
    "eventUid": "cal-intake-2026-03-28T090000@conduction.nl",
    "title": "Intake gesprek vergunning aanvraag — Bakker Installaties",
    "startDate": "2026-03-28T09:00:00+01:00",
    "endDate": "2026-03-28T09:30:00+01:00",
    "attendees": ["p.bakker@bakker-installaties.nl", "klantenservice@conduction.nl"],
    "linkedEntityType": "request",
    "linkedEntityId": "00000000-0000-0000-0000-000000000401",
    "status": "completed",
    "createdFrom": "calendar",
    "notes": "Klant heeft documentatie aangeleverd. Behandeltermijn 10 werkdagen."
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "calendar-link",
      "slug": "calendar-link-004"
    },
    "eventUid": "pipelinq-review-2026-04-10T150000@conduction.nl",
    "title": "Kwartaalreview — Reisbureau Klein",
    "startDate": "2026-04-10T15:00:00+02:00",
    "endDate": "2026-04-10T16:00:00+02:00",
    "attendees": ["a.janssen@reisbureauklein.nl", "accountmanager@conduction.nl"],
    "linkedEntityType": "client",
    "linkedEntityId": "00000000-0000-0000-0000-000000000202",
    "status": "scheduled",
    "createdFrom": "pipelinq",
    "notes": "Bespreken uitbreiding licentie en nieuwe modules."
  }
]
```

---

## File Changes

### New Files

| File | Purpose |
|------|---------|
| `lib/Service/EmailSyncService.php` | Email-to-entity matching and emailLink creation |
| `lib/Service/CalendarSyncService.php` | Calendar event indexing and calendarLink creation |
| `lib/BackgroundJob/EmailSyncJob.php` | ITimedJob: runs every 5 minutes, calls sync services |
| `lib/Controller/EmailSyncController.php` | REST API for sync settings and manual trigger |
| `appinfo/routes.php` (entries) | Add `/api/sync/email/*` routes |
| `src/components/sync/EmailTimelineCard.vue` | Email timeline component for entity detail views |
| `src/components/sync/CalendarEventCard.vue` | Calendar events component for entity detail views |
| `src/components/sync/FollowUpEventDialog.vue` | Dialog for creating follow-up calendar events |
| `src/components/sync/SyncSettingsSection.vue` | Per-user sync settings section in UserSettings modal |
| `tests/Unit/Service/EmailSyncServiceTest.php` | Unit tests for EmailSyncService |
| `tests/Unit/Service/CalendarSyncServiceTest.php` | Unit tests for CalendarSyncService |
| `tests/Unit/BackgroundJob/EmailSyncJobTest.php` | Unit tests for EmailSyncJob |
| `tests/Unit/Controller/EmailSyncControllerTest.php` | Unit tests for EmailSyncController |

### Modified Files

| File | Change |
|------|--------|
| `lib/Settings/pipelinq_register.json` | Add seed data objects for emailLink and calendarLink |
| `src/views/clients/ClientDetail.vue` | Add `EmailTimelineCard` and `CalendarEventCard` sections |
| `src/views/contacts/ContactDetail.vue` | Add `EmailTimelineCard` section |
| `src/views/leads/LeadDetail.vue` | Add `EmailTimelineCard` and `CalendarEventCard` sections |
| `src/views/requests/RequestDetail.vue` | Add `EmailTimelineCard` and `CalendarEventCard` sections |
| `src/views/UserSettings.vue` | Add `SyncSettingsSection` to user settings modal |
| `appinfo/info.xml` | Register `EmailSyncJob` in background-jobs section |
| `l10n/en.json` | Add translation keys for sync UI strings |
| `l10n/nl.json` | Add Dutch translations for sync UI strings |

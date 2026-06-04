# Design: email-calendar-sync

## Architecture

### Data Model (OpenRegister Schemas)

Two new schemas are added to `pipelinq_register.json`. Entity definitions match ADR-000 exactly.

#### emailLink

Stores metadata for emails synced from Nextcloud Mail and linked to Pipelinq entities. Full email body is accessed on-demand from Nextcloud Mail.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| messageId | string | Yes | Email message ID from Nextcloud Mail |
| subject | string | No | Email subject line |
| sender | string | No | Sender email address |
| recipients | array | No | Recipient email addresses |
| date | string (date-time) | No | Email date |
| threadId | string | No | Email thread ID for conversation grouping |
| linkedEntityType | string | Yes | Type of linked CRM entity (client/contact/lead/request) |
| linkedEntityId | string (uuid) | Yes | UUID of the linked CRM entity |
| direction | string | No | Email direction (inbound/outbound) |
| syncSource | string | No | Nextcloud Mail account ID |
| excluded | boolean | No | Whether this email is excluded from future sync (default: false) |
| deleted | boolean | No | Whether the source email has been deleted (default: false) |

#### calendarLink

Stores metadata for calendar events synced with Nextcloud Calendar and linked to Pipelinq entities.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| eventUid | string | Yes | Calendar event UID |
| title | string | No | Event title |
| startDate | string (date-time) | No | Event start date and time |
| endDate | string (date-time) | No | Event end date and time |
| attendees | array | No | Attendee email addresses |
| linkedEntityType | string | Yes | Type of linked CRM entity (client/contact/lead/request) |
| linkedEntityId | string (uuid) | Yes | UUID of the linked CRM entity |
| status | string | No | Event status (scheduled/completed/cancelled) |
| createdFrom | string | No | Where the event was created (pipelinq/calendar) |
| notes | string | No | Post-event notes |

### Backend

#### EmailSyncService (`lib/Service/EmailSyncService.php`)

Core matching logic for linking emails to CRM entities.

| Method | Signature | Description |
|--------|-----------|-------------|
| matchEmailToEntities | `(string $senderEmail, array $recipientEmails): array` | Find matching clients, contacts, leads, requests by email address |
| matchDomainToOrganization | `(string $domain): ?array` | Find a client organization matching the email domain |
| isPublicDomain | `(string $domain): bool` | Return true for gmail.com, outlook.com, hotmail.com, etc. |

#### EmailSyncJob (`lib/BackgroundJob/EmailSyncJob.php`)

`ITimedJob` running every 5 minutes. For each user with sync enabled:
1. Retrieve new messages from selected mail accounts via Nextcloud Mail API
2. For each message, call `EmailSyncService::matchEmailToEntities()`
3. Create `emailLink` objects for each matched entity
4. Skip messages where `messageId` already exists as an emailLink

#### CalendarSyncService (`lib/Service/CalendarSyncService.php`)

| Method | Signature | Description |
|--------|-----------|-------------|
| createFollowUpEvent | `(string $entityType, string $entityId, array $eventData): array` | Create a Nextcloud Calendar event and store a calendarLink record |
| matchEventToEntities | `(array $attendeeEmails): array` | Find matching CRM entities by attendee email addresses |

### Frontend

#### SyncSettings.vue (`src/views/sync/SyncSettings.vue`)

Per-user sync configuration UI. Displays:
- List of available Nextcloud Mail accounts (via IMailManager)
- Toggle per account to enable/disable sync
- Privacy controls: which entity types to sync (clients, contacts, leads, requests)
- "Sync now" manual trigger button
- Last sync timestamp per account

#### EmailTimeline.vue (`src/components/EmailTimeline.vue`)

Displays a chronological list of linked emails for an entity. Shown on client/contact/lead/request detail views. Each email shows sender, subject, date, direction badge. Clicking an email opens it in Nextcloud Mail.

## Files Changed

### New Files
- `lib/Service/EmailSyncService.php` — Email-to-entity matching service
- `lib/Service/CalendarSyncService.php` — Calendar event creation and matching
- `lib/BackgroundJob/EmailSyncJob.php` — ITimedJob for periodic email sync
- `src/views/sync/SyncSettings.vue` — Per-user sync configuration view
- `src/components/EmailTimeline.vue` — Email timeline component for entity detail views

### Modified Files
- `lib/Settings/pipelinq_register.json` — Add emailLink and calendarLink schemas, update schemas list
- `src/router/index.js` — Add sync settings route (`/settings/sync`)

## Seed Data

### emailLink examples (Dutch values)

```json
[
  {
    "messageId": "<20260315.084521.12345@mail.gemeente-amsterdam.nl>",
    "subject": "Aanvraag subsidie buurtbeheer 2026",
    "sender": "j.vandermeer@gemeente-amsterdam.nl",
    "recipients": ["info@pipelinq.example.nl"],
    "date": "2026-03-15T08:45:21Z",
    "threadId": "thread-001-subsidie",
    "linkedEntityType": "client",
    "linkedEntityId": "a1b2c3d4-0001-0001-0001-000000000001",
    "direction": "inbound",
    "syncSource": "account-info-pipelinq",
    "excluded": false,
    "deleted": false
  },
  {
    "messageId": "<20260316.143012.67890@mail.acme.nl>",
    "subject": "RE: Offerte CRM implementatie",
    "sender": "p.bakker@acme.nl",
    "recipients": ["verkoop@pipelinq.example.nl"],
    "date": "2026-03-16T14:30:12Z",
    "threadId": "thread-002-offerte",
    "linkedEntityType": "lead",
    "linkedEntityId": "b2c3d4e5-0002-0002-0002-000000000002",
    "direction": "inbound",
    "syncSource": "account-verkoop-pipelinq",
    "excluded": false,
    "deleted": false
  },
  {
    "messageId": "<20260317.091500.11111@mail.pipelinq.example.nl>",
    "subject": "Bevestiging afspraak demo 18 maart",
    "sender": "verkoop@pipelinq.example.nl",
    "recipients": ["k.smit@bv-logistics.nl", "m.hendriks@bv-logistics.nl"],
    "date": "2026-03-17T09:15:00Z",
    "threadId": "thread-003-demo",
    "linkedEntityType": "contact",
    "linkedEntityId": "c3d4e5f6-0003-0003-0003-000000000003",
    "direction": "outbound",
    "syncSource": "account-verkoop-pipelinq",
    "excluded": false,
    "deleted": false
  },
  {
    "messageId": "<20260318.162233.22222@mail.rdw.nl>",
    "subject": "Vraag over vergunning aanvraag status",
    "sender": "info@rdw.nl",
    "recipients": ["service@pipelinq.example.nl"],
    "date": "2026-03-18T16:22:33Z",
    "threadId": "thread-004-vergunning",
    "linkedEntityType": "request",
    "linkedEntityId": "d4e5f6a7-0004-0004-0004-000000000004",
    "direction": "inbound",
    "syncSource": "account-service-pipelinq",
    "excluded": false,
    "deleted": false
  }
]
```

### calendarLink examples (Dutch values)

```json
[
  {
    "eventUid": "pipelinq-event-001@nextcloud.gemeente-amsterdam",
    "title": "Intake gesprek subsidie aanvraag — Gemeente Amsterdam",
    "startDate": "2026-03-20T10:00:00Z",
    "endDate": "2026-03-20T11:00:00Z",
    "attendees": ["j.vandermeer@gemeente-amsterdam.nl", "verkoop@pipelinq.example.nl"],
    "linkedEntityType": "client",
    "linkedEntityId": "a1b2c3d4-0001-0001-0001-000000000001",
    "status": "completed",
    "createdFrom": "pipelinq",
    "notes": "Klant is geïnteresseerd in de zakelijke module. Vervolgafspraak ingepland voor april."
  },
  {
    "eventUid": "pipelinq-event-002@nextcloud.acme",
    "title": "Follow-up offerte CRM — Acme B.V.",
    "startDate": "2026-03-25T14:00:00Z",
    "endDate": "2026-03-25T14:30:00Z",
    "attendees": ["p.bakker@acme.nl", "verkoop@pipelinq.example.nl"],
    "linkedEntityType": "lead",
    "linkedEntityId": "b2c3d4e5-0002-0002-0002-000000000002",
    "status": "scheduled",
    "createdFrom": "pipelinq",
    "notes": ""
  },
  {
    "eventUid": "pipelinq-event-003@nextcloud.bv-logistics",
    "title": "Demo Pipelinq CRM — BV Logistics",
    "startDate": "2026-03-18T13:00:00Z",
    "endDate": "2026-03-18T14:00:00Z",
    "attendees": ["k.smit@bv-logistics.nl", "m.hendriks@bv-logistics.nl", "verkoop@pipelinq.example.nl"],
    "linkedEntityType": "contact",
    "linkedEntityId": "c3d4e5f6-0003-0003-0003-000000000003",
    "status": "completed",
    "createdFrom": "calendar",
    "notes": "Demo goed verlopen. Contact wil kostenvoorstel ontvangen voor 5 gebruikers."
  },
  {
    "eventUid": "pipelinq-event-004@nextcloud.rdw",
    "title": "Terugbelafspraak vergunning aanvraag — RDW",
    "startDate": "2026-03-19T09:30:00Z",
    "endDate": "2026-03-19T10:00:00Z",
    "attendees": ["info@rdw.nl", "service@pipelinq.example.nl"],
    "linkedEntityType": "request",
    "linkedEntityId": "d4e5f6a7-0004-0004-0004-000000000004",
    "status": "cancelled",
    "createdFrom": "pipelinq",
    "notes": "Afspraak geannuleerd door klant. Nieuw moment te plannen."
  }
]
```

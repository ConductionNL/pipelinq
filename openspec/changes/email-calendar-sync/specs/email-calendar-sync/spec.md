# Email Calendar Sync — Delta Spec

## Purpose

Add email and calendar synchronisation to Pipelinq by indexing `emailLink` and `calendarLink` objects from Nextcloud Mail and Calendar, matching communications to CRM entities, and exposing linked communications on entity detail views. Enables the automation engine to react to email and calendar events.

**Standards**: Schema.org (`schema:CommunicateAction`, `schema:Event`), iCalendar (RFC 5545), vCard (RFC 6350 email field)
**Feature tier**: V1 (email sync, calendar sync, timeline UI), V2 (automation triggers)
**Entities used**: `emailLink`, `calendarLink` (both defined in ADR-000 — no new schemas)
**OCP interfaces**: `OCP\Mail\IMailManager`, `OCP\Calendar\ICalendarQuery`, `OCP\Calendar\IManager`

---

## Requirements

---

### REQ-ECS-001: EmailLink Seed Data

The system MUST include realistic seed `emailLink` objects in `lib/Settings/pipelinq_register.json` so that the feature can be tested and demonstrated on a fresh install.

**Feature tier**: V1

#### Scenario: Seed data loaded on install

- **GIVEN** a fresh Pipelinq installation
- **WHEN** the repair step imports `pipelinq_register.json`
- **THEN** at least 3 `emailLink` objects MUST be available in the register
- AND re-importing MUST NOT create duplicates (matched by slug)

---

### REQ-ECS-002: CalendarLink Seed Data

The system MUST include realistic seed `calendarLink` objects in `lib/Settings/pipelinq_register.json`.

**Feature tier**: V1

#### Scenario: Calendar seed data loaded on install

- **GIVEN** a fresh Pipelinq installation
- **WHEN** the repair step imports `pipelinq_register.json`
- **THEN** at least 3 `calendarLink` objects MUST be available in the register
- AND re-importing MUST NOT create duplicates (matched by slug)

---

### REQ-ECS-003: Email Sync Background Job

The system MUST run an `ITimedJob` background job every 5 minutes that fetches new emails from Nextcloud Mail and creates `emailLink` objects for matched CRM entities.

**Feature tier**: V1

#### Scenario: New inbound email from known contact

- **GIVEN** a contact exists with email address `contact@example.nl`
- **AND** sync is enabled for the Pipelinq mail account
- **WHEN** a new email arrives from `contact@example.nl`
- **THEN** an `emailLink` object MUST be created within 5 minutes
- AND `linkedEntityType` MUST be `contact`
- AND `linkedEntityId` MUST be the UUID of the matching contact
- AND `direction` MUST be `inbound`

#### Scenario: Duplicate email is not re-indexed

- **GIVEN** an `emailLink` with `messageId` X already exists
- **WHEN** the sync job runs again
- **THEN** no new `emailLink` is created for message X
- AND the existing record is not modified

#### Scenario: Sync disabled for account

- **GIVEN** the user has disabled sync for a mail account
- **WHEN** the EmailSyncJob runs
- **THEN** no `emailLink` objects are created from that account's emails

---

### REQ-ECS-004: Email-to-Contact Matching

The `EmailSyncService` MUST match email sender/recipient addresses to `contact.email` and `client.email` fields in OpenRegister.

**Feature tier**: V1

#### Scenario: Match by exact email address

- **GIVEN** a contact exists with `email: "j.devries@gemeente-utrecht.nl"`
- **WHEN** an email is processed with sender `j.devries@gemeente-utrecht.nl`
- **THEN** `matchEmailToEntities("j.devries@gemeente-utrecht.nl")` MUST return that contact's entity reference

#### Scenario: No match returns empty

- **GIVEN** no contact or client has email `unknown@example.nl`
- **WHEN** `matchEmailToEntities("unknown@example.nl")` is called
- **THEN** it MUST return an empty array

---

### REQ-ECS-005: Domain-to-Organization Matching

When no exact email match is found, the service MUST attempt to match the sender's domain against client organizations — unless the domain is a public email provider.

**Feature tier**: V1

#### Scenario: Corporate domain matched to organization

- **GIVEN** a client of type `organization` with email `info@bakker-installaties.nl` exists
- **AND** no individual contact has the address `p.bakker@bakker-installaties.nl`
- **WHEN** an email from `p.bakker@bakker-installaties.nl` is processed
- **THEN** `matchDomainToOrganization("bakker-installaties.nl")` MUST return the organization's entity reference

#### Scenario: Public domain is not matched

- **GIVEN** no contact has address `iemand@gmail.com`
- **WHEN** `isPublicDomain("gmail.com")` is called
- **THEN** it MUST return `true`
- AND domain matching MUST NOT be attempted for `gmail.com`

---

### REQ-ECS-006: Email Timeline on Entity Detail Views

The system MUST display a chronological list of linked `emailLink` objects on client, contact, lead, and request detail views.

**Feature tier**: V1

#### Scenario: Emails visible on client detail

- **GIVEN** one or more `emailLink` objects are linked to a client
- **WHEN** an agent opens the client detail page
- **THEN** an email timeline MUST be displayed showing: direction icon, subject, sender or recipient, and date
- AND each entry MUST include a link that opens the email in Nextcloud Mail

#### Scenario: Empty state when no emails linked

- **GIVEN** no `emailLink` objects are linked to an entity
- **WHEN** the agent views that entity's detail page
- **THEN** the `EmailTimelineCard` MUST display an empty state message
- AND the empty state MUST NOT show an error

---

### REQ-ECS-007: Email Exclude Action

An agent MUST be able to exclude a specific email from future sync visibility by marking it `excluded: true` on the `emailLink` object.

**Feature tier**: V1

#### Scenario: Excluded email is hidden

- **GIVEN** an `emailLink` with `excluded: true`
- **WHEN** the email timeline is rendered for the linked entity
- **THEN** the excluded email MUST NOT appear in the visible timeline

#### Scenario: Exclude action saves to register

- **GIVEN** an email is visible in the timeline
- **WHEN** the agent clicks "Exclude"
- **THEN** the `emailLink.excluded` field MUST be set to `true` via `ObjectService.saveObject()`
- AND the email MUST disappear from the timeline without a page reload

---

### REQ-ECS-008: Calendar Sync Background Job

The `EmailSyncJob` MUST also invoke `CalendarSyncService::syncUserCalendar()` to index new calendar events and create `calendarLink` objects.

**Feature tier**: V1

#### Scenario: Calendar event matched to CRM entity

- **GIVEN** a calendar event exists with attendee `m.vanderberg@stichtingwelzijn.nl`
- **AND** a contact exists with that email address
- **WHEN** the sync job processes that event
- **THEN** a `calendarLink` object MUST be created with `linkedEntityType: contact` and `createdFrom: calendar`

#### Scenario: Duplicate calendar event not re-indexed

- **GIVEN** a `calendarLink` with `eventUid` Y already exists
- **WHEN** the sync job runs again
- **THEN** no new `calendarLink` is created for event Y

---

### REQ-ECS-009: Calendar Events on Entity Detail Views

The system MUST display linked `calendarLink` objects on lead, request, and client detail views.

**Feature tier**: V1

#### Scenario: Calendar events visible on lead detail

- **GIVEN** one or more `calendarLink` objects are linked to a lead
- **WHEN** an agent opens the lead detail page
- **THEN** a calendar events section MUST display each event with: title, start/end datetime, attendees, and status badge

#### Scenario: Status badge uses correct colour

- **GIVEN** a `calendarLink` with `status: scheduled`
- **WHEN** rendered in `CalendarEventCard`
- **THEN** the `CnStatusBadge` MUST use the colour associated with `scheduled`

---

### REQ-ECS-010: Follow-up Calendar Event Creation

An agent MUST be able to create a follow-up calendar event from entity context. The event is created in the user's primary Nextcloud Calendar and a `calendarLink` object is saved in OpenRegister.

**Feature tier**: V1

#### Scenario: Follow-up event created from lead

- **GIVEN** a lead detail page is open with a linked contact
- **WHEN** the agent clicks "Schedule follow-up" and fills the event form
- **THEN** `CalendarSyncService::createFollowUpEvent()` MUST create the event via `ICalendarManager`
- AND a `calendarLink` object MUST be saved with `createdFrom: pipelinq` and `linkedEntityType: lead`
- AND the new event MUST appear in the calendar events section without a page reload

#### Scenario: Follow-up dialog pre-fills attendees

- **GIVEN** a lead is linked to a contact with email `j.devries@gemeente-utrecht.nl`
- **WHEN** the "Schedule follow-up" dialog opens
- **THEN** the attendees field MUST be pre-filled with `j.devries@gemeente-utrecht.nl`

---

### REQ-ECS-011: Per-User Sync Settings

Each Nextcloud user MUST be able to configure their own sync preferences independently via the in-app settings modal.

**Feature tier**: V1

#### Scenario: Settings saved per user

- **GIVEN** two users have different mail account configurations
- **WHEN** each saves their sync settings via `POST /api/sync/email/settings`
- **THEN** each user's settings MUST be stored independently in `IAppConfig`
- AND changing one user's settings MUST NOT affect the other

#### Scenario: Settings surfaced in user settings modal

- **GIVEN** a user opens the in-app settings modal (gear menu)
- **WHEN** the SyncSettingsSection is rendered
- **THEN** it MUST show: mail account selector, sync enabled toggle, excluded addresses field, last sync status, and a "Sync now" button

---

### REQ-ECS-012: Sync Status Display

The sync settings UI MUST display the last sync timestamp, count of indexed items, and any error messages from the last run.

**Feature tier**: V1

#### Scenario: Status shows last sync time

- **GIVEN** a sync run completed successfully at 14:30
- **WHEN** the user views sync settings
- **THEN** the status MUST show "Last synced: [date] at 14:30" (formatted per user locale)

#### Scenario: Status shows error when sync failed

- **GIVEN** the last sync run produced an error (e.g., mail account unreachable)
- **WHEN** the user views sync settings
- **THEN** the status MUST show an error indicator with the error message
- AND the error message MUST NOT expose internal paths, SQL, or stack traces (per ADR-005)

---

### REQ-ECS-013: Automation Trigger Types

The `automation` entity MUST accept `email.received` and `calendar.event.start` as valid `trigger` values.

**Feature tier**: V2

#### Scenario: email.received automation triggers on new emailLink

- **GIVEN** an `automation` with `trigger: email.received` and `isActive: true` exists
- **AND** a new inbound `emailLink` is created for a client
- **WHEN** the EmailSyncService creates the emailLink
- **THEN** the automation engine MUST evaluate trigger conditions against the linked entity
- AND execute configured actions if conditions match

#### Scenario: calendar.event.start automation triggers on event start

- **GIVEN** an `automation` with `trigger: calendar.event.start` exists
- **AND** a `calendarLink` with `status: scheduled` has a `startDate` in the next sync window
- **WHEN** the CalendarSyncService processes the event
- **THEN** the automation engine MUST be notified and evaluate the trigger

---

### REQ-ECS-014: Unit Tests

Every new PHP service and controller MUST have PHPUnit tests with at least 3 test methods per class.

**Feature tier**: V1

#### Scenario: Service tests cover matching logic

- **GIVEN** `EmailSyncServiceTest.php` exists
- **WHEN** `composer test` runs
- **THEN** tests for `matchEmailToEntities`, `isPublicDomain`, and `isDuplicate` MUST pass

#### Scenario: Integration error paths tested

- **GIVEN** `EmailSyncControllerTest.php` exists
- **THEN** tests MUST cover: 200 success, 401 unauthenticated, and 400 invalid input for each endpoint

---

### REQ-ECS-015: Translation Coverage

All user-visible strings in sync components MUST have `en.json` and `nl.json` entries.

**Feature tier**: V1

#### Scenario: No hardcoded strings in sync components

- **GIVEN** `EmailTimelineCard.vue`, `CalendarEventCard.vue`, `SyncSettingsSection.vue`, and `FollowUpEventDialog.vue` are rendered
- **WHEN** the app language is set to Dutch (nl)
- **THEN** all labels, buttons, empty states, and error messages MUST display in Dutch
- AND no raw English string MUST appear in Dutch locale

# Spec: email-calendar-sync

## Purpose

Define requirements for integrating Nextcloud Mail and Calendar with Pipelinq CRM. Emails are automatically linked to CRM entities (clients, contacts, leads, requests). Calendar events are created from entity context and matched back to entities via attendee email addresses.

---

## Requirements

### REQ-ECS-001: Email Sync Background Job

The system MUST run a background job every 5 minutes that syncs new emails from enabled Nextcloud Mail accounts and creates emailLink records for matched CRM entities.

#### Scenario: Job runs on schedule

- GIVEN a user has enabled sync for their mail account "verkoop@pipelinq.example.nl"
- WHEN the EmailSyncJob executes
- THEN it MUST fetch all messages received since the last sync timestamp
- AND it MUST attempt entity matching for each new message
- AND it MUST update the last sync timestamp after successful execution

#### Scenario: Duplicate emails are not re-synced

- GIVEN an emailLink already exists with `messageId` = `<20260315.084521.12345@mail.gemeente-amsterdam.nl>`
- WHEN the EmailSyncJob processes the same message again
- THEN a duplicate emailLink MUST NOT be created
- AND the existing emailLink MUST remain unchanged

#### Scenario: Job skips users with sync disabled

- GIVEN a user has not enabled any mail account for sync
- WHEN the EmailSyncJob executes
- THEN no emails MUST be processed for that user
- AND no emailLink objects MUST be created

---

### REQ-ECS-002: Email-to-Entity Matching by Address

The system MUST match inbound and outbound emails to CRM entities by exact email address comparison against client, contact, lead, and request email fields.

#### Scenario: Match inbound email to contact

- GIVEN a contact "Jan van der Meer" with email "j.vandermeer@gemeente-amsterdam.nl"
- WHEN an inbound email is received from "j.vandermeer@gemeente-amsterdam.nl"
- THEN an emailLink MUST be created with:
  - `linkedEntityType` = "contact"
  - `linkedEntityId` = Jan van der Meer's UUID
  - `direction` = "inbound"

#### Scenario: Match outbound email to client

- GIVEN a client "Gemeente Amsterdam" with email "info@gemeente-amsterdam.nl"
- WHEN an outbound email is sent to "info@gemeente-amsterdam.nl"
- THEN an emailLink MUST be created with:
  - `linkedEntityType` = "client"
  - `linkedEntityId` = Gemeente Amsterdam's UUID
  - `direction` = "outbound"

#### Scenario: Email with no matching entity is skipped

- GIVEN no client, contact, lead, or request exists with email "onbekend@extern.nl"
- WHEN an email is processed from or to "onbekend@extern.nl"
- THEN no emailLink MUST be created
- AND the email MUST be silently skipped (no error)

#### Scenario: Email matches multiple entities

- GIVEN contact "Petra Bakker" and lead "CRM Implementatie" both reference "p.bakker@acme.nl"
- WHEN an email from "p.bakker@acme.nl" is processed
- THEN separate emailLink records MUST be created for both the contact and the lead

---

### REQ-ECS-003: Domain-to-Organization Matching

The system MUST match emails to client organizations by email domain when no exact address match exists, unless the domain is a known public provider.

#### Scenario: Domain matches organization

- GIVEN a client organization "Acme B.V." with email "info@acme.nl" (domain: acme.nl)
- AND no exact address match exists for "p.bakker@acme.nl"
- WHEN an email from "p.bakker@acme.nl" is processed
- THEN an emailLink MUST be created linking to "Acme B.V."

#### Scenario: Public domains are not matched to organizations

- GIVEN a client with email "contact@gmail.com"
- WHEN an email from "someone@gmail.com" is processed
- THEN domain matching MUST NOT create an emailLink
- AND public domains MUST include at minimum: gmail.com, outlook.com, hotmail.com, yahoo.com, icloud.com

#### Scenario: Domain with no matching organization is skipped

- GIVEN no client organization with domain "unknown-company.nl"
- WHEN an email from "iemand@unknown-company.nl" is processed
- THEN no emailLink is created for domain matching

---

### REQ-ECS-004: EmailLink Schema Storage

The system MUST store emailLink objects in OpenRegister with all required metadata fields populated correctly.

#### Scenario: EmailLink is stored with correct fields

- GIVEN an inbound email from "j.vandermeer@gemeente-amsterdam.nl" with subject "Aanvraag subsidie"
- WHEN the email is matched and an emailLink is created
- THEN the emailLink MUST contain:
  - `messageId` set to the Nextcloud Mail message ID
  - `subject` set to the email subject
  - `sender` set to "j.vandermeer@gemeente-amsterdam.nl"
  - `direction` set to "inbound"
  - `date` set to the email's received timestamp
  - `linkedEntityType` and `linkedEntityId` set to the matched entity

#### Scenario: Email marked as excluded is not re-synced

- GIVEN an emailLink with `excluded` = true
- WHEN the EmailSyncJob runs
- THEN that email MUST NOT be re-processed or replaced
- AND the excluded flag MUST persist across sync cycles

---

### REQ-ECS-005: Calendar Event Creation from Entity Context

The system MUST allow users to create a Nextcloud Calendar event from any CRM entity detail view, storing a calendarLink record that links the event to the entity.

#### Scenario: Create follow-up event from lead

- GIVEN a lead "CRM Implementatie — Acme B.V." is open
- WHEN the user clicks "Plan follow-up" and fills in title, date, time, and attendees
- THEN a Nextcloud Calendar event MUST be created in the user's calendar
- AND a calendarLink MUST be created with:
  - `eventUid` = the new calendar event's UID
  - `linkedEntityType` = "lead"
  - `linkedEntityId` = the lead's UUID
  - `status` = "scheduled"
  - `createdFrom` = "pipelinq"

#### Scenario: Attendees are pre-populated from entity

- GIVEN a contact "Petra Bakker" with email "p.bakker@acme.nl"
- WHEN the user creates a calendar event from the contact detail
- THEN "p.bakker@acme.nl" MUST be pre-filled as an attendee

---

### REQ-ECS-006: CalendarLink Schema Storage

The system MUST store calendarLink objects in OpenRegister with all required metadata fields.

#### Scenario: CalendarLink stores correct status values

- GIVEN a calendar event linked to a lead
- WHEN the event date is in the future
- THEN the calendarLink `status` MUST be "scheduled"
- WHEN the event date has passed
- THEN the user MUST be able to update `status` to "completed" or "cancelled"
- AND optionally add post-event `notes`

---

### REQ-ECS-007: Calendar Event-to-Entity Matching

The system MUST match existing Nextcloud Calendar events to CRM entities by attendee email addresses, using the same matching rules as email-to-entity matching.

#### Scenario: Calendar event matched to client by attendee

- GIVEN a Nextcloud Calendar event with attendee "j.vandermeer@gemeente-amsterdam.nl"
- AND a client "Gemeente Amsterdam" with email "j.vandermeer@gemeente-amsterdam.nl"
- WHEN CalendarSyncService processes the event
- THEN a calendarLink MUST be created with `linkedEntityType` = "client" and `createdFrom` = "calendar"

#### Scenario: Event with no matching attendee is skipped

- GIVEN a calendar event with attendees that match no CRM entities
- WHEN CalendarSyncService processes the event
- THEN no calendarLink is created

---

### REQ-ECS-008: Per-User Sync Configuration

The system MUST provide a per-user settings UI for configuring which Nextcloud Mail accounts are synced and which entity types are included.

#### Scenario: User enables sync for a mail account

- GIVEN a user has two Nextcloud Mail accounts: "info@pipelinq.example.nl" and "verkoop@pipelinq.example.nl"
- WHEN the user navigates to Settings > Sync
- THEN both accounts MUST be listed
- AND the user MUST be able to toggle sync on or off per account independently

#### Scenario: User restricts sync to specific entity types

- GIVEN a user has sync enabled for "verkoop@pipelinq.example.nl"
- WHEN the user disables sync for "requests" in entity type privacy controls
- THEN emails MUST NOT be linked to request entities for that user's sync
- AND matching for clients, contacts, and leads MUST still proceed

#### Scenario: Sync settings persist across sessions

- GIVEN a user has configured sync settings
- WHEN the user logs out and back in
- THEN the sync settings MUST be restored unchanged

---

### REQ-ECS-009: Email Exclusion

The system MUST allow users to manually exclude individual emails from sync, preventing the emailLink from being regenerated.

#### Scenario: User excludes an email

- GIVEN an emailLink is shown in the email timeline for a contact
- WHEN the user clicks "Exclude from sync"
- THEN the emailLink `excluded` flag MUST be set to true
- AND the email MUST no longer appear in the contact's email timeline
- AND the EmailSyncJob MUST NOT recreate an emailLink for that `messageId`

---

### REQ-ECS-010: Sync Status Display

The system MUST display sync status and email/calendar link counts on entity detail views.

#### Scenario: Entity with linked emails shows timeline

- GIVEN a client "Gemeente Amsterdam" has 3 linked emailLinks
- WHEN the user views the Gemeente Amsterdam client detail
- THEN an email timeline section MUST display all 3 emails in reverse chronological order
- AND each email MUST show: sender, subject, date, direction badge (inbound/outbound)
- AND clicking an email MUST open it in Nextcloud Mail

#### Scenario: Entity with no linked emails shows empty state

- GIVEN a client has no emailLinks
- WHEN the user views the client detail
- THEN an empty state MUST be shown in the email timeline section: "No emails synced yet"

#### Scenario: Entity with linked calendar events shows event list

- GIVEN a lead has 2 calendarLinks
- WHEN the user views the lead detail
- THEN both calendar events MUST be listed with title, date, and status badge
- AND a "Plan follow-up" button MUST be visible

---

### REQ-ECS-011: Graceful Handling of Missing Mail App

The system MUST handle environments where the Nextcloud Mail app is not installed or IMailManager is unavailable.

#### Scenario: Mail app not available — sync skips silently

- GIVEN the Nextcloud Mail app is not installed
- WHEN the EmailSyncJob runs
- THEN it MUST log a debug-level message and skip sync
- AND no errors MUST be thrown
- AND all other Pipelinq functionality MUST continue normally

#### Scenario: Sync settings UI gracefully degrades

- GIVEN the Nextcloud Mail app is not installed
- WHEN the user navigates to Settings > Sync
- THEN a notice MUST display: "Nextcloud Mail is not installed. Email sync is unavailable."
- AND no mail account toggles MUST be shown

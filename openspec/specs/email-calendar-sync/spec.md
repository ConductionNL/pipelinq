# email-calendar-sync Specification

## Purpose

Enable bidirectional email and calendar synchronization in Pipelinq. Emails are automatically linked to contacts and pipeline items by matching sender/recipient addresses. Calendar events for follow-ups and meetings are synced with Nextcloud Calendar. This ensures that all communication context is captured in the CRM without manual data entry, leveraging Nextcloud's existing Mail and Calendar apps.

Email/calendar sync is a standard CRM capability, with modern platforms offering Gmail and Outlook sync with automatic contact matching and domain-based company linking, IMAP/SMTP integration with email-to-entity linking, and calendar sync with auto-contact creation from meeting participants. Nextcloud's built-in Mail and Calendar apps provide a natural integration path that standalone backends cannot match.

**Standards**: CalDAV (RFC 4791), iCalendar (RFC 5545), IMAP (RFC 3501), vCard (RFC 6350)
**Feature tier**: V1 (core sync), Enterprise (advanced analytics)
**Tender frequency**: Implicitly required by 32/69 tenders requiring communication and notification capabilities

## Data Model

Email and calendar sync metadata is stored as OpenRegister objects in the `pipelinq` register:
- **EmailLink**: email message ID, subject, sender, recipients (array), date, thread ID, linked entity type (client/contact/lead/request), linked entity UUID, direction (inbound/outbound), sync source (mail account ID), excluded (boolean)
- **CalendarLink**: calendar event UID, title, start datetime, end datetime, attendees (array), linked entity type, linked entity UUID, status (scheduled/completed/cancelled), created from (pipelinq/calendar)
- **SyncConfig**: per-user configuration stored via `IConfig` (user preferences) -- enabled accounts, sync scope, visibility settings

## Requirements

---

### Requirement: Emails MUST be automatically linked to CRM contacts

Inbound and outbound emails MUST be matched to Pipelinq contacts by email address.

**Feature tier**: V1

#### Scenario: Incoming email matched to contact

- GIVEN contact `Jan de Vries` has email `jan@gemeente-utrecht.nl` in Pipelinq
- AND the Nextcloud Mail app receives an email from `jan@gemeente-utrecht.nl`
- WHEN the email sync background job runs (every 5 minutes via `ITimedJob`)
- THEN the system MUST create an `EmailLink` object linking the email to Jan's contact record
- AND the email MUST appear in Jan's klantbeeld interaction timeline showing: subject, sender, date, and a preview of the body (first 200 characters)

#### Scenario: Outgoing email matched to contact

- GIVEN a user sends an email to `jan@gemeente-utrecht.nl` via Nextcloud Mail
- WHEN the email sync background job runs
- THEN the system MUST create an `EmailLink` object with direction "outbound" linked to Jan's contact record
- AND the email MUST appear in Jan's timeline as an outgoing interaction with a distinct icon (arrow-out)

#### Scenario: Email matched to organization by domain

- GIVEN organization `Gemeente Utrecht` has domain `gemeente-utrecht.nl` configured in a `domains` property on the client schema
- AND an email arrives from `info@gemeente-utrecht.nl` (no matching contact person)
- WHEN the email sync runs
- THEN the email MUST appear in the Gemeente Utrecht organization timeline
- AND the system MUST display a suggestion banner: "Contactpersoon aanmaken voor info@gemeente-utrecht.nl?"
- AND clicking the suggestion MUST open the contact creation form pre-filled with the email address and linked to the organization

#### Scenario: No match found

- GIVEN an email arrives from an address not matching any contact, contact person, or organization domain
- THEN the email MUST NOT be added to any timeline
- AND the email MUST remain in the user's regular Nextcloud Mail inbox without modification

#### Scenario: Email matched to multiple contacts

- GIVEN an email is sent to both `jan@devries.nl` (Contact A) and `petra@bakker.nl` (Contact B)
- WHEN the email sync runs
- THEN the system MUST create an `EmailLink` for EACH matching contact
- AND the email MUST appear in both Contact A's and Contact B's timelines
- AND each link MUST indicate the role: "To", "CC", or "BCC" (if detectable)

---

### Requirement: Users MUST be able to manually link emails to pipeline items

Beyond automatic contact matching, users MUST be able to explicitly link emails to deals, leads, and requests.

**Feature tier**: V1

#### Scenario: Link email to lead from Pipelinq

- GIVEN a user views an email in Nextcloud Mail about a quote for lead `Adviestraject Acme B.V.`
- WHEN the user clicks "Link to Pipelinq" (via Nextcloud Mail action menu integration)
- THEN the system MUST display a search dialog showing Pipelinq entities (leads, requests, clients)
- AND selecting the lead MUST create an `EmailLink` object linking the email to the lead
- AND the email MUST also remain on the contact's timeline (if a contact match exists)

#### Scenario: Link email from Pipelinq entity detail

- GIVEN a user is viewing lead "Adviestraject Acme B.V." in Pipelinq
- WHEN they click "E-mail koppelen" and search for recent emails
- THEN the system MUST display emails from the past 30 days matching contacts linked to this lead
- AND selecting an email MUST create the `EmailLink` association
- AND the email MUST appear in the lead's activity timeline

#### Scenario: Unlink incorrectly matched email

- GIVEN an email was automatically linked to contact "Jan de Vries" but the email is actually about a different topic
- WHEN the user clicks "Ontkoppelen" on the email in Jan's timeline
- THEN the `EmailLink` MUST be removed
- AND the email MUST no longer appear in Jan's timeline
- AND the system MUST NOT re-link the email on the next sync cycle (mark as excluded)

#### Scenario: Send email from entity context

- GIVEN a user is viewing client "Acme B.V." in Pipelinq
- WHEN the user clicks "E-mail versturen"
- THEN the system MUST open Nextcloud Mail's compose window with the client's email address pre-filled
- AND after sending, the email MUST be automatically linked to the client via the next sync cycle

---

### Requirement: Calendar events MUST sync with Nextcloud Calendar

Follow-ups and meetings created in Pipelinq MUST appear in Nextcloud Calendar and vice versa.

**Feature tier**: V1

#### Scenario: Create follow-up from Pipelinq

- GIVEN a user creates a follow-up for contact `Jan de Vries` on March 25 at 14:00
- WHEN the follow-up is saved
- THEN a calendar event MUST be created in the user's Nextcloud Calendar via `\OCA\DAV\CalDAV\CalDavBackend`
- AND the event MUST include: title "Follow-up: Jan de Vries", contact name in description, and a link back to the Pipelinq contact (URL in DESCRIPTION field)
- AND a `CalendarLink` object MUST be created linking the event to the contact
- AND the event MUST appear on the contact's timeline as a scheduled activity

#### Scenario: Create meeting with multiple attendees

- GIVEN a user creates a meeting for lead "Adviestraject Acme B.V." with attendees jan@devries.nl and petra@bakker.nl
- WHEN the meeting is saved
- THEN a calendar event MUST be created with both attendees in the ATTENDEE fields
- AND the event MUST be linked to the lead AND to each matching contact
- AND invitation emails MUST be sent via Nextcloud Calendar's standard invitation mechanism

#### Scenario: Calendar event with contact creates timeline entry

- GIVEN a user creates a calendar event in Nextcloud Calendar with attendee `jan@gemeente-utrecht.nl`
- AND `jan@gemeente-utrecht.nl` matches contact `Jan de Vries` in Pipelinq
- WHEN the calendar sync background job runs (every 5 minutes)
- THEN a `CalendarLink` object MUST be created linking the event to Jan's contact record
- AND the event MUST appear in Jan's klantbeeld timeline as type "Afspraak"

#### Scenario: Calendar event completion logging

- GIVEN a follow-up calendar event for March 25 exists linked to contact "Jan de Vries"
- WHEN the date passes
- THEN the timeline entry status MUST update to "Afgerond" (completed) automatically
- AND the system MUST display a prompt in the contact's timeline: "Notities toevoegen over het gesprek?"
- AND the user MUST be able to add notes that are stored as a comment on the `CalendarLink` object

#### Scenario: Reschedule follow-up

- GIVEN a follow-up for March 25 is linked to contact "Jan de Vries"
- WHEN the user reschedules the calendar event to March 28 via Nextcloud Calendar
- THEN the `CalendarLink` MUST be updated with the new date
- AND the timeline entry MUST reflect the new date
- AND the reschedule MUST be noted in the timeline: "Afspraak verplaatst van 25-03 naar 28-03"

---

### Requirement: Email sync MUST respect privacy and scope controls

The system MUST enforce privacy and scope controls so that not all emails are synced to the CRM.

**Feature tier**: V1

#### Scenario: Configure which mail accounts to sync

- GIVEN a user has 3 email accounts in Nextcloud Mail (work, personal, shared)
- WHEN configuring Pipelinq email sync in the personal settings section
- THEN the user MUST be able to select which accounts to sync via checkboxes
- AND personal accounts MUST NOT be synced unless explicitly enabled
- AND the configuration MUST be stored via `IConfig` (per-user preference)

#### Scenario: Configure sync scope (folders)

- GIVEN a user has selected their work email account for sync
- WHEN configuring sync scope
- THEN the user MUST be able to select which folders to sync (e.g., Inbox, Sent) and which to exclude (e.g., Spam, Trash, Drafts)
- AND the default MUST be: Inbox and Sent enabled, all others disabled

#### Scenario: Exclude specific email threads

- GIVEN an email thread is synced to a contact
- WHEN a user marks the thread as "Niet CRM-relevant"
- THEN all `EmailLink` objects for emails in that thread MUST be marked as excluded
- AND future emails in that thread (matched by thread ID / References header) MUST NOT be synced

#### Scenario: Visibility controls for shared emails

- GIVEN user A syncs their emails to contact `Jan de Vries`
- WHEN user B views Jan's timeline
- THEN user A's synced emails MUST be visible to user B (CRM data is shared by default)
- BUT the full email body MUST only be accessible to the syncing user (user A)
- AND user B MUST see: subject, date, sender, direction -- but clicking "Bekijk volledige e-mail" MUST show "Alleen beschikbaar voor de eigenaar"
- AND an admin MUST be able to configure whether email bodies are shared or private per organization policy

---

### Requirement: Sync MUST be near-real-time and handle conflicts

Email and calendar sync MUST be near-real-time and handle conflicts gracefully.

**Feature tier**: V1

#### Scenario: Sync frequency configuration

- GIVEN email sync is enabled for a user
- THEN new emails MUST be synced within 5 minutes of arrival (via `ITimedJob` running every 5 minutes)
- AND calendar events MUST be synced within 5 minutes of creation/modification (same job cycle)
- AND an admin MUST be able to configure the sync interval globally (minimum: 2 minutes, maximum: 60 minutes)

#### Scenario: Deleted email handling

- GIVEN an email linked to contact `Jan` is deleted from Nextcloud Mail
- WHEN the next sync runs
- THEN the `EmailLink` object MUST be updated with a "deleted" flag
- AND the timeline entry MUST show "E-mail verwijderd" with the subject and date preserved
- AND the `EmailLink` MUST NOT be removed (audit trail preservation)

#### Scenario: Sync error handling

- GIVEN the Nextcloud Mail app is temporarily unavailable or the mail account connection fails
- WHEN the sync job runs
- THEN the system MUST log the error and retry on the next cycle
- AND the user's sync status MUST show "Laatst gesynchroniseerd: [timestamp] - Fout bij synchronisatie"
- AND no data MUST be lost or corrupted due to the sync failure

#### Scenario: Large mailbox initial sync

- GIVEN a user enables email sync for an account with 10,000 emails
- WHEN the initial sync starts
- THEN the system MUST process emails in batches (100 per cycle) starting from the most recent
- AND the sync MUST NOT block other background jobs or cause timeouts
- AND a progress indicator MUST be shown: "Synchronisatie: 500/10.000 e-mails verwerkt"

---

### Requirement: Email sync MUST be configurable per user

Each user MUST be able to control their own email sync preferences.

**Feature tier**: V1

#### Scenario: User opt-in to email sync

- GIVEN a new Pipelinq user
- THEN email sync MUST be disabled by default
- AND the user MUST explicitly enable it via Pipelinq personal settings and select mail accounts
- AND the settings page MUST explain what data will be synced and who can see it

#### Scenario: Admin enforcement of email sync

- GIVEN an organization requires all KCC agents to have email sync enabled
- WHEN an admin enables "Force email sync" in Pipelinq admin settings
- THEN all users with the Nextcloud Mail app MUST have sync enabled
- AND users MUST still be able to select which accounts to sync (but cannot fully disable sync)

#### Scenario: Disable email sync

- GIVEN a user has email sync enabled with 500 linked emails
- WHEN the user disables email sync in personal settings
- THEN no new emails MUST be synced from that point forward
- AND existing `EmailLink` objects MUST be preserved (historical data remains on timelines)
- AND the user MUST be informed: "Bestaande koppelingen blijven bewaard"

---

### Requirement: Email Template Quick-Send

The system MUST support sending templated emails from CRM entity context for common communication scenarios.

**Feature tier**: V1

#### Scenario: Send follow-up template from lead

- GIVEN a user is viewing lead "Adviestraject Acme B.V." linked to contact "Petra Bakker"
- WHEN the user clicks "E-mail template" and selects "Offerte follow-up"
- THEN the system MUST open Nextcloud Mail compose with: recipient pre-filled (petra@bakker.nl), subject from template, body from template with merge fields resolved (e.g., {contact.name}, {lead.title})
- AND after sending, the email MUST be automatically linked to both the lead and the contact

#### Scenario: Manage email templates

- GIVEN an admin accesses Pipelinq's email template settings
- WHEN they create a template with name "Offerte follow-up", subject "Follow-up: {lead.title}", and body text with merge fields
- THEN the template MUST be stored as an OpenRegister object with schema `emailTemplate` in the pipelinq register
- AND the template MUST be available to all users when sending emails from entity context

#### Scenario: Template preview before sending

- GIVEN a user selects template "Offerte follow-up" for lead "Adviestraject Acme B.V."
- WHEN the template is selected
- THEN the system MUST display a preview with merge fields resolved showing the actual values
- AND the user MUST be able to edit the preview before sending

---

### Requirement: Activity Logging for CRM Events

The system MUST log email and calendar interactions as activities on the CRM entity for comprehensive activity tracking.

**Feature tier**: V1

#### Scenario: Email logged as activity

- GIVEN an email from "jan@devries.nl" is synced and linked to client "Jan de Vries"
- WHEN the `EmailLink` is created
- THEN an activity MUST be logged via `ActivityService` with type "email_received" or "email_sent"
- AND the activity MUST be visible in the Nextcloud Activity app feed for users watching that entity

#### Scenario: Calendar event logged as activity

- GIVEN a follow-up meeting is created for contact "Jan de Vries"
- WHEN the `CalendarLink` is created
- THEN an activity MUST be logged with type "meeting_scheduled"
- AND when the meeting date passes, an activity "meeting_completed" MUST be logged

#### Scenario: Activity feed aggregation

- GIVEN a user views the Pipelinq dashboard
- WHEN the "Recente activiteiten" widget loads
- THEN the widget MUST include email and calendar activities alongside other CRM activities (lead created, request updated, etc.)
- AND the activities MUST be chronologically sorted and type-filterable

---

### Requirement: Domain-to-Organization Mapping

The system MUST support configuring email domains to organizations for automatic email-to-organization matching.

**Feature tier**: V1

#### Scenario: Configure domain for organization

- GIVEN organization "Gemeente Utrecht" exists in Pipelinq
- WHEN an agent adds domain "gemeente-utrecht.nl" to the organization's profile
- THEN all future emails from any `@gemeente-utrecht.nl` address MUST be linked to the organization
- AND the domain MUST be stored in a `domains` array property on the client schema

#### Scenario: Multiple domains for one organization

- GIVEN organization "Acme Corp" has domains "acme.nl" and "acme-group.com"
- WHEN emails arrive from both domains
- THEN both MUST be matched to the Acme Corp organization

#### Scenario: Domain conflict detection

- GIVEN domain "gmail.com" would match thousands of unrelated contacts
- WHEN an admin attempts to add "gmail.com" as an organization domain
- THEN the system MUST display a warning: "Dit domein is te algemeen en zal veel onterechte koppelingen veroorzaken"
- AND the system MUST maintain a blocklist of common public email domains (gmail.com, outlook.com, hotmail.com, yahoo.com, etc.)

---

## Appendix

### Current Implementation Status

**NOT implemented.** No email or calendar sync functionality exists in the codebase.

- No integration with Nextcloud Mail app (`OCA\Mail`).
- No integration with Nextcloud Calendar app (`OCA\DAV`).
- No email-to-contact matching logic.
- No email sync configuration UI.
- No calendar event creation from Pipelinq.
- No follow-up/meeting scheduling from entity detail views.
- No domain-based organization matching (no `domains` property on client schema).
- No "Link to Pipelinq" action in Nextcloud Mail.
- No mail account selection or sync scope configuration.
- No sync frequency or conflict handling.
- The `ContactSyncService` exists for Nextcloud Contacts sync (address book sync) but is separate from email/calendar sync.
- The `ActivityService` exists for logging CRM activities and could be extended for email/calendar events.
- The `NotificationService` and `Notifier.php` exist for push notifications.
- The client schema has `email` and `contactsUid` properties but no `domains` array for domain-based matching.

### Competitor Comparison

- **Twenty**: Full email/calendar sync with Google, Microsoft, and generic providers. Three visibility levels (metadata only, subject, full). Auto-contact creation from interactions. Domain-based company linking. Sync speed ~400 msgs/min, 5-minute update cycle. No HTML signatures, attachments planned for H1 2026.
- **EspoCRM**: Email integration via IMAP/SMTP with auto-linking to contacts, accounts, and cases. Mass email campaigns. Email templates with merge fields. Group email accounts. Real-time email receiving via web hooks.
- **Krayin**: Built-in email client with IMAP and SendGrid webhook support. Email-to-lead/person linking. Threading via References header. Folder management (inbox, sent, drafts, trash). Attachments supported.
- **Pipelinq advantage**: Native Nextcloud Mail and Calendar integration eliminates the need for separate IMAP/SMTP configuration. Users already have their mail in Nextcloud Mail; Pipelinq adds CRM context on top. CalDAV integration provides standards-based calendar sync. No need to manage mail credentials in the CRM -- Nextcloud handles authentication.

### Standards & References
- Nextcloud Mail API -- `OCA\Mail\Service\MailManager` for accessing email accounts and messages
- Nextcloud Calendar/DAV API -- `OCA\DAV\CalDAV\CalDavBackend` for calendar event creation
- CalDAV (RFC 4791) -- calendar protocol used by Nextcloud Calendar
- iCalendar (RFC 5545) -- event format for calendar entries
- IMAP (RFC 3501) -- email retrieval protocol
- vCard RFC 6350 -- for contact matching via email addresses
- GDPR/AVG -- privacy considerations for email content indexing and storage

### Specificity Assessment
- The spec covers the full sync lifecycle: automatic matching, manual linking, calendar bidirectional sync, privacy controls, and configuration.
- **NOT fully implementable as-is** due to Nextcloud Mail API dependencies:
- **Resolved design decisions:**
  - Sync mechanism: **Nextcloud `ITimedJob` background job** running every 5 minutes, querying Nextcloud Mail's database for new messages matching CRM contacts.
  - Email content storage: Pipelinq stores **metadata only** in `EmailLink` objects (subject, sender, date, direction). Full email body is accessed on-demand from Nextcloud Mail.
  - Calendar sync: Uses **`CalDavBackend`** for creating/reading events. Events are created in a dedicated "Pipelinq" calendar (auto-created per user).
  - "Link to Pipelinq" in Mail: Implemented as a **Nextcloud Mail integration** via the Mail app's action menu extension point (if available) or as a separate browser action.
- **Significant risks:**
  - Nextcloud Mail's internal API is not stable for third-party integration. The `MailManager` service may change between Mail app versions.
  - Deep calendar integration requires understanding CalDAV internals and proper iCalendar event generation.
  - Initial sync of large mailboxes needs careful batching to avoid memory/timeout issues.
- **Open questions:**
  - Does Nextcloud Mail expose events (hooks) for new email arrival? If not, polling the Mail database is required. Recommendation: poll `oc_mail_messages` table via `ITimedJob`.
  - Should calendar sync use a dedicated "Pipelinq" calendar or the user's default? Recommendation: dedicated calendar named "Pipelinq" for clarity.
  - How should email thread tracking work across multiple contacts (CC'd contacts, forwarded emails)? Recommendation: create `EmailLink` per matching contact, use thread ID grouping.

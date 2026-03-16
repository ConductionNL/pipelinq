# email-calendar-sync Specification

## Purpose
Enable bidirectional email and calendar synchronization in Pipelinq. Emails are automatically linked to contacts and pipeline items by matching sender/recipient addresses. Calendar events for follow-ups and meetings are synced with Nextcloud Calendar. This ensures that all communication context is captured in the CRM without manual data entry, leveraging Nextcloud's existing Mail and Calendar apps.

Email/calendar sync is a standard CRM capability, with modern platforms offering Gmail and Outlook sync with automatic contact matching and domain-based company linking, IMAP/SMTP integration with email-to-entity linking, and calendar sync with auto-contact creation from meeting participants. Nextcloud's built-in Mail and Calendar apps provide a natural integration path that standalone backends cannot match.

## Requirements

### Requirement: Emails MUST be automatically linked to CRM contacts
Inbound and outbound emails are matched to Pipelinq contacts by email address.

#### Scenario: Incoming email matched to contact
- GIVEN contact `Jan de Vries` has email `jan@gemeente-utrecht.nl` in Pipelinq
- AND the Nextcloud Mail app receives an email from `jan@gemeente-utrecht.nl`
- WHEN the email sync runs
- THEN the email MUST appear in Jan's contact timeline
- AND the email MUST show: subject, sender, date, and a preview of the body

#### Scenario: Outgoing email matched to contact
- GIVEN a user sends an email to `jan@gemeente-utrecht.nl` via Nextcloud Mail
- WHEN the email sync runs
- THEN the email MUST appear in Jan's contact timeline as an outgoing interaction

#### Scenario: Email matched to organization by domain
- GIVEN organization `Gemeente Utrecht` has domain `gemeente-utrecht.nl` configured
- AND an email arrives from `info@gemeente-utrecht.nl` (no matching contact)
- WHEN the email sync runs
- THEN the email MUST appear in the Gemeente Utrecht organization timeline
- AND the system MUST suggest creating a new contact for the sender

#### Scenario: No match found
- GIVEN an email arrives from an address not matching any contact or organization
- THEN the email MUST NOT be added to any timeline
- AND the email MUST remain in the user's regular Nextcloud Mail inbox

### Requirement: Users MUST be able to manually link emails to pipeline items
Beyond automatic contact matching, users can explicitly link emails to deals.

#### Scenario: Link email to pipeline item
- GIVEN a user views an email in Nextcloud Mail about a quote for deal `deal-1`
- WHEN the user clicks "Link to Pipelinq" and selects `deal-1`
- THEN the email MUST appear in deal `deal-1`'s timeline
- AND the email MUST also remain on the contact's timeline

#### Scenario: Email linked from Pipelinq UI
- GIVEN a user is viewing deal `deal-1` in Pipelinq
- WHEN they click "Link email" and search for recent emails
- THEN matching emails from linked contacts MUST be shown
- AND selecting an email MUST create the link

### Requirement: Calendar events MUST sync with Nextcloud Calendar
Follow-ups and meetings created in Pipelinq appear in Nextcloud Calendar and vice versa.

#### Scenario: Create follow-up from Pipelinq
- GIVEN a user creates a follow-up for contact `Jan de Vries` on March 25 at 14:00
- WHEN the follow-up is saved
- THEN a calendar event MUST be created in the user's Nextcloud Calendar
- AND the event MUST include: title, contact name, and a link back to the Pipelinq contact
- AND the event MUST appear on the contact's timeline as a scheduled activity

#### Scenario: Calendar event with contact creates timeline entry
- GIVEN a user creates a calendar event in Nextcloud Calendar with attendee `jan@gemeente-utrecht.nl`
- AND `jan@gemeente-utrecht.nl` matches contact `Jan de Vries` in Pipelinq
- WHEN the calendar sync runs
- THEN the event MUST appear in Jan's contact timeline as type `meeting`

#### Scenario: Calendar event completion
- GIVEN a follow-up calendar event for March 25 exists
- WHEN the date passes (or the user marks it complete)
- THEN the timeline entry MUST be updated to reflect the event occurred
- AND the user MUST be prompted to add notes about the interaction

### Requirement: Email sync MUST respect privacy and scope controls
Not all emails should be synced to the CRM.

#### Scenario: Configure which mail accounts to sync
- GIVEN a user has 3 email accounts in Nextcloud Mail (work, personal, shared)
- WHEN configuring Pipelinq email sync
- THEN the user MUST be able to select which accounts to sync
- AND personal accounts MUST NOT be synced unless explicitly enabled

#### Scenario: Exclude specific email threads
- GIVEN an email thread is synced to a contact
- WHEN a user marks the thread as "not CRM relevant"
- THEN the thread MUST be removed from the timeline
- AND future emails in that thread MUST NOT be synced

#### Scenario: Visibility controls
- GIVEN user A syncs their emails to contact `Jan de Vries`
- WHEN user B views Jan's timeline
- THEN user A's synced emails MUST be visible to user B (CRM data is shared)
- BUT the full email body MUST only be accessible to users with appropriate permissions

### Requirement: Sync MUST be near-real-time and handle conflicts
Email and calendar sync should be frequent enough to be useful.

#### Scenario: Sync frequency
- GIVEN email sync is enabled
- THEN new emails MUST be synced within 5 minutes of arrival
- AND calendar events MUST be synced within 2 minutes of creation/modification

#### Scenario: Deleted email handling
- GIVEN an email linked to contact `Jan` is deleted from Nextcloud Mail
- WHEN the next sync runs
- THEN the timeline entry MUST be marked as "email deleted" but NOT removed
- AND the subject and date MUST be preserved for audit purposes

### Requirement: Email sync MUST be configurable per user
Each user controls their own email sync preferences.

#### Scenario: User opt-in to email sync
- GIVEN a new Pipelinq user
- THEN email sync MUST be disabled by default
- AND the user MUST explicitly enable it and select mail accounts
- AND an admin MUST be able to enforce email sync for all users (organization policy)

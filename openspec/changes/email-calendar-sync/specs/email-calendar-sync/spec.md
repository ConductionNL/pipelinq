# Email Calendar Sync — Delta Spec

## Purpose

Surface email and calendar communications on Pipelinq CRM detail pages by **consuming the OpenRegister `email` and `calendar` integration leaves** (ADR-022), and add CRM-specific email-to-entity matching plus automation triggers on top. No pipelinq-local link schemas, timeline components, or sync services are introduced — the link tables, sidebar tabs, and follow-up-event create flow are owned by the leaves.

**Standards**: Schema.org (`schema:CommunicateAction`, `schema:Event`), iCalendar (RFC 5545), vCard (RFC 6350 email field)
**Feature tier**: V1 (leaf enablement, email matching), V2 (automation triggers)
**OR leaves consumed**: `email` (`integration-email`), `calendar` (`integration-calendar`) via the pluggable integration registry (ADR-019) and app manifest (ADR-024)
**OCP interfaces**: `OCP\Mail\IMailManager` (matching job only)

---

## ADDED Requirements

### Requirement: Enable email + calendar leaves on CRM detail pages

The app manifest MUST add `email` and `calendar` to the `linkedTypes` of the `client`, `contact`, `lead`, and `request` schemas so that the leaves' tabs and cards render on those detail pages. The system MUST NOT define pipelinq-local `emailLink` or `calendarLink` schemas.

#### Scenario: Email tab appears on client detail

- **GIVEN** the Nextcloud Mail app is installed and the `client` schema lists `email` in `linkedTypes`
- **WHEN** an agent opens a client detail page
- **THEN** the `email` leaf's `CnEmailTab` MUST render the client's linked emails
- **AND** no pipelinq-local email-timeline component is loaded

#### Scenario: Calendar card appears on lead detail

- **GIVEN** the Nextcloud Calendar app is installed and the `lead` schema lists `calendar` in `linkedTypes`
- **WHEN** an agent opens a lead detail page
- **THEN** the `calendar` leaf's `CnCalendarCard` MUST render the lead's linked events

#### Scenario: No pipelinq-local link schema is registered

- **GIVEN** the pipelinq register file `lib/Settings/pipelinq_register.json`
- **WHEN** the register is imported
- **THEN** it MUST NOT contain an `emailLink` or `calendarLink` schema definition

---

### Requirement: CRM email-to-entity matching job

The system MUST run an `ITimedJob` (`EmailMatchJob`) every 5 minutes that resolves a Nextcloud Mail message's sender/recipient to a CRM entity and links the message to that entity **through the `email` leaf's link API** (`openregister_email_links`), not through a pipelinq-local table.

#### Scenario: New inbound email from known contact is linked via the leaf

- **GIVEN** a contact exists with email address `contact@example.nl`
- **AND** sync is enabled for the Pipelinq mail account
- **WHEN** a new email arrives from `contact@example.nl`
- **THEN** within 5 minutes the message MUST be linked to that contact via the email leaf
- **AND** the link MUST be visible in the leaf's `CnEmailTab` on the contact detail page

#### Scenario: Duplicate email is not re-linked

- **GIVEN** the email leaf already holds a link for message X on the matched entity
- **WHEN** the matching job runs again
- **THEN** no duplicate link is created

#### Scenario: Sync disabled for account

- **GIVEN** the user has disabled sync for a mail account
- **WHEN** the `EmailMatchJob` runs
- **THEN** no email links are created from that account's messages

---

### Requirement: Email-to-contact matching rule

The `EmailMatchJob` MUST match sender/recipient addresses to `contact.email` and `client.email` fields in OpenRegister. This CRM matching rule is pipelinq-specific and stays in pipelinq.

#### Scenario: Match by exact email address

- **GIVEN** a contact exists with `email: "j.devries@gemeente-utrecht.nl"`
- **WHEN** an email is processed with sender `j.devries@gemeente-utrecht.nl`
- **THEN** the matcher MUST return that contact's entity reference

#### Scenario: No match returns empty

- **GIVEN** no contact or client has email `unknown@example.nl`
- **WHEN** the matcher is called for `unknown@example.nl`
- **THEN** it MUST return an empty result and create no link

---

### Requirement: Domain-to-organization matching rule

When no exact email match is found, the matcher MUST attempt to match the sender's domain against client organizations — unless the domain is a public email provider.

#### Scenario: Corporate domain matched to organization

- **GIVEN** a client of type `organization` with email `info@bakker-installaties.nl` exists
- **AND** no individual contact has the address `p.bakker@bakker-installaties.nl`
- **WHEN** an email from `p.bakker@bakker-installaties.nl` is processed
- **THEN** the matcher MUST return the organization's entity reference

#### Scenario: Public domain is not matched

- **GIVEN** an email from `iemand@gmail.com`
- **WHEN** the matcher evaluates `gmail.com`
- **THEN** it MUST treat the domain as public and skip domain matching

---

### Requirement: Follow-up events use the calendar leaf create flow

Creating a follow-up event from a lead, request, or client detail page MUST use the `calendar` leaf's inline create flow (`CnCalendarTab`). The system MUST NOT implement a pipelinq-local `createFollowUpEvent` or `calendarLink` write.

#### Scenario: Follow-up event created via the leaf

- **GIVEN** a lead detail page is open with a linked contact
- **WHEN** the agent uses the calendar leaf's "Add meeting" create flow
- **THEN** the VEVENT MUST be created and linked to the lead by the calendar leaf
- **AND** the new event MUST appear in the leaf's `CnCalendarCard` without a page reload

#### Scenario: Follow-up form pre-fills attendees from contact

- **GIVEN** a lead is linked to a contact with email `j.devries@gemeente-utrecht.nl`
- **WHEN** the calendar leaf's create form opens from that lead
- **THEN** the attendees field MUST be pre-filled with `j.devries@gemeente-utrecht.nl`

---

### Requirement: Per-user matching-job settings

Each Nextcloud user MUST be able to configure their own matching-job preferences (mail account to index, sync enabled toggle, excluded addresses) independently via the in-app settings modal. These settings govern the matching job only; link display/storage is owned by the email leaf.

#### Scenario: Settings saved per user

- **GIVEN** two users have different mail account configurations
- **WHEN** each saves their sync settings
- **THEN** each user's settings MUST be stored independently in `IAppConfig`
- **AND** changing one user's settings MUST NOT affect the other

#### Scenario: Settings surfaced in user settings modal

- **GIVEN** a user opens the in-app settings modal (gear menu)
- **WHEN** the sync settings section is rendered
- **THEN** it MUST show: mail account selector, sync enabled toggle, excluded addresses field, last run status, and a "Sync now" button

---

### Requirement: Matching-job status display

The sync settings UI MUST display the last matching-job run timestamp, count of links created, and any error messages from the last run.

#### Scenario: Status shows last run time

- **GIVEN** a matching-job run completed successfully at 14:30
- **WHEN** the user views sync settings
- **THEN** the status MUST show "Last synced: [date] at 14:30" (formatted per user locale)

#### Scenario: Status shows error when run failed

- **GIVEN** the last run produced an error (e.g., mail account unreachable)
- **WHEN** the user views sync settings
- **THEN** the status MUST show an error indicator with the error message
- **AND** the error message MUST NOT expose internal paths, SQL, or stack traces (per ADR-005)

---

### Requirement: Automation trigger types

The `automation` entity MUST accept `email.received` and `calendar.event.start` as valid `trigger` values. This is pipelinq-specific automation wiring and stays in pipelinq.

#### Scenario: email.received automation triggers on new linked email

- **GIVEN** an `automation` with `trigger: email.received` and `isActive: true` exists
- **WHEN** the `EmailMatchJob` links a new inbound email to a client
- **THEN** the automation engine MUST evaluate trigger conditions against the linked entity
- **AND** execute configured actions if conditions match

#### Scenario: calendar.event.start automation triggers on event start

- **GIVEN** an `automation` with `trigger: calendar.event.start` exists
- **AND** a calendar-leaf-linked event has a `startDate` in the next evaluation window
- **WHEN** the automation engine processes the window
- **THEN** it MUST evaluate the trigger against the linked entity

---

### Requirement: Unit tests

Every new PHP service and controller MUST have PHPUnit tests with at least 3 test methods per class.

#### Scenario: Matcher tests cover matching logic

- **GIVEN** `EmailMatchJobTest.php` (or the matcher service test) exists
- **WHEN** `composer test` runs
- **THEN** tests for exact-address match, domain match, and public-domain skip MUST pass

#### Scenario: Settings controller error paths tested

- **GIVEN** the sync-settings controller test exists
- **THEN** tests MUST cover: 200 success, 401 unauthenticated, and 400 invalid input for each endpoint

---

### Requirement: Translation coverage

All user-visible strings in the sync-settings UI MUST have `en.json` and `nl.json` entries.

#### Scenario: No hardcoded strings in sync settings

- **GIVEN** the sync settings section is rendered
- **WHEN** the app language is set to Dutch (nl)
- **THEN** all labels, buttons, empty states, and error messages MUST display in Dutch
- **AND** no raw English string MUST appear in Dutch locale

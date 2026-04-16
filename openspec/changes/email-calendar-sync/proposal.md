# Proposal: email-calendar-sync

## Summary

Connect Pipelinq to Nextcloud Mail and Calendar so that emails and calendar events are automatically linked to CRM records. Agents can see the full communication history per client or lead without switching between applications, and marketing automations can trigger on email and calendar events.

Based on market intelligence: **3 feature clusters with a combined demand score of 5** across "Marketing Automation" (demand: 2 × 2 clusters) and "advanced marketing automation" (demand: 1).

## Demand Evidence

### Feature: Marketing Automation (demand: 2)

Email and calendar integration is cited as a prerequisite for CRM marketing automation in procurement requirements. Organizations need the ability to track communication touchpoints and trigger follow-up sequences based on email receipt and calendar events.

### Feature: Marketing Automation (demand: 2)

Second independent cluster confirming market demand for automation capabilities driven by email and calendar events — specifically scheduled follow-ups, appointment reminders, and email-triggered CRM workflows.

### Feature: advanced marketing automation (demand: 1)

Complex multi-step marketing sequences that combine email timing, calendar scheduling, and CRM stage progression. Requires email and calendar sync as the foundational layer before advanced automation logic can be built.

## Problem

Pipelinq has no integration with Nextcloud Mail or Calendar. As a result:

- Emails exchanged with a client are invisible inside the CRM — agents must manually check Nextcloud Mail and copy context into notes.
- Calendar events for meetings, demos, and follow-up calls are not linked to CRM records — there is no way to see scheduled appointments from a lead or request detail view.
- CRM automations cannot react to email or calendar events — the `automation` entity supports trigger types but no `email.received` or `calendar.event.start` triggers are wired.
- Teams running email-based outreach have no way to track which clients were contacted and whether they responded.

This gap directly blocks the marketing automation features that the market demands.

## Solution

Implement email and calendar synchronisation using the existing `emailLink` and `calendarLink` OpenRegister schemas defined in ADR-000:

1. **EmailSyncJob** — ITimedJob background job running every 5 minutes, fetching new messages from Nextcloud Mail via `OCP\Mail\IMailManager` and creating `emailLink` objects for matched CRM entities.
2. **EmailSyncService** — matching logic: look up sender/recipient addresses against `contact.email` and `client.email`; fall back to domain-to-organization matching for unknown senders.
3. **CalendarSyncService** — bidirectional calendar link management: create `calendarLink` objects from Nextcloud Calendar events that include attendees matching CRM contacts, and allow creating calendar follow-up events directly from entity context.
4. **EmailSyncController** — REST API for per-user sync settings (which mail account to index, sync toggle, public-domain exclusion list).
5. **Email Timeline UI** — `EmailTimelineCard.vue` component displayed on client, contact, lead, and request detail views showing linked emails with subject, date, direction, and a link to open the full message in Nextcloud Mail.
6. **Calendar Events UI** — `CalendarEventCard.vue` component displayed on entity detail views showing upcoming and past linked calendar events, with a button to schedule a new follow-up event.
7. **Automation trigger types** — register `email.received` and `calendar.event.start` as valid automation trigger values so that the existing automation engine can react to communication events.

## Scope

### In scope

- `emailLink` objects created by background job from Nextcloud Mail (poll-based, every 5 minutes)
- `calendarLink` objects created from Nextcloud Calendar events (poll-based)
- Email-to-contact matching by exact email address (`contact.email`, `client.email`)
- Domain-to-organization matching for corporate email domains (skips public domains: gmail, outlook, yahoo, etc.)
- Per-user sync configuration: select mail account, enable/disable sync, exclude email addresses
- Email timeline display on client, contact, lead, and request detail views
- Calendar event timeline display on lead, request, and client detail views
- Create follow-up calendar event from entity context (pre-fills attendees from linked contacts)
- Automation trigger types: `email.received`, `calendar.event.start`
- Sync status display: last sync timestamp, error count, sync toggle

### Out of scope

- Email compose and send from within Pipelinq (requires Mail app plugin API — V2)
- Email template library and marketing campaign sequences (V2)
- Bulk email / mass marketing sends (V3)
- Real-time push (WebSocket/webhook from Mail) — poll only in this change
- Email attachment management inside CRM (OpenRegister FileService handles this separately)
- "Link to Pipelinq" action in Nextcloud Mail sidebar (requires Nextcloud Mail plugin — V2)
- Sync monitoring admin dashboard (V2)

## Acceptance Criteria

1. **GIVEN** a Nextcloud Mail account is configured for sync, **WHEN** an email is exchanged with a known contact's email address, **THEN** an `emailLink` object is created within 5 minutes linking the email to the contact and its parent client.

2. **GIVEN** an `emailLink` exists for a client, **WHEN** an agent views the client detail page, **THEN** a chronological email timeline is visible with subject, date, direction, and a link to open the message in Nextcloud Mail.

3. **GIVEN** a lead exists with a linked contact, **WHEN** the agent clicks "Schedule follow-up" on the lead detail page, **THEN** a calendar event creation dialog opens pre-filled with the contact's email as attendee, and on save a `calendarLink` object is created linking the event to the lead.

4. **GIVEN** a calendar event exists for a meeting with a known contact, **WHEN** an agent views the contact detail page, **THEN** linked calendar events are displayed with title, date/time, and status.

5. **GIVEN** an agent configures sync settings, **WHEN** they disable sync for a specific mail account, **THEN** no new `emailLink` objects are created from that account's emails.

6. **GIVEN** an automation with trigger type `email.received`, **WHEN** a new inbound `emailLink` is created for an entity, **THEN** the automation engine evaluates and executes matching automations for that entity.

## Dependencies

- **client-management** (completed) — Clients and contacts must exist for email address matching
- **OpenRegister** — `emailLink` and `calendarLink` schemas must be registered
- **crm-workflow-automation** — Automation engine must accept `email.received` and `calendar.event.start` trigger types
- **Nextcloud OCP interfaces**: `OCP\Mail\IMailManager`, `OCP\Calendar\ICalendarQuery`, `OCP\IUserSession`

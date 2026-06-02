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

**Leaf-first (ADR-022).** The link-table, sidebar timeline, and follow-up-event-creation concepts in this change map directly onto two OpenRegister integration leaves that already own this functionality fleet-wide:

- **`email` leaf** (`openregister/.../integration-email`) — `EmailProvider` + `CnEmailTab` + `CnEmailCard`. Backed by OR's shipped `EmailService`/`EmailsController` (links Nextcloud Mail messages to OR objects via `openregister_email_links`, caches subject/sender/date, reverse-lookup by sender). Note: the email leaf is **link-only** — compose/send stays out of scope.
- **`calendar` leaf** (`openregister/.../integration-calendar`) — `CalendarProvider` + `CnCalendarTab` + `CnCalendarCard`. Backed by OR's shipped `CalendarEventService`/`CalendarEventsController` (link/create/unlink VEVENT, attendee handling, `X-OPENREGISTER-*` reverse discovery, the "Add meeting" inline create flow).

Building pipelinq-local `emailLink`/`calendarLink` schemas, an `EmailSyncService`/`CalendarSyncService`, and `EmailTimelineCard`/`CalendarEventCard` Vue components would reproduce three ADR-022 review-blocking anti-patterns at once — **parallel link tables**, **duplicate sidebar tab systems**, and **app-local "linked emails/events" that mirror an OR integration**. This change therefore **consumes the leaves** instead:

1. **Enable the leaves on CRM detail pages via the app manifest (ADR-024).** Add `email` and `calendar` to the `linkedTypes` of the `client`, `contact`, `lead`, and `request` schemas so the `CnEmailTab`/`CnEmailCard` and `CnCalendarTab`/`CnCalendarCard` widgets render on those detail pages — no per-app timeline component, no per-app link schema, no per-app sync service.
2. **CRM email-to-entity matching (genuinely app-specific, stays in pipelinq).** A thin `EmailMatchJob` (`ITimedJob`, every 5 minutes) resolves a Mail message's sender/recipient against `contact.email` / `client.email` (falling back to domain-to-organization matching, skipping public domains) and then calls the **email leaf's link API** to attach the message to the matched OR object. Pipelinq owns the CRM matching rule; OR owns the link record.
3. **Follow-up events use the calendar leaf's create flow.** "Schedule follow-up" from a lead/request opens the `CnCalendarTab` inline create form (attendees pre-filled from the linked contact via the contacts integration) — pipelinq does not implement its own `createFollowUpEvent`.
4. **Per-user sync configuration** for the matching job (which mail account to index, sync toggle, public-domain exclusion list) stays a thin pipelinq setting; the leaf owns display/storage of the links themselves.
5. **Automation trigger types (genuinely app-specific, stays in pipelinq).** Register `email.received` and `calendar.event.start` as valid `automation` trigger values; the matching job and the calendar leaf's link events drive the existing pipelinq automation engine.

## Scope

### In scope

- Enable the `email` + `calendar` leaves on `client`, `contact`, `lead`, `request` detail pages via the app manifest (add to each schema's `linkedTypes`)
- `EmailMatchJob` (poll-based, every 5 minutes): resolve a Mail message's sender/recipient and link it to the matched OR object **through the email leaf's link API**
- Email-to-contact matching by exact email address (`contact.email`, `client.email`) — pipelinq CRM rule
- Domain-to-organization matching for corporate email domains (skips public domains: gmail, outlook, yahoo, etc.) — pipelinq CRM rule
- Per-user sync configuration for the matching job: select mail account, enable/disable sync, exclude email addresses
- Automation trigger types: `email.received`, `calendar.event.start` — pipelinq automation engine
- Sync status display for the matching job: last run timestamp, count linked, error count

### Out of scope

- **Email link-table, email timeline UI, calendar link-table, calendar timeline UI, and follow-up-event create flow** — all owned by the `email` and `calendar` OR integration leaves (ADR-022); pipelinq consumes them, does not rebuild them
- Pipelinq-local `emailLink` / `calendarLink` schemas — superseded by `openregister_email_links` / the calendar leaf's link table
- Email compose and send from within Pipelinq (the email leaf is link-only; sending handled by n8n / openconnector workflows)
- Email template library and marketing campaign sequences (V2; see `marketing-segmentation-and-blast`)
- Bulk email / mass marketing sends (V3)
- Real-time push (WebSocket/webhook from Mail) — poll only in this change
- Email attachment management inside CRM (OpenRegister FileService handles this separately)
- Sync monitoring admin dashboard (V2)

## Acceptance Criteria

1. **GIVEN** a Nextcloud Mail account is configured for sync, **WHEN** an email is exchanged with a known contact's email address, **THEN** an `emailLink` object is created within 5 minutes linking the email to the contact and its parent client.

2. **GIVEN** the matching job has linked an email to a client, **WHEN** an agent views the client detail page, **THEN** the email leaf's `CnEmailTab`/`CnEmailCard` renders a chronological email timeline (subject, date, direction, open-in-Mail link) — no pipelinq-local timeline component is involved.

3. **GIVEN** a lead exists with a linked contact, **WHEN** the agent uses the calendar leaf's inline create flow on the lead detail page, **THEN** a follow-up VEVENT is created (attendees pre-filled from the linked contact) and linked to the lead via the calendar leaf — no pipelinq-local `calendarLink` object is created.

4. **GIVEN** a calendar event exists for a meeting with a known contact, **WHEN** an agent views the contact detail page, **THEN** the calendar leaf's `CnCalendarCard` displays the linked event with title, date/time, and status.

5. **GIVEN** an agent configures sync settings, **WHEN** they disable sync for a specific mail account, **THEN** the `EmailMatchJob` creates no new email links from that account.

6. **GIVEN** an automation with trigger type `email.received`, **WHEN** the matching job links a new inbound email to an entity, **THEN** the automation engine evaluates and executes matching automations for that entity.

## Dependencies

- **client-management** (completed) — Clients and contacts must exist for email address matching
- **OpenRegister `email` leaf** (`integration-email`) — provides `EmailProvider`, `CnEmailTab`, `CnEmailCard`, `openregister_email_links`; pipelinq links via its API
- **OpenRegister `calendar` leaf** (`integration-calendar`) — provides `CalendarProvider`, `CnCalendarTab`, `CnCalendarCard`, VEVENT link/create; pipelinq enables it on detail pages
- **OpenRegister pluggable integration registry** (ADR-019) + **app manifest** (ADR-024) — the mechanism that places the leaf widgets on detail pages
- **crm-workflow-automation** — Automation engine must accept `email.received` and `calendar.event.start` trigger types
- **Nextcloud OCP interfaces**: `OCP\Mail\IMailManager` (matching job only); calendar/link surfaces are mediated by the OR leaves

---
kind: mixed
depends_on: [calendar-leaf-inbound]
---

## Why

Pipelinq already consumes OpenRegister's calendar leaf correctly on two of its three CRM hubs: LeadDetail and ContactDetail render the leaf's `calendar` integration widget ("Meetings"), the appointment-booking surface delegates all calendar I/O to the leaf via `AppointmentCalendarLeafProvider` (ADR-022 leaf-first), and the live spec (`openspec/specs/email-calendar-sync/spec.md`, authoritative block from line 411) mandates that follow-up events are created through the leaf's inline create flow and forbids any pipelinq-local calendar write.

Four consumption gaps remain — all pipelinq-side, none requiring new calendar machinery in pipelinq:

1. **ClientDetail has no calendar widget**, even though the `client` schema already declares `calendar` in its `linkedTypes` (`lib/Settings/pipelinq_register.json` ~line 89) and the existing requirement "Enable email + calendar leaves on CRM detail pages" names `client` explicitly. The account hub of the CRM is the one page where an agent cannot see meetings.
2. **A follow-up that was scheduled is a follow-up that can be forgotten.** The leaf creates and links the VEVENT, but nothing reminds the lead's assignee before the meeting, and a `followUpTask` deadline passes silently. The task schema also cannot attach to a lead or contact (no FK), so "follow up on this deal" has no record to hang off.
3. **Linked events never reach the record timeline.** `ActivityTimeline` (ClientDetail) aggregates contactmomenten and notes from pipelinq's own `/api/timeline`, but leaf-linked calendar events are invisible there — the one chronological view of a client omits the meetings.
4. **Inbound event-to-record backfill is dormant.** Events tagged with `X-OPENREGISTER-*` properties from the Calendar-app side (or by earlier leaf versions) only reach the link table through OpenRegister's `BackfillCalendarLinksJob`, which is unregistered and disabled by default, and NC-Calendar-side edits never refresh the cached link fields. That machinery is the leaf's to fix; pipelinq's part is to consume it and document enabling it.

## What Changes

Pipelinq-side only, strictly leaf-first:

1. **ClientDetail calendar widget** — add `{ "id": "client-calendar", "type": "integration", "integrationId": "calendar", "title": "Meetings", "icon": "Calendar" }` to the ClientDetail `config.widgets` in `src/manifest.json` plus a layout row, the exact shape LeadDetail (`lead-calendar`) and ContactDetail (`contact-calendar`) already use. Pure manifest edit; satisfies the existing L412 requirement rather than extending scope.
2. **Follow-up reminders on leads and tasks** — a `FollowUpReminderJob` (`TimedJob`, 900 s, mirroring `CallbackOverdueJob`) that (a) reads upcoming leaf-linked events for open leads **through the leaf's read API** (`CalendarLinkService::getLinkedEvents` / `GET /api/objects/{r}/{s}/{id}/events`) and notifies the lead `assignee` ahead of the event, and (b) notifies the assignee of an open `followUpTask` whose `deadline` is due. Idempotent via a recently-notified marker; graceful no-op when the leaf or register is unavailable. No CalDAV I/O in pipelinq. Additionally the `task` schema gains optional `leadId` and `contactId` relation fields so a follow-up task can be attached to the record it follows up.
3. **Linked events on the record timeline** — the timeline aggregation behind `ActivityTimeline` / `entityActivity#index` merges leaf-linked calendar events (read live through the leaf, never persisted pipelinq-side) as appointment-typed entries, deduplicated against existing sources.
4. **Consume inbound backfill** — once the companion OpenRegister change lands (see Dependencies), leaf-tagged events created or edited on the Calendar-app side appear on pipelinq's widgets and timeline with no pipelinq action. Pipelinq's first-time-setup/admin documentation gains the `backfill_calendar_links` enablement step; pipelinq SHALL NOT build its own CalDAV scan.

## Capabilities

### Modified Capabilities

- `email-calendar-sync` — calendar-leaf consumption deepened: ClientDetail widget parity, leaf-driven follow-up reminders, leaf events on the record timeline, inbound backfill consumption.

## Dependencies on the OpenRegister leaf (not specced here)

These capabilities belong in the leaf and are proposed as the companion change **`openregister/openspec/changes/calendar-leaf-inbound`**; pipelinq consumes them and MUST NOT reimplement them:

- Inbound CalDAV listeners (event edited/moved/cancelled/deleted in NC Calendar → link table refreshed) — today nothing listens and cached link fields go stale silently.
- Registration + default-on enablement of `BackfillCalendarLinksJob` (currently unregistered, flag `backfill_calendar_links` default `no`).
- Idempotent linking and a reverse event→objects lookup (today repeat link calls duplicate rows; there is no `findByEventUid`).
- Reminder machinery at the VEVENT level (VALARM on `createEvent`) so calendar-native alarms work in every CalDAV client; pipelinq's `FollowUpReminderJob` covers only the in-app NC-notification layer on top.

This change degrades gracefully without the companion: widget, timeline entries, and reminders work for leaf-created links; only inbound-originated links stay invisible until the leaf ships them.

## Impact

- `src/manifest.json` — ClientDetail widget + layout row (additive).
- `lib/Settings/pipelinq_register.json` — `task` schema gains optional `leadId` / `contactId` (additive; a property addition, not a rename — no data migration).
- New `lib/BackgroundJob/FollowUpReminderJob.php`; timeline controller/service extended to merge leaf events.
- No new pipelinq schema, no pipelinq-local calendar/link storage, no CalDAV client — the L412/L500 prohibitions stay intact.
- Depends on `calendar-leaf-inbound` (openregister) for inbound freshness; must not claim inbound sync as shipped before that lands.

# Design — calendar-deepening

## Context

OpenRegister's calendar leaf already provides: outbound VEVENT creation with `X-OPENREGISTER-*` + RFC 9253 `LINK` stamping into a per-user pinned calendar (`CalendarEventService::createEvent`, pin key `events_calendar_uri`), link/unlink of existing events, the `openregister_calendar_links` Tier-2 link table with cached summary/dtstart/dtend/location (`CalendarLinkService`), object-scoped read APIs (`GET /api/objects/{r}/{s}/{id}/events`, `getLinkedEvents` with link-table ∪ X-OR-scan dedupe), calendar/event pickers, a read-only virtual-calendar provider (`RegisterCalendarProvider`), and the `calendar` integration provider (group `comms`, storage strategy `link-table`) that renders the shared-library card/tab on detail pages.

What the leaf does **not** provide today — and what this change therefore must not claim — is inbound: no listeners on `OCA\DAV` calendar-object events (Calendar-side edits silently stale the cached link fields), `BackfillCalendarLinksJob` unregistered and default-off (`backfill_calendar_links` = `no`), no idempotent linking, no reverse event→objects lookup, no VALARM/reminder machinery, no recurrence, no `updateEvent`. Those gaps are the companion change `calendar-leaf-inbound` (openregister).

Pipelinq's own state: calendar widgets on LeadDetail/ContactDetail but not ClientDetail (despite `client.linkedTypes` already declaring `calendar`); `ActivityTimeline` only on ClientDetail, fed by pipelinq's `/api/timeline` aggregation (contactmomenten + notes — no leaf events); a booking-scoped `ReminderDispatchJob` (24 h window) but no CRM follow-up reminder; a `task` schema whose `type` enum already contains `followUpTask` but which has `clientId`/`requestId` FKs only — no `leadId`/`contactId`.

## Goals / Non-Goals

**Goals:**
- Widget parity: Meetings on ClientDetail.
- Leaf-driven follow-up reminders for leads (linked events) and tasks (deadlines), in-app NC notifications only.
- Leaf-linked events merged into the record timeline, read-through, never stored.
- Consume the leaf's inbound backfill/listeners; document the flag.

**Non-Goals:**
- No calendar writes, CalDAV clients, event listeners, or link storage in pipelinq (L412/L500 prohibitions stand).
- No VALARM authoring — that is leaf capability (`calendar-leaf-inbound`).
- No re-spec of the leaf's create flow, tabs/cards, or the email matching job.
- No recurrence or attendee-scheduling handling — out of scope on both sides for now.

## Decisions

### D1 — Widget is a manifest row, nothing else

ClientDetail gains `client-calendar` with the identical prop-less shape of `lead-calendar`/`contact-calendar`; the leaf resolves register/schema/objectId from the page `config`. Placement: 8-wide under the comms row (next to `client-email`), pushing subsequent rows down — same grid discipline as ContactDetail.

### D2 — Reminders are pipelinq orchestration on top of leaf reads

The leaf owns the event; pipelinq owns "my CRM nags me". `FollowUpReminderJob` (TimedJob, 900 s like `CallbackOverdueJob`) iterates open leads with an `assignee`, calls the leaf read surface per lead, and notifies for events with `dtstart` in a configurable look-ahead window (default 24 h, app-config `followup_reminder_window_hours`). Task deadlines are read from OR objects pipelinq already queries. Dedupe via the `wasRecentlyNotified`/`markNotified` marker pattern already proven in `CallbackOverdueJob` (per event UID / task id + occurrence). The leaf is resolved through the DI container with `method_exists` guards, exactly like `AppointmentCalendarLeafProvider::resolveLeaf()` — openregister stays an optional runtime dependency, and the register guard fails closed on `register === ''` (the pipelinq guard convention from `EmailMatchService`).

Why not `x-openregister-notifications`? The dialect triggers on object lifecycle events (`created`, filtered), not on wall-clock proximity to a date; time-based dispatch needs a job. When the dialect grows scheduled triggers, this job is the first candidate for retirement — noted here so the imperative code carries its ADR-031 justification (scheduled delivery exception).

### D3 — Timeline entries are read-through, not mirrored

`ActivityTimeline`'s server aggregation gains one more source: the leaf's linked events for the entity, mapped to appointment-typed entries at request time. No persistence — the leaf's link table is already the system of record, and mirroring would recreate the pipelinq-local `CalendarLink` the spec forbids. Dedupe key: event UID against any timeline entry that already carries one (the component already models calendar-type items independently; the aggregation must prefer the leaf-sourced entry and drop same-UID duplicates). Failure isolation: leaf errors reduce to an omitted source, never a failed timeline response.

### D4 — Task FKs, not a new follow-up schema

`followUpTask` already exists in the `task.type` enum; the missing piece is attachment. Adding optional `leadId`/`contactId` (same shape as the existing `clientId`/`requestId`) is additive and lets the existing related-collection primitive surface follow-ups on Lead/Contact pages. A dedicated `followUp` schema would duplicate the task lifecycle for no gain (ADR-012).

### D5 — Inbound is consumed via dependency, and the claim is honest

Pipelinq's scenario "event linked from the Calendar side appears" is conditional on `calendar-leaf-inbound` shipping. Until then: leaf-created links work end-to-end; Calendar-side-originated links surface only after a manual `occ background-job:execute` of the backfill with the flag set. The product page must not claim continuous two-way sync before the companion lands — the leaf today has zero inbound listeners and the backfill is a disabled one-shot.

## Risks / Trade-offs

- **Reminder fan-out cost**: one leaf read per open lead per run. Bounded by open-lead count and the 900 s interval; if it grows, the leaf's missing reverse/date-range query (flagged in the companion change) is the proper fix — not a pipelinq cache.
- **Double timeline entries**: `ActivityTimeline` already draws calendar-ish icons from its own sources; the UID-dedupe in D3 is load-bearing and needs a regression test.
- **Stale cached link fields**: until the leaf's inbound listeners land, a meeting moved in NC Calendar keeps its old `dtstart` in the link table — a reminder could fire for a moved meeting at the old time when reads come from cached rows. Mitigation: reminder reads prefer the leaf's live event read over cached rows where available; residual risk documented, resolved by the companion change.
- **Notification fatigue**: one reminder per event occurrence, window default 24 h, and reminders only for `assignee`-owned records.

## Seed Data

Existing lead/task seed objects suffice; add one seeded `followUpTask` carrying `leadId` to exercise the related-collection surface and the deadline reminder in dev.

## Migration Plan

- Manifest + register JSON changes deploy by re-import on app update; additive only, rollback = revert the rows.
- New job registers in `info.xml`; removing it deranks cleanly.
- Task FK addition requires no data migration (new optional properties).
- Ordering: independent of the companion change for everything except the inbound scenarios; `depends_on: [calendar-leaf-inbound]` marks the claim boundary, not a merge blocker for the pipelinq-side surfaces.

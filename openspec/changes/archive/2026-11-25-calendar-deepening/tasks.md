## 1. ClientDetail calendar widget

- [x] Add `{ "id": "client-calendar", "type": "integration", "integrationId": "calendar", "title": "Meetings", "icon": "Calendar" }` to ClientDetail `config.widgets` in `src/manifest.json` (same shape as `lead-calendar` / `contact-calendar`).
- [x] Add the ClientDetail layout row for `client-calendar` (8-wide, under the comms row next to `client-email`) and shift subsequent `gridY` values accordingly.
- [x] Verify `src/manifest.json` still validates against the manifest schema and the page renders with the widget in the integration-unavailable state when the Calendar app is off.
  - Live pass 17 Nov 2026: not verifiable as written. ClientDetail's layout is now only `client-panels` (client-page-as-the-relation-boards), so the `client-calendar` layout row this task checks is gone. Re-read against the relation board.
  - Live 25 Nov 2026 (sandbox NC 34 + MariaDB, OpenRegister main 119f4810, pipelinq development 3db8eb51, Zuiddrecht set; the Calendar app is not installed): `npm run check:manifest` says 'Manifest OK: v1.1.0 | 51 pages'. ClientDetail now carries `client-calendar` in its side column (after `client-email`). Opening a client (Playwright, admin) renders the page normally; the side card `cn-detail-page-side-client-calendar` reads "Meetings / No meetings", and `GET .../integrations/calendar` answers 200 with no rows. Decision: the leaf reads Nextcloud's own CalDAV, so without the Calendar app it is not unavailable but empty; the delta scenario now says so (the card's unavailable text appears only on a 503 from the leaf).

## 2. Task schema follow-up attachment

- [x] Add optional `leadId` and `contactId` properties to the `task` schema in `lib/Settings/pipelinq_register.json` (same shape as the existing `clientId` / `requestId`; additive, no rename).
- [x] Surface follow-up tasks on LeadDetail via the FK-scoped related-collection primitive (`task`, FK `leadId`).
- [x] Seed one `followUpTask` with `leadId` set for dev verification.

## 3. FollowUpReminderJob

- [x] Create `lib/BackgroundJob/FollowUpReminderJob.php` (`TimedJob`, 900 s), registered in `appinfo/info.xml`.
- [x] Lead branch: iterate open leads with `assignee`; resolve the leaf via the DI container (`OCA\OpenRegister\Service\CalendarLinkService`, `method_exists` guards, mirror `AppointmentCalendarLeafProvider::resolveLeaf()`); notify for linked events with `dtstart` inside the look-ahead window (app-config `followup_reminder_window_hours`, default 24).
- [x] Task branch: notify assignees of open `followUpTask` records whose `deadline` falls inside the window (resolve `assigneeUserId` / `assigneeGroupId`).
- [x] Idempotency: `wasRecentlyNotified` / `markNotified` marker per event UID / task id, mirroring `CallbackOverdueJob`.
- [x] Fail closed: no-op with a single log line when openregister is absent or app-config `register` is `''` (never cast `''` to a register id — the `EmailMatchService::registerSlug()` guard convention).
- [x] Notifications deep-link to the owning lead/task detail route; subjects i18n nl/en.
- [x] Unit tests: window edges, dedupe marker, leaf-absent no-op, group-assignee resolution.

## 4. Timeline merge

- [x] Extend the timeline aggregation behind `ActivityTimeline` / `entityActivity#index` with a leaf-events source: read `getLinkedEvents` for the entity at request time, map to appointment-typed entries (past = completed, future = scheduled).
- [x] Deduplicate by event UID against entries from other sources; leaf failure omits the source, never fails the response.
- [x] Regression test: same-UID event contributed by two sources renders once.

## 5. Documentation and dependency

- [x] Document enabling `backfill_calendar_links` (openregister app-config) + the manual `occ background-job:execute 'OCA\OpenRegister\BackgroundJob\BackfillCalendarLinksJob'` path in the setup/admin docs for instances with pre-existing tagged events.
- [x] Cross-reference the companion change `calendar-leaf-inbound` (openregister) and keep pipelinq's product claims scoped to "leaf-created links live; inbound continuous sync after the companion lands".

## Acceptance Criteria

- ClientDetail shows the Meetings widget with the same leaf behaviour as Lead/Contact detail; no pipelinq-local calendar component was added.
- Lead assignees and follow-up-task assignees receive exactly one reminder per upcoming occurrence; job is a logged no-op without the leaf or register.
- Client timeline shows leaf-linked events read-through, deduplicated, with no new pipelinq persistence.
- `task` schema carries optional `leadId`/`contactId`; register JSON remains valid.
- No CalDAV client, calendar listener, or calendar link write exists in pipelinq (audit scenario passes).

## Quality reminders

- Respect the authoritative leaf-first block of `openspec/specs/email-calendar-sync/spec.md` (lines 411+); do not cite or revive the superseded pre-leaf block (lines 26–357, pipelinq-local `CalendarLink`/`CalDavBackend`).
- Use only SAFE placeholders in examples (nil UUID); no real client data.
- Fix pre-existing quality issues encountered in touched regions; keep all additions back-compatible.
- Composer `check:strict` and the hydra gates must pass on the touched PHP.

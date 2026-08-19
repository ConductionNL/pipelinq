## 1. ClientDetail calendar widget

- [ ] Add `{ "id": "client-calendar", "type": "integration", "integrationId": "calendar", "title": "Meetings", "icon": "Calendar" }` to ClientDetail `config.widgets` in `src/manifest.json` (same shape as `lead-calendar` / `contact-calendar`).
- [ ] Add the ClientDetail layout row for `client-calendar` (8-wide, under the comms row next to `client-email`) and shift subsequent `gridY` values accordingly.
- [ ] Verify `src/manifest.json` still validates against the manifest schema and the page renders with the widget in the integration-unavailable state when the Calendar app is off.

## 2. Task schema follow-up attachment

- [ ] Add optional `leadId` and `contactId` properties to the `task` schema in `lib/Settings/pipelinq_register.json` (same shape as the existing `clientId` / `requestId`; additive, no rename).
- [ ] Surface follow-up tasks on LeadDetail via the FK-scoped related-collection primitive (`task`, FK `leadId`).
- [ ] Seed one `followUpTask` with `leadId` set for dev verification.

## 3. FollowUpReminderJob

- [ ] Create `lib/BackgroundJob/FollowUpReminderJob.php` (`TimedJob`, 900 s), registered in `appinfo/info.xml`.
- [ ] Lead branch: iterate open leads with `assignee`; resolve the leaf via the DI container (`OCA\OpenRegister\Service\CalendarLinkService`, `method_exists` guards, mirror `AppointmentCalendarLeafProvider::resolveLeaf()`); notify for linked events with `dtstart` inside the look-ahead window (app-config `followup_reminder_window_hours`, default 24).
- [ ] Task branch: notify assignees of open `followUpTask` records whose `deadline` falls inside the window (resolve `assigneeUserId` / `assigneeGroupId`).
- [ ] Idempotency: `wasRecentlyNotified` / `markNotified` marker per event UID / task id, mirroring `CallbackOverdueJob`.
- [ ] Fail closed: no-op with a single log line when openregister is absent or app-config `register` is `''` (never cast `''` to a register id — the `EmailMatchService::registerSlug()` guard convention).
- [ ] Notifications deep-link to the owning lead/task detail route; subjects i18n nl/en.
- [ ] Unit tests: window edges, dedupe marker, leaf-absent no-op, group-assignee resolution.

## 4. Timeline merge

- [ ] Extend the timeline aggregation behind `ActivityTimeline` / `entityActivity#index` with a leaf-events source: read `getLinkedEvents` for the entity at request time, map to appointment-typed entries (past = completed, future = scheduled).
- [ ] Deduplicate by event UID against entries from other sources; leaf failure omits the source, never fails the response.
- [ ] Regression test: same-UID event contributed by two sources renders once.

## 5. Documentation and dependency

- [ ] Document enabling `backfill_calendar_links` (openregister app-config) + the manual `occ background-job:execute 'OCA\OpenRegister\BackgroundJob\BackfillCalendarLinksJob'` path in the setup/admin docs for instances with pre-existing tagged events.
- [ ] Cross-reference the companion change `calendar-leaf-inbound` (openregister) and keep pipelinq's product claims scoped to "leaf-created links live; inbound continuous sync after the companion lands".

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

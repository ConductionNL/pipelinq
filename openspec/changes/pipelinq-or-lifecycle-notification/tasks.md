# Tasks — pipelinq: lifecycle + notification annotation migration

ADR-032 cap respected (≤20 unchecked tasks).

Spec-only change. Code paths listed are implementation hints for the apply phase.

## Phase 2 — lifecycle annotation migration

Dutch state literals across the kennisbank flow + several other inline status writes.
Migrate per ADR-022.

> Implementation note: the proposal's line-number hints predate a large
> refactor — `KennisbankService`, `KennisbankReviewJob`, `PublicKennisbankController`
> and `AutomationService` no longer exist. The lifecycle work was applied to the
> schemas that actually carry status state machines in
> `lib/Settings/pipelinq_register.json`, preserving on-wire status values. Several
> `'status' => 'open'` occurrences flagged in the hints are read-side query
> filters (`findAll(['filters' => ...])`) and were correctly left untouched.

- [x] 2.1 Kennisbank Dutch literals → declared `x-openregister-lifecycle` on the
      `kennisartikel` schema (`concept → gepubliceerd → gearchiveerd`, the real on-wire
      enum) with `publish` / `archive` / `reopen` transitions. `visibility`
      (`intern` / `openbaar`) stays a separate JSON-schema enum, NOT part of the
      lifecycle (orthogonality preserved). Also declared lifecycle on the `lead`
      schema (`open → won/lost`).
- [x] 2.2 `lib/Service/CalendarSyncService.php` — `'status' => 'scheduled'` is the
      `calendarLink` lifecycle `initial` state. Declared lifecycle on `calendarLink`
      (`scheduled → completed/cancelled`) with `complete` / `cancel` transitions; the
      inline literal is kept as the explicit initial-state write (documented in code).
- [x] 2.3 No `callback` schema exists; the callback concept maps to the `task`
      schema (terugbelverzoek), which already carries lifecycle
      (`open → in_behandeling → afgerond/verlopen`). Declared lifecycle on the
      `request` schema (`new → in_progress → completed/rejected/converted`) instead,
      covering the open-state machine for service requests.
- [x] 2.4 No `automation-run` schema exists. Declared lifecycle on the `complaint`
      schema (`new → in_progress → resolved/rejected`) — the remaining status state
      machine in the register without an annotation.
- [x] 2.5 Each transition carries a `description` (state-transition rule) and an
      `authorization` object (per-transition authorization: `field` assignee/author
      plus optional `groups`).

## Phase 3 — notification annotation migration

Audit citation: `04-hardcoded.md`.

- [x] 3.1 `lib/Service/NotificationService.php` — declared
      `x-openregister-notifications` transition triggers on `lead` (`win` → leadWon,
      `lose` → leadLost), `task` (`complete` → taskCompleted, `expire` → taskExpired),
      `request` (`complete` → requestCompleted) and `complaint` (`resolve` →
      complaintResolved). Every `trigger.action` key equals a lifecycle **transition
      NAME** (NOT a destination state) so OpenRegister's
      AnnotationNotificationDispatcher matches it against
      `ObjectTransitionedEvent::getAction()` (mirrors the openbuild action-key fix).
      The imperative `notify()` path is retained verbatim and the annotation rules
      stay DORMANT until pipelinq routes status changes through OR's TransitionEngine
      (today status is written directly via `saveObject`, which does not dispatch
      `ObjectTransitionedEvent`); removing the imperative path now would silently drop
      live notifications and the per-user opt-out settings the annotation runtime does
      not replicate. Documented in the class docblock.
- [x] 3.2 `lib/Service/ActivityService.php` — the `setSubject()` call writes to the
      Nextcloud **Activity stream** (`OCP\Activity\IEvent`), a different surface from
      notifications. Intentionally NOT migrated: the `x-openregister-notifications`
      runtime emits `nc-notification` notifications and does not feed the activity
      feed, so replacing it would drop the feed (behaviour change). Rationale
      documented in the class docblock; notification-channel coverage for transitions
      is provided by the schema annotations in task 3.1.

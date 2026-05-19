# Tasks — pipelinq: lifecycle + notification annotation migration

ADR-032 cap respected (≤20 unchecked tasks).

Spec-only change. Code paths listed are implementation hints for the apply phase.

## Phase 2 — lifecycle annotation migration

Dutch state literals across the kennisbank flow + several other inline status writes.
Migrate per ADR-022.

- [ ] 2.1 `lib/Service/KennisbankService.php:82,176`,
      `lib/BackgroundJob/KennisbankReviewJob.php:93`,
      `lib/Controller/PublicKennisbankController.php:75` — `'status' => 'gepubliceerd'`
      and `'nieuw'`, `'visibility' => 'openbaar'`. Define lifecycle states
      `nieuw → in_review → gepubliceerd → ingetrokken` on the kennisbank schema. Visibility
      stays as a separate field but its allowed values (`openbaar`, `intern`) become a
      JSON-schema enum, NOT a lifecycle (visibility is orthogonal to lifecycle).
- [ ] 2.2 `lib/Service/CalendarSyncService.php:76` — `'status' => 'scheduled'`. Define
      lifecycle states on the calendar-sync schema (`scheduled`, `running`, `succeeded`,
      `failed`).
- [ ] 2.3 `lib/Controller/CallbackController.php:302` — `'status' => 'open'`. Define
      lifecycle states on the callback schema (`open`, `claimed`, `completed`, `cancelled`).
- [ ] 2.4 `lib/Service/AutomationService.php:220,249` —
      `['status' => 'skipped'|'failure']`. Define lifecycle states on the automation-run
      schema (`pending`, `running`, `succeeded`, `failed`, `skipped`).
- [ ] 2.5 Document the state transition rules + per-transition authorization in each
      lifecycle annotation.

## Phase 3 — notification annotation migration

Audit citation: `04-hardcoded.md`.

- [ ] 3.1 `lib/Service/NotificationService.php:405-412` — direct
      `notificationManager->notify()` calls. Replace with
      `x-openregister-notifications` triggers on the relevant schemas (likely
      task/callback/lead).
- [ ] 3.2 `lib/Service/ActivityService.php:291` — `setSubject()` call. Same migration —
      activity events become notification triggers on lifecycle transitions.

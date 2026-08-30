# pipelinq: lifecycle + notification annotation migration

## Why

Split from the parent `pipelinq-adopt-or-abstractions` change per ADR-032
(spec-sizing cap: ≤20 unchecked tasks per change). See
`openspec/changes/archive/2026-05-18-pipelinq-adopt-or-abstractions-split/`
for the original bundled proposal and design.

This slice covers Phase 2 (lifecycle annotation migration of Dutch and English
state literals) and Phase 3 (notification annotation migration of direct
`notificationManager` calls). Both phases share the same migration shape
(inline write → annotation-driven) and pair naturally as one change.

## What Changes

### Lifecycle annotation migration (Phase 2)

1. Migrate Dutch state literals (`gepubliceerd`, `nieuw`, `openbaar`) on the
   kennisbank schema to `x-openregister-lifecycle`. Visibility stays as a
   separate JSON-schema enum (it is orthogonal to lifecycle).
2. Migrate `'status' => 'scheduled'` (calendar-sync), `'open'` (callback),
   `'skipped'` / `'failure'` (automation-run) inline writes to lifecycle
   transitions.
3. Document state transition rules + per-transition authorization in each
   lifecycle annotation.

### Notification annotation migration (Phase 3)

4. `NotificationService:405-412` and `ActivityService:291` — direct notification
   calls replaced with `x-openregister-notifications` annotations on the
   relevant schemas.

## Affected Projects

- pipelinq (consumer)
- openregister (must ship ADR-022 lifecycle + ADR-025 notification annotation
  runtime)

## Impact

- Affected code (apply-phase hints, NOT changed here):
  `lib/Service/KennisbankService.php`,
  `lib/BackgroundJob/KennisbankReviewJob.php`,
  `lib/Controller/PublicKennisbankController.php`,
  `lib/Service/CalendarSyncService.php`,
  `lib/Controller/CallbackController.php`,
  `lib/Service/AutomationService.php`,
  `lib/Service/NotificationService.php`,
  `lib/Service/ActivityService.php`.
- Affected specs: new `pipelinq-or-adoption` capability slice (delta only).
- Breaking changes: none — on-wire status values preserved (literal is the
  lifecycle-state name).

## See Also

- `openspec/changes/archive/2026-05-18-pipelinq-adopt-or-abstractions-split/design.md`
  (Decision 2: visibility orthogonal to lifecycle).
- `hydra/openspec/architecture/ADR-022.md` (lifecycle).
- `hydra/openspec/architecture/ADR-025.md` (notifications).

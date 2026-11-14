# Tasks: round5-task-name-backfill

## 1. Silent path

- [x] 1.1 `lib/Service/ObjectEventSilence.php`: says whether OpenRegister can withhold the object update event (it ships `SystemOperationContext` and `MagicMapper::suppressLifecycleEvents()`), and whether the system scope is active right now

## 2. Backfill

- [x] 2.1 `lib/Service/TaskNameBackfillService.php`: inside `runAsSystem()`, reads every task, saves each one whose name is empty or its uuid and whose subject is set, with `silent: true`; checks the scope before every write; writes nothing when the silence cannot be proven
- [x] 2.2 `lib/Repair/BackfillTaskNames.php`: the repair step, reporting named, skipped and failed counts or why it wrote nothing
- [x] 2.3 `appinfo/info.xml`: register the step in post-migration after `InitializeSettings`, which imports `97-task-name.json`
- [x] 2.4 `tests/Unit/Service/TaskNameBackfillServiceTest.php`: a nameless task gets its subject; a named task is untouched; every write is inside the scope and silent; no silence means no write; a second run writes nothing
  - Verify: fails on the old code (the class does not exist)

## 3. Verification

- [x] 3.1 Live on :8099: the three demo tasks still named after their uuid. Control: the same save without the system scope on one of them wrote an activity row and queued an `AnnotationNotificationDispatchJob` (trigger `updated`). The step on the other two: names filled, no activity row, no notification job, no notification row; three tasks that already had a name kept their version. A second run named nothing
- [x] 3.2 Full checks once: check:strict, vitest, lint, format, test:l10n

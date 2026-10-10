# Tasks: round5-task-name-backfill

## 1. Silent path

- [ ] 1.1 `lib/Service/ObjectEventSilence.php`: says whether OpenRegister can withhold the object update event (it ships `SystemOperationContext` and `MagicMapper::suppressLifecycleEvents()`), and whether the system scope is active right now

## 2. Backfill

- [ ] 2.1 `lib/Service/TaskNameBackfillService.php`: inside `runAsSystem()`, reads every task, saves each one whose name is empty or its uuid and whose subject is set, with `silent: true`; checks the scope before every write; writes nothing when the silence cannot be proven
- [ ] 2.2 `lib/Repair/BackfillTaskNames.php`: the repair step, reporting named, skipped and failed counts or why it wrote nothing
- [ ] 2.3 `appinfo/info.xml`: register the step in post-migration after `InitializeSettings`, which imports `97-task-name.json`
- [ ] 2.4 `tests/Unit/Service/TaskNameBackfillServiceTest.php`: a nameless task gets its subject; a named task is untouched; every write is inside the scope and silent; no silence means no write; a second run writes nothing
  - Verify: fails on the old code (the class does not exist)

## 3. Verification

- [ ] 3.1 Live on :8099: two tasks assigned to a user with their name reset to the uuid, plus the demo tasks; run the step; names filled; no new notification for the assignee or admin; no new activity row; second run names nothing
- [ ] 3.2 Full checks once: check:strict, vitest, lint, format, test:l10n

# Proposal: round5-task-name-backfill

kind: fix. Round 5 of the pipelinq review (cloud check of 10 October 2026), lane R5-PQ-TASKNAME. Follows round5-contact-create-and-task-links (pipelinq#2376).

## Summary

pipelinq#2376 named a task after its subject (`crmTask.configuration.objectNameField = subject`). OpenRegister computes the name on save, so a task written before that fragment keeps its uuid as its name until somebody edits it. The activity stream and the task page keep showing the uuid for every old task.

Ruben decided on 10 October: fill in the name for existing tasks now, once, and do not tell anyone. The assignee must not get a "Task changed" notification and no activity entry may appear, because nothing about the task changed for them.

A repair step, `BackfillTaskNames`, runs after the register import on upgrade. It saves every task whose name is empty or still its uuid, and lets OpenRegister compute the name from the subject. A task that already has a name is not saved. A second run finds nothing to do.

## How the save stays silent

OpenRegister has one switch that withholds object events: `SystemOperationContext`. `MagicMapper::suppressLifecycleEvents()` skips the `ObjectUpdatedEvent` dispatch for every write made inside `SystemOperationContext::run()`, which `ObjectService::runAsSystem()` enters. Every listener that tells people about a change hangs off that event: OpenRegister's notification rules (`AnnotationNotificationListener`, which sends "Task changed"), its activity publisher (`ActivityEventListener`), and pipelinq's own update listeners. Withholding the dispatch withholds all of them at once.

`saveObject(silent: true)` is NOT that switch. Despite the contract's docblock ("Suppress events for this save"), `$silent` only skips the audit trail row and the inverse-relation update; the event is still dispatched. This step passes `silent: true` as well, so no audit trail row says the task changed, but the silence comes from the system scope.

The step refuses to write unless it can prove the silence:

- before the run, OpenRegister must ship `SystemOperationContext` and the event gate in `MagicMapper`. An older OpenRegister would dispatch the event, so the step writes nothing and reports why; the next upgrade tries again.
- at every write, the system scope must be active. If it is not, the step stops before the write.

## Flags passed to OpenRegister

- `findAll(_rbac: false, _multitenancy: false)`: read every task in the register, not only the ones an anonymous CLI caller may see, and across organisations. Without them the step would name a fraction of the tasks and report success.
- `saveObject(_rbac: false, _multitenancy: false)`: write the task back without a per-user permission or organisation check. The step runs only from `occ upgrade` (a repair step); no route, controller or public path reaches it.
- `saveObject(silent: true)`: no audit trail row and no inverse-relation pass.
- `saveObject(currentUser: <first admin>)`: OpenRegister's object folder check needs a user to authorise; with no session it denies a task that has a folder. Same as `NormaliseTicketTitle`.

## Side effects that remain

The save bumps the task's `version` and `updated` timestamp, like any save. Caches that listen for the event (facets, aggregations) are not invalidated by it; `saveObject` still invalidates the collection cache itself.

## Not changed

- No `occ` command. The repair step runs on the upgrade that ships it, which is the "once" Ruben asked for. Re-running it later is harmless.
- No database writes outside OpenRegister's save path.

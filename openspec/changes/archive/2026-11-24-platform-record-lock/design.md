# Design: platform-record-lock

## Context (read at pipelinq development cfe0a0a51, OpenRegister development 555af7212, nextcloud-vue 2.57.1)

- **What freezes today, per type.** `lib/Service/ContractService.php:159-163`
  refuses any transition out of a terminal contract state.
  `lib/Lifecycle/PosTransactionConfirmGuard.php` guards the posTransaction
  `confirm` transition from the schema's `x-openregister-lifecycle`. Neither is a
  lock a person puts on a record.
- **OpenRegister's `objects#lock` is an edit session lock, not this.**
  `appinfo/routes.php:1246` routes `POST .../{id}/lock`.
  `ObjectEntity::lock()` (`lib/Db/ObjectEntity.php:1681`) records a holder, and
  `ObjectsController::releaseOwnLockAfterWrite()` (around :3494) releases it on
  the holder's own save. The holder can still edit, and
  `lib/Service/Object/DeleteObject.php` never checks it. The matrix is right
  that this lock does not answer the row.
- **OpenRegister has a freeze, and the matrix does not mention it.**
  `appinfo/routes.php:1270-1271` routes `objectState#freeze` (`POST
  .../{id}/freeze`) and `objectState#unfreeze` (`DELETE .../{id}/freeze`).
  `lib/Service/Object/ArchiveHandler.php:203` writes the `@self.frozen` marker
  (`by`, `at`, `reason`, `state`) and an audit entry. It refuses a caller
  without `update` on the object, and refuses a schema whose
  `configuration["x-openregister-archive"].enabled` is not true
  (`Schema::isArchivingEnabled()`, `lib/Db/Schema.php:3808`).
  `lib/Service/Object/SaveObject.php:3712` refuses every data write to a frozen
  object with `ObjectStateWriteException::frozen()`, whose message names who
  froze it and when. A frozen object stays in lists and search
  (OpenRegister change `object-archive-state`, REQ-OAS-004, tasks C29.1 and C29.7
  ticked).
- **The freeze does not refuse a delete.** `git grep -i frozen` over
  OpenRegister `lib/Service/Object/DeleteObject.php` and
  `lib/Controller/ObjectsController.php` finds nothing. OpenRegister already stops
  deletes by guard listeners on the stoppable `ObjectDeletingEvent`
  (`lib/AppInfo/Application.php:3359` and :3378 register two).
- **pipelinq schemas.** In `lib/Settings/pipelinq_register.json` the `client`
  schema (:59) carries only `x-openregister-mcp` in its configuration; `lead`
  (:277) carries a lifecycle with `final: ["won", "lost"]` (:396) and a
  calculations block. The `ticket` schema
  (`lib/Settings/register.d/99-unify-ticket-supertype.json:4`) carries a
  lifecycle with `final: ["completed", "converted", "resolved", "rejected",
  "closed"]` (:75) and an archival block. None declares `x-openregister-archive`.
- **Detail pages.** `src/manifest.json` declares ClientDetail (:1591),
  TicketDetail (:2232) and LeadDetail (:2715) as `type: detail`. None sets
  `headerActions`.
- **What nextcloud-vue 2.57.1 offers.** `CnDetailPage.vue:1567` takes
  `headerActions`, rendered in the Actions menu with an optional `visibleWhen`
  that can read a dot path on the loaded record (`utils/visibleWhen.js`, local
  mode; ops include `empty` and `notEmpty`). An `api-call` action speaks only
  POST and PUT (`utils/actionsDispatcher.js:383`), so it cannot send the DELETE
  that unfreezes. `confirm: true` gates an action behind `CnConfirmDialog`
  (`CnActionButtons.vue:152`). A named `handler` resolves from the app registry
  (`resolveRegisteredHandler`, `actionsDispatcher.js`).
- **Calculations.** OpenRegister's `CalculationEvaluator` supports `or` and `eq`
  (`lib/Service/Calculation/CalculationEvaluator.php`), and pipelinq already
  declares non-materialised calculations on the lead.

## Decisions

### D1. The lock is OpenRegister's freeze

pipelinq adds no lock field, no guard and no controller. Lock calls `POST
/apps/openregister/api/objects/pipelinq/{schema}/{id}/freeze`; Unlock calls
`DELETE` on the same address. OpenRegister keeps the marker, writes the audit
entry and refuses the edit. This is ADR-022: one freeze for the whole fleet,
consumed here. The temporary `objects#lock` stays what it is, an edit session
lock.

### D2. The schemas switch the verbs on declaratively

Each of `client`, `lead` and `ticket` gets `"x-openregister-archive":
{"enabled": true}` in its `configuration`. That is ADR-031: the schema decides
that its objects can be frozen, and no pipelinq code decides it.

### D3. Lock waits for a finished lead or ticket

A lock on an open ticket would refuse the SLA engine's own writes
(`lib/BackgroundJob/SlaDeadlineSweepJob.php:446` saves the ticket). So Lock
shows on a lead or ticket only when it is finished. Each of the two schemas
gets a non-materialised calculation `isFinished`, an `or` of `eq` tests over
its lifecycle's `final` list. The Lock action's `visibleWhen` reads it. A
client has no lifecycle, so Lock shows on every client.

### D4. Unlock is one registered handler

Because `api-call` cannot send DELETE in 2.57.1, `src/registry.js` gets one
`kind: 'handler'` entry, `unlockRecord`, which sends the DELETE with the page's
register, schema and object id, shows a toast and refreshes the page. Lock stays
a plain `api-call` with `confirm: true` and an optional reason in its payload.
When nextcloud-vue's api-call learns DELETE, the handler can go.

### D5. Refusing a delete is OpenRegister's rule

A locked record must not be deleted by accident. That rule belongs next to the
freeze, in OpenRegister, as a guard on `ObjectDeletingEvent` that refuses a
frozen object. pipelinq does not copy it into its own listener. Until
OpenRegister ships it, the delete scenario in this change's spec fails, and the
PR that lands the pipelinq half says so.

### D6. Who may lock and unlock

Anyone with `update` on the record, which is OpenRegister's rule for all four
state verbs (`ArchiveHandler`, ADR-010 in OpenRegister). No new role. The audit
trail records who did it.

## Risks

- `x-openregister-archive` also switches on Archive for these schemas over the
  API. pipelinq shows no Archive action, but a caller with `update` can archive
  a client through OpenRegister, which hides it from lists. The PR notes it; a
  separate freeze switch is an OpenRegister question.
- Background writes to a locked client (for example the contact sync writing
  the denormalised name and email) are refused. Task 1.3 lists every
  `saveObject` on client, lead and ticket under `lib/` and decides per path:
  skip a frozen record, or log and move on.
- A lock does not stop retention. OpenRegister writes destruction dates and
  legal holds outside `saveObject()` on purpose, so a locked contact moment
  still expires when its term ends.

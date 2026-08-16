---
status: done
---

# event-listener-work-placement Specification

## Purpose
Places the work pipelinq's OpenRegister object-lifecycle listeners do according to what the event can still influence (ADR-078). Pre-`*ing` events may veto or mutate and stay synchronous; post-`*ed` events cannot change the write they observe, so their work is queued onto an actor-forwarded background job instead of being charged to the user's request. Covers the shared job, the handler allow-list, the acting-user contract, the reconcile-against-current-state rule, and the process-wide guard that stops a listener's own write from re-entering it and re-queuing itself for ever.

@e2e exclude backend work placement — a listener queueing instead of writing has no browser surface; the observable behaviour (queued entry, job effect, no request-path write, no re-queue loop) is asserted by PHPUnit in tests/Unit/Listener and tests/Integration/ExpenseApSyncTest.php

## Requirements

### Requirement: Deferred post-event work runs in one actor-forwarded job

Every pipelinq listener registered on `ObjectCreatedEvent` or `ObjectUpdatedEvent`
that performs outbound I/O, a write, or an unbounded query MUST implement
`OCA\Pipelinq\Listener\DeferredObjectWork` and MUST NOT do that work inside
`handle()`.

`handle()` MAY only: reject events of the wrong type, resolve the entity's
schema through `SchemaMapService`, evaluate short-circuits that are answerable
from the event payload alone, and call
`ListenerDeferralService::defer()` with
`DeferredObjectListenerJob::class` and an entry carrying at minimum
`handler` (the listener's `HANDLER_KEY`) and `uuid`. An entry MAY carry
additional scalars that a later read cannot reconstruct — the previous object
state for a compensating correction, or the old/new status pair a ledger event
must report. It MUST NOT carry the object body as the source of truth.

`DeferredObjectListenerJob::runDeferred(DeferredListenerContext $context): void`
MUST, for each entry:

- resolve `handler` against a **hardcoded allow-list** of the app's own
  listeners and log-and-skip an unknown key. A class name taken from a persisted
  job row and passed to the container would be an instantiate-anything
  primitive;
- log-and-skip when the resolved service does not implement
  `DeferredObjectWork`;
- claim `DeferredWorkGuard::key(handler, uuid)` and skip the entry entirely when
  the claim fails;
- call `runDeferredWork($entry)` and release the claim in a `finally`;
- catch `Throwable` per entry, log it, and continue — the same blast radius the
  inline listeners had, and never a rethrow into cron.

The job extends `OCA\OpenRegister\BackgroundJob\ActorForwardedJob`, so the user
who performed the write is re-established for the duration of the work
(ADR-078 Rule 6) and the job is a one-shot `QueuedJob` that is removed from the
job list once it has run.

#### Scenario: an approval queues instead of dispatching

- **GIVEN** an expense whose `status` becomes `approved` and whose AP webhook is configured
- **WHEN** `ExpenseApprovalListener::handle()` runs
- **THEN** exactly one entry is deferred to `DeferredObjectListenerJob`
- **AND** no domain event is emitted, no object is written and no outbound webhook is sent during the request

#### Scenario: the queued entry does the work

- **WHEN** the job runs that entry
- **THEN** the domain event is emitted, `apSyncStatus` goes `pending` then `synced`, and exactly one CloudEvent envelope is dispatched

#### Scenario: an unknown handler key is dropped, not resolved

- **GIVEN** a job entry whose `handler` is not in the allow-list
- **WHEN** the job runs
- **THEN** a warning is logged and no service is resolved from the container

### Requirement: Deferred work reconciles against current state

`runDeferredWork()` MUST re-read the object it acts on rather than trusting the
payload captured at dispatch time. Delivery is at-least-once and ordering
against the write is not guaranteed (ADR-078 Rule 7).

An object that no longer resolves MUST be treated as a stale no-op — logged at
most, never an error. Every condition that decided to queue the work
(status still approved, sync status not already `synced`, integration still
configured, SLA envelope still unarmed) MUST be re-evaluated against the re-read
state before acting.

Where the deferred work is a **compensating correction** rather than a
projection — `DealUpdatedListener` reverting a rejected `forecast_category`
transition — the decision MUST be re-taken, not replayed. Replaying a captured
revert would overwrite a value someone has since corrected.

#### Scenario: a deleted object is a stale no-op

- **GIVEN** a queued entry whose object has been deleted before the job runs
- **WHEN** the job runs
- **THEN** nothing is written, nothing is dispatched, and the job completes normally

#### Scenario: a rejected transition already fixed is left alone

- **GIVEN** a deal whose invalid `forecast_category` has been corrected by another path since the update
- **WHEN** the deferred correction runs
- **THEN** `validateTransition()` re-run against the CURRENT value returns no error and no write is made

### Requirement: A listener's own write must not re-queue it

`DeferredWorkGuard` holds a process-wide claim on `<handler>|<uuid>` for the
duration of the deferred work. Every converted listener MUST test
`DeferredWorkGuard::isRunning()` for its own `(HANDLER_KEY, uuid)` pair before
calling `defer()`, and MUST return without deferring when the claim is held.

This is load-bearing, not defensive. `ObjectService::saveObject()` causes
`MagicMapper::update()` to dispatch `ObjectUpdatedEvent`, which re-enters the
same listeners with an object that satisfies their entry conditions —
`SlaObjectUpdatedListener` writes `slaStatus.lastEvaluatedAt = now` on every
pass and has no idempotency check that could stop it, and
`ExpenseApprovalListener`'s `apSyncStatus === 'synced'` check cannot stop it
because the re-entry sees the `pending` value the deferred pass has just
written. Inline, that recursed on one request's stack. Without the guard, the
deferred form enqueues a fresh job on every turn, and since `cron.php` runs one
job per web call, that job starves every other job on the instance.

`leave()` MUST be called from a `finally`. The claim is deliberately static:
Nextcloud resolves a listener from the container per dispatch, so a re-entrant
dispatch is not guaranteed to reach the same instance, and the process context
is torn down per request and per cron job.

#### Scenario: the deferred write re-enters the listener exactly once and stops

- **GIVEN** an approved expense and an object service that dispatches `ObjectUpdatedEvent` for every write, as the mapper does
- **WHEN** the job runs the deferred AP dispatch
- **THEN** the outbound AP webhook is sent exactly once
- **AND** the terminating `synced` write is reached
- **AND** no further entry is queued

# Proposal: retire-mdm-sync-queue

kind: refactor/consolidation — remove pipelinq's app-side MDM outbound sync-queue subsystem and defer queueing, delivery, and retry entirely to OpenRegister's `WebhookService`. This is the exact anti-pattern ADR-045 forbids ("no parallel queue subsystem"), still shipping.

## Problem

Pipelinq carries a complete app-side outbound queue even though dispatch is **already delegated** to OR:

- `lib/Service/Mdm/SyncQueueService.php` (429 LOC) — an app-side queue store over `syncQueueItem` objects (schema in `lib/Settings/register.d/90-master-data-management.json`), with its own enqueue/process/retry bookkeeping. Its `dispatch()` (line ~299) hands every item to `OCA\OpenRegister\Service\WebhookService::dispatchEvent(...)` — and since that OR method **returns void** (verified on origin/development), the "acknowledgment reference" pipelinq records is always the synthetic fallback `pipelinq-mdm-{id}`. The app-side queue adds bookkeeping, not delivery guarantees.
- Three background jobs polling that store: `MdmSyncQueueProcessorJob` (drains the queue), `MdmOpenRegisterSyncJob` (polls golden records into `OpenRegisterSyncService::syncMasterToRegister`), `MdmHardDeleteConfirmationJob` (confirms hard-deletes off queue acks via `Mdm\AVGWorkflowService`).
- `MdmApiController` (`mdmApi#queryByNaturalKey` GET `/api/mdm/master`, `mdmApi#show` GET `/api/mdm/master/{id}`) — a read-API wrapper for downstream apps, contrary to ADR-022/ADR-045: downstream apps consume OR directly (MDM steward surfaces already moved to OR per ADR-045 #D).
- `lib/Listener/ObjectsMergedSyncListener.php` enqueues into the app-side store instead of dispatching.

OR's `WebhookService` (verified origin/development) already owns the whole delivery problem: `dispatchEvent()`, per-delivery webhook logs with request bodies stored on failure, `next_retry_at` scheduling, and a `WebhookRetryJob` cron every 5 minutes. Two queues means two retry semantics, double failure surfaces, and rows that can be marked "delivered" by a synthetic ack.

## Solution

1. **Delete the queue subsystem** — `SyncQueueService`, the three background jobs, `MdmApiController` + its routes, and the `syncQueueItem` schema from `90-master-data-management.json`.
2. **Dispatch at the source** — `ObjectsMergedSyncListener` (and any other enqueue caller) invokes OR `WebhookService::dispatchEvent` directly at merge/mutation time. Queueing, per-target delivery, failure logging, and retries are OR's job (webhook logs + `WebhookRetryJob`).
3. **Golden-record sync without a polling job** — `OpenRegisterSyncService::syncMasterToRegister` is called from the merge/mutation event path instead of the retired `MdmOpenRegisterSyncJob` poller (or deleted outright if the steward surfaces in OR make it redundant — decided by evidence during apply; both dispositions keep zero app-side queue rows).
4. **Hard-delete confirmation re-anchored** — `MdmHardDeleteConfirmationJob` dies with the queue. Downstream deletion confirmation becomes an OR webhook-delivery outcome (webhook log status), not an app-side ack row. Coordination note: the job constructor-injects `Mdm\AVGWorkflowService`, which `consume-or-dsar` deletes — see design.md §Ordering.
5. **Drain migration** — a repair step processes in-flight `syncQueueItem` rows once through `WebhookService::dispatchEvent` (pending/failed rows), records a drain summary, and only then the schema is removed.

## Scope

- Deletions: `lib/Service/Mdm/SyncQueueService.php`, `lib/BackgroundJob/MdmSyncQueueProcessorJob.php`, `lib/BackgroundJob/MdmOpenRegisterSyncJob.php`, `lib/BackgroundJob/MdmHardDeleteConfirmationJob.php`, `lib/Controller/MdmApiController.php`, `mdmApi#*` routes, `syncQueueItem` schema, job/DI registrations, related tests
- Edits: `lib/Listener/ObjectsMergedSyncListener.php` (direct dispatch), `lib/Service/Mdm/OpenRegisterSyncService.php` (event-driven call path or deletion)
- New: `lib/Repair/DrainMdmSyncQueue.php`

**Depends on:** OR `WebhookService` + `WebhookRetryJob` (origin/development), ADR-045, ADR-022.

## Out of Scope

- The MDM AVG right-of-deletion workflow (`MdmAvgWorkflowController` / `AVGWorkflowService`) — retired by `consume-or-dsar` (ADR-047); this change only sequences around it
- Duplicate detection / merge tooling / trust config — already moved to OR (ADR-045 #D)
- Any OR-side change; if a consumer genuinely needs a delivery-ack API, that is an OR WebhookService delta, not an app queue

## Success Criteria

- `lib/Service/Mdm/SyncQueueService.php` and the three jobs no longer exist; `/api/mdm/master*` routes are gone
- A golden-record merge produces an OR webhook dispatch (visible in OR's webhook logs) with no `syncQueueItem` row created anywhere
- A failed downstream delivery is retried by OR's `WebhookRetryJob` — pipelinq contains zero retry code
- Pre-existing pending/failed queue rows are drained exactly once by the repair step, with a logged summary; the `syncQueueItem` schema is removed afterwards
- `composer check:strict` green; no orphaned DI wiring, job registrations, or tests

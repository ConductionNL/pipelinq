# Design: retire-mdm-sync-queue

## Context

ADR-045 moved MDM stewardship (golden records, duplicates, trust config, steward UI) to OpenRegister and forbids parallel subsystems in leaf apps. The outbound sync queue survived that sweep: pipelinq still persists `syncQueueItem` rows and runs three cron jobs around them, while the actual delivery already goes through OR's `WebhookService`. Verified against OR origin/development:

- `WebhookService::dispatchEvent(Event $_event, string $eventName, array $payload): void` — pipelinq's `SyncQueueService::dispatch()` assigns its (void) return and always falls back to the synthetic ack `pipelinq-mdm-{id}`. The recorded "acknowledgmentReference" has never been a real downstream ack.
- OR owns delivery reliability end-to-end: per-delivery webhook logs, request bodies stored on failure for replay, `next_retry_at` scheduling, and `WebhookRetryJob` (5-minute cron) — the exact semantics `SyncQueueService` re-implements app-side.

## Component dispositions

| pipelinq component | Today | After |
|---|---|---|
| `Mdm/SyncQueueService.php` (429 LOC) | Queue store + processQueue + synthetic acks; delegates dispatch to OR (line ~299) | **Deleted** — OR WebhookService is the queue |
| `MdmSyncQueueProcessorJob` | Cron drain of the app queue | **Deleted** |
| `MdmOpenRegisterSyncJob` | Polls masters → `OpenRegisterSyncService::syncMasterToRegister` | **Deleted**; sync invoked from the merge/mutation event path (or the service deleted if OR-side stewardship already covers it — evidence check during apply) |
| `MdmHardDeleteConfirmationJob` | Confirms hard-deletes from queue acks via `Mdm\AVGWorkflowService` | **Deleted**; confirmation = OR webhook-delivery outcome (webhook log status), no app-side ack row |
| `MdmApiController` (`/api/mdm/master`, `/api/mdm/master/{id}`) | Read-API wrapper for downstream apps | **Deleted** — downstream apps read OR directly (ADR-022 / redundant-controller gate) |
| `ObjectsMergedSyncListener` | `$this->syncQueue->enqueueSync(...)` on merge events | **Edited** — calls `WebhookService::dispatchEvent` directly (lazy container resolve, OR-absent safe) |
| `syncQueueItem` schema (`register.d/90-master-data-management.json`) | Queue row storage | **Removed** after drain |
| `Mdm/MdmObjectRepository`, remaining MDM read paths | Golden-record access | **Kept** (out of scope) |

## Drain plan (in-flight rows)

`lib/Repair/DrainMdmSyncQueue.php`, ordered strictly before schema removal:

1. Query all `syncQueueItem` rows with non-terminal status (pending / retrying / failed).
2. For each, dispatch once through `WebhookService::dispatchEvent` with the same `targetSystem`/`changeType`/`masterEntity`/`payload` envelope `SyncQueueService::dispatch()` used — from here OR's webhook log + retry own the outcome.
3. Mark the row terminal (`drained`) with a timestamp; rows already terminal are skipped (idempotent re-run).
4. Log a summary (`drained`/`skipped` counts). Rows that fail to hand off remain non-terminal and the repair step reports them; the schema-removal task is gated on a clean drain.
5. Terminal rows are not migrated anywhere — they were bookkeeping; the audit story continues in OR's webhook logs.

Rollback: the repair step never deletes rows; re-enabling the previous app version restores the processor job over the same data until the schema removal ships.

## Ordering with consume-or-dsar

`MdmHardDeleteConfirmationJob` constructor-injects `Mdm\AVGWorkflowService`; `consume-or-dsar` deletes that service. Whichever change applies first removes its own files; the second must confirm no dangling reference: if this change lands first, the job (the only queue-side consumer) is already gone and `consume-or-dsar` deletes `AVGWorkflowService` freely; if `consume-or-dsar` lands first, this change's job deletion is the unblock. Neither change may stub the dependency.

## Decisions

1. **Direct dispatch over app-side queue** — an app queue in front of a queueing service is double-buffering with two failure vocabularies. One write path (feedback: long-term decisions favor unification).
2. **Synthetic acks are dropped, not replicated** — the ack field never carried a downstream confirmation (dispatchEvent returns void). If real delivery acks become a requirement, that is an OR WebhookService delta (expose delivery/log status), not app code.
3. **No replacement read-API** — `MdmApiController` duplicated OR object reads (ADR-022; hydra redundant-controller gate). Consumers use OR's `/api/objects` surface.
4. **Listener resolves WebhookService lazily** — same container pattern as other OR touchpoints, so pipelinq still boots without OR; merge events then log-and-skip dispatch.

## Risks / Trade-offs

- **Loss of the in-app queue view** — stewards inspected queue rows in pipelinq before ADR-045 #D moved steward UI to OR; delivery visibility now lives in OR's webhook logs. Documented in the MDM feature doc.
- **Burst dispatch on merge storms** — the app queue smoothed bursts by cron-draining; OR's dispatch is synchronous per event with its own retry backoff. Merge volume is steward-driven (low); acceptable.
- **Drain double-send** — a row that was mid-flight when the upgrade ran may be delivered twice (once by the old processor, once by the drain). Downstream consumers already had to be idempotent under the old retry semantics; unchanged assumption.

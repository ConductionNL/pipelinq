# Master Data Management — Retire App-Side Sync Queue Delta

**Spec refs**: ADR-045 (MDM → OpenRegister; no parallel queue subsystem), ADR-022 (apps consume OR abstractions), OR `WebhookService` + `WebhookRetryJob` (origin/development)
**Standards**: webhook delivery with durable logs and scheduled retries (OR-owned)

## MODIFIED Requirements

### Requirement: REQ-MDM-006 — Downstream Sync Queue with Retries and Confirmation

Downstream synchronization MUST be fulfilled entirely by OpenRegister's `WebhookService`: `ObjectsMergedSyncListener` dispatches merge/reversal payloads directly via `WebhookService::dispatchEvent` at event time, and queueing, per-target delivery logging, failure capture, and retry scheduling (`WebhookRetryJob`, `next_retry_at`) are OpenRegister's responsibility. Pipelinq MUST NOT persist queue rows, run drain jobs, or record acknowledgment references: `SyncQueueService`, `MdmSyncQueueProcessorJob`, `MdmOpenRegisterSyncJob`, `MdmHardDeleteConfirmationJob`, and the `syncQueueItem` schema are removed. This SUPERSEDES the earlier retention of the app-side queue — the retained store recorded only synthetic acknowledgments (`dispatchEvent` returns void) and duplicated OR's retry semantics. The listener MUST resolve `WebhookService` lazily and degrade to a logged no-op when OpenRegister is absent. Delivery confirmation is an OR webhook-log outcome, never an app-side ack row.

**Feature tier**: MVP
**Handoff**: Queueing, delivery, and retries — OpenRegister `WebhookService` + `WebhookRetryJob`.

#### Scenario: Merge dispatches directly through OR

- GIVEN a merge or reversal fires `ObjectsMergedEvent`
- WHEN `ObjectsMergedSyncListener` handles the event
- THEN it MUST call `WebhookService::dispatchEvent` with the sync envelope (targetSystem, changeType, masterEntity, payload)
- AND no `syncQueueItem` object MUST be created anywhere

#### Scenario: Retry is OR's job

- GIVEN a downstream delivery that fails
- WHEN OR's `WebhookRetryJob` next runs
- THEN the retry MUST be driven by OR's webhook log (`next_retry_at`), with zero pipelinq retry code involved

#### Scenario: OR absent

- GIVEN a deployment without OpenRegister
- WHEN a merge event fires
- THEN the listener MUST log and skip dispatch without throwing

`@e2e exclude` backend dispatch path — asserted by the listener unit test (WebhookService dispatch mock + no-queue assertion); no UI surface exists.

### Requirement: REQ-MDM-010 — Read-API for Downstream Apps

Downstream apps MUST consume master-entity data directly from OpenRegister's object surface (`/apps/openregister/api/objects`, RBAC + multitenancy scoped) — pipelinq MUST NOT expose a read-API wrapper. `MdmApiController` and its `mdmApi#queryByNaturalKey` / `mdmApi#show` routes are removed. This SUPERSEDES the earlier read-projection requirement: the projection duplicated OR object reads (ADR-022; redundant-controller rule), and the `dataQualityScore` it projected is already materialised on the OR object as `qualityScore`. `MdmObjectRepository` remains only for pipelinq-internal reads.

**Feature tier**: MVP
**Handoff**: Downstream reads — OpenRegister object API directly.

#### Scenario: No pipelinq read-API routes remain

- GIVEN the pipelinq codebase after this change
- WHEN routes are enumerated
- THEN no `/api/mdm/master` or `/api/mdm/master/{id}` route MUST exist

#### Scenario: Downstream resolves via OR

- GIVEN a downstream caller needing a master entity by natural key
- WHEN it queries OpenRegister's object API with the masterEntity schema filter
- THEN it MUST receive the golden record including the OR-materialised `qualityScore`, without any pipelinq endpoint in the path

`@e2e exclude` machine-to-machine read path — asserted in Newman against the OR object API; no pipelinq UI surface.

### Requirement: REQ-MDM-011 — Sync Golden Record to OpenRegister

The system MUST keep the OpenRegister schema instances (contact, account, product, vendor) synchronized with the golden records, marking them with `masterEntityRef` — invoked from the merge/mutation event path (`ObjectsMergedSyncListener` → `OpenRegisterSyncService::syncMasterToRegister`) instead of a polling background job. `MdmOpenRegisterSyncJob` is removed; no periodic sweep re-reads masters. If evidence during apply shows OR-side stewardship (ADR-045 #D) already maintains these projections, `OpenRegisterSyncService` is deleted instead — either disposition leaves zero app-side queue or polling infrastructure.

**Feature tier**: MVP
**Handoff**: Event-driven sync; storage remains OpenRegister schemas as system of record.

#### Scenario: Golden record reflects in OR schema on the event path

- GIVEN a change to a Master Entity account's goldenRecord (phone updated)
- WHEN the merge/mutation event is handled
- THEN the corresponding OpenRegister `account` object MUST be updated with the new phone value and `masterEntityRef = <masterId>`
- AND no background polling job MUST be involved

#### Scenario: Pre-merge OR records marked as merged

- GIVEN two OR `account` objects are merged into one Master Entity
- WHEN the first OR object is marked as `isMasterRecord = false`
- THEN queries against the OpenRegister catalog still resolve correctly via masterEntityRef

`@e2e exclude` backend sync path — asserted by OpenRegisterSyncService/listener unit tests; steward UI lives in OR.

## ADDED Requirements

### Requirement: REQ-MDM-014 — One-Time Drain of In-Flight Queue Rows

A repair step MUST drain pre-existing non-terminal `syncQueueItem` rows exactly once: dispatch each through `WebhookService::dispatchEvent` with the original sync envelope, mark it terminal (`drained`), skip already-terminal rows on re-run, and log a drained/skipped summary. Rows whose hand-off fails MUST remain non-terminal and be reported; removal of the `syncQueueItem` schema MUST be gated on a clean drain. The repair step MUST NOT delete rows.

**Feature tier**: MVP

#### Scenario: Pending rows are drained once

- GIVEN three `syncQueueItem` rows with status pending and one already delivered
- WHEN the repair step runs
- THEN exactly three dispatches MUST go through `WebhookService::dispatchEvent`
- AND all three rows MUST be marked `drained`; the delivered row is skipped

#### Scenario: Idempotent re-run

- GIVEN a completed drain
- WHEN the repair step runs again
- THEN zero dispatches MUST occur and the summary MUST report all rows skipped

#### Scenario: Failed hand-off blocks schema removal

- GIVEN a row whose dispatch throws
- WHEN the drain completes
- THEN the row MUST remain non-terminal and be listed in the repair output
- AND the `syncQueueItem` schema MUST NOT be removed until a clean drain is achieved

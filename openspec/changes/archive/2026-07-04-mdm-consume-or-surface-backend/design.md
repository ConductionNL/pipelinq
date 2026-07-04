# Design — mdm-consume-or-surface-backend (ADR-045 #D, code)

## Context

This is the **backend code link** of the three-link chain (ADR-032) that migrates pipelinq to fully
consume OpenRegister's now-complete MDM surface. The head config link `mdm-consume-or-surface`
declared the `x-openregister-survivorship` / `x-openregister-merge` annotations and re-pointed dedup
to nested paths; this link deletes the app-side engine those annotations replace, re-homes the AVG
consumer, rewires the merge→sync trigger to an OR event, seeds the trust rows into OR, and finishes
the deferred schema cleanup. The sibling `mdm-consume-or-surface-frontend` removes the steward UI.

## Deletion ledger (disposition + why + callers handled)

| Artifact | Disposition | Replaced by (OR) | Callers → handling |
|---|---|---|---|
| `MasterEntityService` | **delete** | `SurvivorshipRecomputeListener` materialises golden record on save | AVGWorkflowService, MdmApiController, OpenRegisterSyncService, MdmOpenRegisterSyncJob → re-pointed to re-homed `MdmObjectRepository` read helpers; `MergeService` + `SourceRecordChangedListener` → deleted |
| `DuplicateDetectionService` | **delete** | OR nested-path `duplicate-detection` | `MdmMergeController` (deleted), `MdmDuplicateDetectionJob` (deleted) |
| `StringSimilarity` | **delete** | OR similarity calculator | only `DuplicateDetectionService` (deleted) |
| `DataQualityScorer` | **delete** | OR `mdm-quality-api` materialises `qualityScore` | `MdmDataQualityScorerJob` (deleted); the agreement **blend** is retired — `MdmApiController` now reads OR's `qualityScore` |
| `MergeService` | **delete** | OR `mdm-merge` engine + `ObjectsMergedEvent` | `MdmMergeController` (deleted); its `syncQueue->enqueue` path → `ObjectsMergedSyncListener` |
| `TrustConfigurationService` | **delete** | OR `trust-configuration` register + `TrustTierResolver` | `MasterEntityService`/`DuplicateDetectionService` (deleted), `MdmTrustConfigController` (deleted) |
| `SourceRecordChangedListener` | **delete** | OR's on-save `SurvivorshipRecomputeListener` | registration removed from `Application.php` |
| `MdmMasterEntityController` | **delete** | OR-hosted steward list/detail | routes removed; frontend caller removed in the frontend link |
| `MdmMergeController` | **delete** | OR merge REST (`/api/objects/merge/*`) | routes removed |
| `MdmTrustConfigController` | **delete** | OR trust-register CRUD via ObjectService | routes removed |
| `MdmSyncQueueController` | **delete** | (admin view removed; no non-frontend caller) | routes removed; `SyncQueueService` retained for the processor job + AVG + listener |
| `MdmDataQualityScorerJob` | **delete** | OR materialises on save | removed from `info.xml` |
| `MdmDuplicateDetectionJob` | **delete** | OR detects | removed from `info.xml` |
| `OpenRegisterSyncService` | **keep** (rewired) | — (maintains app-side `masterEntityRef` / `isMasterRecord` CRM mirrors OR does **not** own) | `MasterEntityService::find` → `MdmObjectRepository::findMasterEntity` |
| `MdmOpenRegisterSyncJob` | **keep** (rewired) | — | drives `OpenRegisterSyncService`; `findAll` → `findMasterEntities` |
| `MdmObjectRepository` | **keep** (extended) | — | gains the re-homed read helpers; unchanged methods retained |
| `SyncQueueService` | **keep** (rewired trigger) | delivers via OR `WebhookService` | trigger now `ObjectsMergedSyncListener` (+ AVG soft-delete) instead of `MergeService` |
| `MdmSyncQueueProcessorJob` | **keep** | — | drives `SyncQueueService::processQueue` |
| `MdmApiController` | **keep** (rewired) | downstream **read** projection (REQ-MDM-010), not OR CRUD pass-through | uses re-homed helpers; `dataQualityScore` ← OR `qualityScore` |
| `MdmAvgWorkflowController` | **keep** | ADR-047 AVG workflow | unchanged (AVGWorkflowService re-homed under it) |
| `AVGWorkflowService` | **keep** (re-homed) | ADR-047 (AVG owned by pipelinq) | drops `MasterEntityService` dep; reads OR-materialised golden record via `MdmObjectRepository` |
| `MdmHardDeleteConfirmationJob` | **keep** | ADR-047 | depends only on `AVGWorkflowService` (retained) |

### OpenRegisterSyncService — keep rationale

`OpenRegisterSyncService` does **not** duplicate OR's engine: it stamps `masterEntityRef` +
`isMasterRecord` onto pipelinq's own `contact` / `client` / `product` CRM objects so the catalog
resolves the canonical record per master entity — a pipelinq-specific projection OpenRegister does
not maintain. It is retained and its single `MasterEntityService::find` call is re-pointed to
`MdmObjectRepository::findMasterEntity`. `MdmOpenRegisterSyncJob` (its hourly driver) is kept for
the same reason.

## AVG re-homing

`AVGWorkflowService` used `MasterEntityService` only for reads (`find`, `linkedSourceRecords`,
`findAll(status)`) and its `SCHEMA` / `SOURCE_SCHEMA` slug constants. Those move onto the retained
`MdmObjectRepository` as `findMasterEntity()`, `linkedSourceRecords()`, `findMasterEntities()` and
the `SCHEMA_MASTER_ENTITY` / `SCHEMA_SOURCE_RECORD` constants. AVG reads the golden record straight
off OR's materialised object (it redacts, it never recomputes), so no survivorship logic is
re-homed — only the thin OR-access reads. `MdmApiController` and `OpenRegisterSyncService` consume
the same helpers, so the retained repository is their single shared home (not `AVGWorkflowService`).

## ObjectsMergedEvent listener (ADR-041)

`MergeService::executeMerge()` / `reverseMerge()` used to call `SyncQueueService::enqueueSync()`
imperatively. `MergeService` is deleted; OpenRegister owns the merge and fires
`OCA\OpenRegister\Event\ObjectsMergedEvent` (`getSurvivorUuid()`, `getMergedFromUuids()`,
`getMergeOperationId()`, `isReversal()`) after a merge or reversal. `ObjectsMergedSyncListener`
subscribes to it (registered in `Application.php`) and, for each of the five downstream systems
(`shillinq`, `procest`, `scholiq`, `opencatalogi`, `decidesk`), enqueues a sync item with
`changeType = merge` (or `reverse-merge` when `isReversal()`), carrying the survivor's
OR-materialised golden record in the payload. This is the ADR-041 sanctioned pattern: propagation
is an **event subscription**, never an RPC into OR internals; delivery stays on OR's
`WebhookService` (satisfying `no-phantom-cross-app-rpc`). A unit-test event stub lives at
`tests/Stubs/Event/ObjectsMergedEvent.php`.

## Trust-row seed Repair step

`SeedTrustConfigurationRows` (post-migration `IRepairStep`) seeds pipelinq's three account trust
rows into OpenRegister's `trust-configuration` register / `trustConfiguration` schema. It runs as a
Repair step (not a migration) so OR's autoloader + its imported register are available, and writes
through `ObjectService::saveObject(register: 'trust-configuration', schema: 'trustConfiguration')`
(RBAC + multitenancy; slugs resolve via OR's `setRegister`/`setSchema`). Idempotent: each row is
matched via `ObjectService::findAll` on its `(entityType, attribute, sourceSystem)` natural key
before write, and the step no-ops when OpenRegister is not installed. The three rows carry the same
field shape as OR's schema, migrating one-to-one from the pipelinq register file.

## Schema cleanup (deferred from the config link)

In `lib/Settings/register.d/90-master-data-management.json`:
- **Drop** `matchName` / `matchEmail` / `matchKvkNumber` / `matchPhone` — OR's nested-path dedup
  reads `goldenRecord.*` directly (the config link already re-pointed `x-openregister-dedup`), so
  the flattened projections and the app-side maintenance that populated them are gone.
- **Remove** the local `trustConfiguration` schema declaration + its `pipelinq` `schemas[]` entry +
  its three seed objects (migrated to OR by the Repair step).
- **Drop** `dataQualityScore` from `masterEntity.required` — nothing populates it now (the scorer is
  deleted); OR materialises `qualityScore`, which the read API exposes. The `dataQualityScore` and
  `qualityScore` property descriptions are corrected (blend retired).
- **Keep** every `masterEntity` field OR/AVG still need (goldenRecord, attributeProvenance,
  attributeOverrides, qualityScore/qualityStatus, lastSourceUpdate, gdprNotes, lineage/status).

## Gate compliance

- **redundant-controller (ADR-022):** the deleted `MdmMerge` / `MdmTrustConfig` / `MdmMasterEntity`
  / `MdmSyncQueue` controllers were pass-throughs over OR's ObjectService/merge/trust surface;
  `MdmApiController` stays because it is a downstream **read projection** (natural-key + alias
  resolution), not OR CRUD.
- **no-phantom-cross-app-rpc / ADR-041:** the sync trigger is an `ObjectsMergedEvent` subscription;
  delivery stays on OR's `WebhookService`. No server-to-server RPC into OR.
- **route-reachability:** every deleted controller's routes are removed from `appinfo/routes.php`;
  the retained `mdmApi#*` and `mdmAvgWorkflow#*` routes still map to existing methods.
- **or-abstraction anti-patterns (gate-23, ADR-045):** the app-local survivorship engine, dedup
  scanner, quality scorer, merge tooling and trust-config service are deleted.
- **spec-coverage:** every changed public/protected method carries `@spec`; new read helpers, the
  listener and the Repair step point at this change's spec.

## Deferred questions

- **Retire the sync-queue wrapper entirely?** ADR-045 favours pure OR webhook subscriptions over a
  parallel queue. `SyncQueueService` already delivers through OR's `WebhookService`, so the retained
  queue is a thin trigger+status wrapper. Whether even that should be replaced by declaring the five
  downstream targets as OR webhook subscriptions is left to a follow-up (tracked, not done here).

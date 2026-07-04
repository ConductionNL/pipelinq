---
kind: code
depends_on: [mdm-consume-or-surface]
---

## Why

ADR-045 (#D) moves the generic MDM / data-governance engine out of every leaf app and into
OpenRegister. The head config link `mdm-consume-or-surface` migrated the `masterEntity` schema to
consume OR's surface (added `x-openregister-survivorship` + `x-openregister-merge`, re-pointed
`x-openregister-dedup` to nested `goldenRecord.*` paths, added the `attributeOverrides` map). With
OpenRegister now **materialising** the golden record + provenance + quality score on save, owning
the reversible merge engine (firing `ObjectsMergedEvent`), owning nested-path dedup, and owning the
`trust-configuration` register, pipelinq's app-side MDM engine is redundant — and, per gate-23
(ADR-045 or-abstraction anti-patterns), a review-blocking duplication of OR's surface.

This is the **backend code link** of the chain (sibling of `mdm-consume-or-surface-frontend`). It
deletes the app-side survivorship / dedup / quality / merge / trust-config engine + its steward
controllers and jobs, re-homes the one genuinely app-owned consumer (the AVG right-of-deletion
workflow, ADR-047) onto a scoped repository that reads OR's materialised golden record directly,
rewires the downstream sync trigger from the deleted `MergeService` to an `ObjectsMergedEvent`
subscription (ADR-041 event-not-RPC), and seeds the three pipelinq trust rows into OpenRegister's
`trust-configuration` register via a Repair step. It also finishes the schema cleanup the config
link deferred (drops the flattened `match*` fields and the local `trustConfiguration` schema + its
seed rows).

## What Changes

**Deleted (OR now owns the engine):** `MasterEntityService`, `DuplicateDetectionService`,
`StringSimilarity`, `DataQualityScorer`, `MergeService`, `TrustConfigurationService`;
`SourceRecordChangedListener`; controllers `MdmMasterEntityController`, `MdmMergeController`,
`MdmTrustConfigController`, `MdmSyncQueueController`; jobs `MdmDataQualityScorerJob`,
`MdmDuplicateDetectionJob` — plus their unit tests, routes and background-job registrations.

**Re-homed:** the small `masterEntity` / `sourceRecord` **read helpers** (`findMasterEntity`,
`findMasterEntities`, `linkedSourceRecords`) move onto the retained `MdmObjectRepository`, reading
OR's materialised object. `AVGWorkflowService` (ADR-047), the `MdmApiController` downstream read
API, `OpenRegisterSyncService` (app-side CRM mirror maintenance) and `MdmOpenRegisterSyncJob` now
consume those helpers instead of the deleted `MasterEntityService`. `MdmApiController` sources the
public `dataQualityScore` from OR's materialised `qualityScore` (the app-side agreement blend is
retired).

**Rewired:** a new `ObjectsMergedSyncListener` subscribes to OpenRegister's `ObjectsMergedEvent`
and enqueues downstream sync (`changeType = merge`, or `reverse-merge` when `isReversal`) via the
retained `SyncQueueService`, which still **delivers** through OR's `WebhookService`. Registered in
`Application.php`; the old source-record recompute listener registration is removed.

**Seeded:** a new `SeedTrustConfigurationRows` Repair step writes pipelinq's three account trust
rows into OpenRegister's `trust-configuration` register (via `ObjectService`, RBAC + tenant scoped,
idempotent on the `(entityType, attribute, sourceSystem)` natural key).

**Schema cleanup:** the flattened `matchName` / `matchEmail` / `matchKvkNumber` / `matchPhone`
projection fields, the local `trustConfiguration` schema declaration (+ its `schemas[]` entry) and
its three seed rows are removed from `lib/Settings/register.d/90-master-data-management.json`;
`dataQualityScore` is dropped from `required` (OR materialises `qualityScore` instead).

## Impact

- **Affected capability spec (this repo):** `master-data-management` — MODIFIED. The requirements
  that described an app-owned survivorship / dedup / quality / merge / trust engine now describe
  **consuming OR's materialised surface + event** with the app retaining only the AVG workflow, the
  downstream read API, the CRM-mirror sync and a thin sync-queue trigger.
- **Deleted code:** 6 services, 4 controllers, 2 jobs, 1 listener (+ 6 unit test files).
- **Consumes:** OR `mdm-survivorship` (materialise-on-save + `trust-configuration` register),
  `mdm-merge` (`ObjectsMergedEvent`), `mdm-quality-api` (`qualityScore`), `duplicate-detection`
  (nested paths).
- **References:** ADR-045 (#D payoff), ADR-022 (apps consume OR abstractions), ADR-041 (propagation
  via events, not phantom RPC), ADR-047 (AVG owned by pipelinq).
- **Chain:** depends on `mdm-consume-or-surface` (config head); sibling of
  `mdm-consume-or-surface-frontend` (frontend code).

# master-data-management (delta — mdm-consume-or-surface-backend)

This delta removes pipelinq's app-side MDM **engine** (survivorship, dedup, quality, merge, trust
config) now that OpenRegister materialises the golden record + quality on save, owns the reversible
merge engine (firing `ObjectsMergedEvent`) and owns the `trust-configuration` register (ADR-045 #D).
It re-homes the AVG consumer onto a scoped repository reading OR's materialised object, rewires the
downstream sync trigger to an OR event (ADR-041), and seeds the trust rows into OR via a Repair
step. It depends on the config head link `mdm-consume-or-surface` and is the sibling of the frontend
link `mdm-consume-or-surface-frontend`. Every scenario here is a backend/config assertion with no UI
surface, so each carries an `@e2e exclude` reason (ADR-020).

## MODIFIED Requirements

### Requirement: REQ-MDM-001 — Golden Record per Master Entity

The system MUST maintain a single authoritative golden record per Master Entity, materialised by
**OpenRegister's `SurvivorshipRecomputeListener` on save** from the `x-openregister-survivorship`
annotation — not by an app-side survivorship service. The app MUST NOT ship an in-process
pick-winner / recompute loop (`MasterEntityService` is deleted). Consumers that need the golden
record (the AVG workflow, the downstream read API, the CRM-mirror sync) MUST read it straight off the
OR-materialised `masterEntity` object via the retained `MdmObjectRepository` read helpers
(`findMasterEntity`, `findMasterEntities`, `linkedSourceRecords`), which go through the OpenRegister
`ObjectService` (RBAC + multitenancy).

**Feature tier**: MVP
**Handoff**: Consumes OpenRegister `mdm-survivorship` (materialise-on-save). App-side `MasterEntityService` + `SourceRecordChangedListener` are deleted.

#### Scenario: App-side survivorship engine is deleted

- WHEN the pipelinq `lib/` tree is inspected after this change
- THEN `MasterEntityService` and `SourceRecordChangedListener` MUST NOT exist, and no class MUST reference them
- AND golden-record consumers MUST read the OR-materialised object via `MdmObjectRepository`, not via an app-side recompute

`@e2e exclude` backend deletion — verified by a repo-wide grep returning no reference to `MasterEntityService` / `SourceRecordChangedListener` and by the PHPUnit suite; survivorship materialisation is owned + e2e-tested by OpenRegister.

#### Scenario: Golden-record reads go through the OR-materialised object

- GIVEN a Master Entity whose `goldenRecord` OpenRegister materialised on its last save
- WHEN a retained consumer resolves it via `MdmObjectRepository::findMasterEntity`
- THEN the returned `goldenRecord` + `attributeProvenance` MUST be the OR-materialised values (the app computes nothing)

`@e2e exclude` backend read-path — asserted by the AVG + listener + read-API unit tests over the in-memory repository; the materialisation itself is OpenRegister's.

### Requirement: REQ-MDM-002 — Deterministic Duplicate Detection on Natural Keys

The system MUST detect duplicates via **OpenRegister's nested-path `duplicate-detection`** driven by
the `masterEntity` `x-openregister-dedup` matchRules on `goldenRecord.*` paths — not via an app-side
detector. The app-side `DuplicateDetectionService` + `StringSimilarity` MUST be deleted, and the
flattened `matchName` / `matchEmail` / `matchKvkNumber` / `matchPhone` projection fields (and the
maintenance that populated them) MUST be removed from the schema, because OpenRegister traverses the
nested dot-paths directly.

**Feature tier**: MVP
**Handoff**: Consumes OpenRegister `duplicate-detection` (nested paths).

#### Scenario: App-side dedup engine and match projections are gone

- WHEN the pipelinq `lib/` tree and the `masterEntity` schema configuration are inspected
- THEN `DuplicateDetectionService` and `StringSimilarity` MUST NOT exist
- AND the schema MUST NOT declare `matchName`, `matchEmail`, `matchKvkNumber` or `matchPhone`

`@e2e exclude` backend deletion + schema assertion — verified by grep + JSON parse; duplicate detection is owned + e2e-tested by OpenRegister.

### Requirement: REQ-MDM-004 — Merge Tooling with Preview and Reversibility

The system MUST rely on **OpenRegister's `mdm-merge` engine** for preview, atomic execution,
reversal and the `mergeOperation` audit log (declared via `x-openregister-merge`); the app-side
`MergeService` MUST be deleted. Downstream propagation after a merge or reversal MUST be driven by
**subscribing to OpenRegister's `ObjectsMergedEvent`**, not by an app-side merge call: the
`ObjectsMergedSyncListener` MUST enqueue one downstream sync item per target system with
`changeType = merge` (or `reverse-merge` when `isReversal()` is true), carrying the survivor's
OR-materialised golden record. Propagation MUST be an event subscription, never an RPC into
OpenRegister internals (ADR-041).

**Feature tier**: MVP
**Handoff**: Consumes OpenRegister `mdm-merge` + `ObjectsMergedEvent`. App-side `MergeService` is deleted.

#### Scenario: Merge event enqueues downstream sync per system

- GIVEN OpenRegister fires `ObjectsMergedEvent` (survivor uuid, merged-from uuids, mergeOperationId, isReversal=false) after a merge
- WHEN `ObjectsMergedSyncListener` handles it
- THEN it MUST enqueue one sync-queue item per downstream system with `changeType = merge`, the survivor as `masterEntity`, and the survivor's golden record in the payload

`@e2e exclude` backend event fan-out — asserted by `ObjectsMergedSyncListenerTest` over the in-memory repository; the merge UI is owned + e2e-tested by OpenRegister.

#### Scenario: Reversal event uses reverse-merge change type

- GIVEN `ObjectsMergedEvent` with `isReversal() = true`
- WHEN the listener handles it
- THEN each enqueued sync item MUST use `changeType = reverse-merge`

`@e2e exclude` backend event fan-out — asserted by the listener unit test.

### Requirement: REQ-MDM-005 — Per-Attribute Trust-Tier Configuration

The system MUST express per-`(entityType, attribute, sourceSystem)` trust tiers as rows in
**OpenRegister's `trust-configuration` register**, resolved by OR's `TrustTierResolver` — not via an
app-side `TrustConfigurationService`, which MUST be deleted. The pipelinq register file MUST NOT
declare a local `trustConfiguration` schema or seed rows. pipelinq MUST seed its three account trust
rows into OpenRegister's register with an idempotent `IRepairStep` (`SeedTrustConfigurationRows`)
that writes through `ObjectService` (RBAC + multitenancy), matches each row on its natural key before
writing, and no-ops when OpenRegister is not installed.

**Feature tier**: MVP
**Handoff**: Consumes OpenRegister `mdm-survivorship` `trust-configuration` register + `TrustTierResolver`.

#### Scenario: Trust rows are seeded into OpenRegister idempotently

- GIVEN OpenRegister is installed and the pipelinq trust rows are not yet present
- WHEN `SeedTrustConfigurationRows` runs
- THEN it MUST write the three account rows into OpenRegister's `trust-configuration` register via `ObjectService`
- AND a second run MUST write nothing (each row matched on `(entityType, attribute, sourceSystem)`)

`@e2e exclude` backend Repair step — asserted by `SeedTrustConfigurationRowsTest` with a recording ObjectService; tier resolution is owned + e2e-tested by OpenRegister.

#### Scenario: Local trust schema and service are removed

- WHEN the pipelinq register file and `lib/` tree are inspected
- THEN there MUST be no `trustConfiguration` schema declaration, no `trustConfiguration` in the `pipelinq` `schemas[]`, no local trust seed rows, and no `TrustConfigurationService` class

`@e2e exclude` backend deletion + schema assertion — verified by grep + JSON parse.

### Requirement: REQ-MDM-006 — Downstream Sync Queue with Retries and Confirmation

The system MUST retain the outbound `SyncQueueService` (queueing, exponential-backoff retries,
dead-letter, confirmation callbacks) but its enqueue **trigger** for merges MUST be the
`ObjectsMergedEvent` subscription rather than a direct call from a deleted `MergeService`. Delivery
MUST continue through OpenRegister's `WebhookService` (no bespoke HTTP client, no phantom RPC). The
app-side `MdmSyncQueueController` admin endpoints MUST be removed (their steward view is deleted; no
non-frontend caller remains); the `MdmSyncQueueProcessorJob` that drives delivery is retained.

**Feature tier**: MVP
**Handoff**: Delivery via OpenRegister `WebhookService`; trigger via `ObjectsMergedEvent`.

#### Scenario: Sync queue is triggered by the event, delivered via WebhookService

- GIVEN a merge or reversal fires `ObjectsMergedEvent`
- WHEN `ObjectsMergedSyncListener` enqueues and `MdmSyncQueueProcessorJob` later processes the queue
- THEN enqueue MUST originate from the event subscription (not a `MergeService` call), and delivery MUST dispatch through OpenRegister's `WebhookService`
- AND the `MdmSyncQueueController` admin routes MUST no longer be registered

`@e2e exclude` backend trigger + delivery path — asserted by the listener unit test + `SyncQueueServiceTest` (WebhookService dispatch); no UI surface remains.

### Requirement: REQ-MDM-007 — Data-Quality-Score per Master Entity

The system MUST expose the per-object data-quality score from **OpenRegister's materialised
`qualityScore`** (from `x-openregister-quality`); the app-side `DataQualityScorer` and its
cross-source agreement **blend** MUST be deleted. The downstream read API MUST source the public
`dataQualityScore` field from OR's `qualityScore`, and `dataQualityScore` MUST no longer be a
required `masterEntity` property (nothing app-side populates it).

**Feature tier**: MVP
**Handoff**: Consumes OpenRegister `mdm-quality-api` (`qualityScore` materialised on save).

#### Scenario: Quality scorer is deleted and the read API uses OR's qualityScore

- WHEN the pipelinq `lib/` tree is inspected and the read API projects a Master Entity
- THEN `DataQualityScorer` MUST NOT exist
- AND the read API's `dataQualityScore` MUST be sourced from the object's OR-materialised `qualityScore`
- AND `dataQualityScore` MUST NOT appear in `masterEntity.required`

`@e2e exclude` backend deletion + projection — verified by grep, the read-API unit path, and JSON parse; quality materialisation is owned + e2e-tested by OpenRegister.

### Requirement: REQ-MDM-010 — Read-API for Downstream Apps

The system MUST keep the read-only downstream API (`MdmApiController`) that resolves a Master Entity
by masterId, pre-merge alias or natural key — a downstream **read projection**, not a pass-through of
OpenRegister CRUD. Its reads MUST go through the retained `MdmObjectRepository` helpers (OR
`ObjectService`, RBAC + multitenancy) rather than the deleted `MasterEntityService`, and its
`dataQualityScore` projection MUST reflect OpenRegister's materialised `qualityScore`.

**Feature tier**: MVP
**Handoff**: Read projection over OpenRegister-materialised objects.

#### Scenario: Read API resolves via the re-homed repository helpers

- GIVEN an authenticated downstream caller querying by natural key or masterId/alias
- WHEN `MdmApiController` resolves the Master Entity
- THEN it MUST read via `MdmObjectRepository::findMasterEntity` / `findMasterEntities` (not `MasterEntityService`)
- AND the projected `dataQualityScore` MUST come from the object's OR-materialised `qualityScore`

`@e2e exclude` backend read projection — asserted by the read-API unit path over the in-memory repository; the endpoint is a machine-to-machine API with no steward UI.

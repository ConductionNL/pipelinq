---
status: done
---

# master-data-management Specification

## Purpose
Maintains a single authoritative golden record per master entity by resolving conflicting attribute values through configurable per-source trust tiers, detecting deterministic and probabilistic duplicates, and providing reversible merge tooling with preview. Computes a data-quality score, records an audit trail of merges and gold-record mutations, executes AVG right-of-deletion, and publishes golden records to downstream apps via a read API and a retrying sync queue.
## Requirements
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

### Requirement: REQ-MDM-003 — Probabilistic Duplicate Detection on Fuzzy Match

The system MUST support probabilistic duplicate detection via the `normalized` and `levenshtein` match methods declared in the `x-openregister-dedup` annotation and resolved by OpenRegister's `DuplicateDetectionService`. Because OpenRegister returns a weight-normalised mean of per-field similarities, the dedup threshold MUST be tuned (0.7) so that a pair agreeing on natural keys surfaces while a single weak field cannot. Jaro-Winkler / TF-IDF scoring that OpenRegister does not yet model MAY be retained as a noted in-process fallback path used only when OpenRegister is unavailable.

**Feature tier**: MVP
**Handoff**: Primary path is OpenRegister `findDuplicates()`; Jaro-Winkler/TF-IDF retained only as the OR-unavailable fallback.

#### Scenario: Name similarity fuzzy match

- GIVEN two Master Entities "Jansens Bouw BV" and "Jansen's Bouw B.V." sharing a natural key
- WHEN the detector runs
- THEN OpenRegister's `findDuplicates()` returns the pair with `linkageMethod = probabilistic-match` (or `deterministic-key` when a natural key matched)
- AND it appears in the stewardship queue for human decision

#### Scenario: Below threshold produces no candidate

- GIVEN two entities whose only weak signal is a partial name match below the dedup threshold
- WHEN the detector runs
- THEN NO candidate is generated (insufficient confidence)

#### Scenario: Fallback path on OpenRegister unavailability

- GIVEN OpenRegister's duplicate-detection service cannot be resolved
- WHEN `detectDuplicates()` runs
- THEN the app MUST degrade to the in-process Jaro-Winkler/TF-IDF fallback rather than failing

---

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

### Requirement: REQ-MDM-008 — Audit Trail per Merge and Gold-Record Mutation

The system MUST log an audit trail for every merge and every gold-record attribute mutation, retained for 10 years.

**Feature tier**: MVP
**Handoff**: Covered by OpenRegister built-in audit-trail field on every schema instance (10-year retention is platform-default).

#### Scenario: Merge audit log

- GIVEN a merge of account A into B performed by steward "alice" on 2026-05-22
- WHEN the audit trail is reviewed
- THEN an entry exists with timestamp 2026-05-22T14:30:00Z, action MERGE, actor alice, object master-entity B, details mergedFrom=[A] and preMergeSnapshot={...}, retention until 2036-05-22

#### Scenario: Attribute change audit log

- GIVEN a source-record update that changes Master Entity's goldenRecord.phone via trust-tier recomputation
- WHEN the change is applied
- THEN an entry exists in Master Entity's auditTrail with action ATTRIBUTE_UPDATE, attribute=phone, oldValue, newValue, reason describing the trust-tier resolution, retention 10 years

---

### Requirement: REQ-MDM-009 — Right-of-Deletion (AVG art. 17)

The system MUST correctly execute AVG right-of-deletion: Master Entity soft-deleted, source-records anonymized, downstream apps synced, audit trail anonymized.

**Feature tier**: MVP
**Handoff**: Covered by existing `avg-verzoeken-workflow` change (separate openspec change in this repo).

#### Scenario: Initiate right-of-deletion

- GIVEN a data-subject request from "Pietje Puk" to be forgotten from all systems
- WHEN a data-steward initiates the workflow
- THEN the Master Entity for contact "Pietje Puk" is identified
- AND all linked source-records (5 records from 5 systems) are listed
- AND a right-of-deletion workflow task is created for steward approval
- AND an audit note logs the request with the GDPR request ID

#### Scenario: Approve and execute deletion

- GIVEN the steward approves the right-of-deletion request
- WHEN `AVGWorkflowService::approveAndExecuteRightOfDeletion()` runs
- THEN Master Entity status = soft-deleted
- AND all source-records are anonymized: name/address/email/phone → "[verwijderd]", withdrawn=true
- AND sync-queue-items are created for all 5 downstream systems with changeType=soft-delete
- AND an audit trail entry is written with steward name and GDPR request ID
- AND a hard-delete callback is scheduled for +30 days

#### Scenario: Audit trail remains after anonymization

- GIVEN the soft-delete was executed as above
- WHEN an auditor reviews the audit trail 1 year later
- THEN the trail still shows merge and right-of-deletion events with timestamps and actor names
- AND the actual attribute values are anonymized (redacted as [***])
- AND the structure of events and dates remains visible for wettelijke aantoonbaarheid

---

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

### Requirement: REQ-MDM-012 — Conflict-Resolution Wizard for Data Stewards

The system MUST provide a wizard for data stewards to resolve attribute conflicts, with the option to establish persistent trust-tier rules.

**Feature tier**: MVP
**Handoff**: Deferred — UI wizard tracked for a future change once the trust-configuration schema lands.

#### Scenario: Resolve VAT number conflict

- GIVEN a Master Entity with conflicting VAT numbers: pipelinq-crm="NL123456789B01", shillinq-debiteuren="NL123456789B02"
- WHEN a steward opens the conflict-resolution wizard
- THEN the UI displays the attribute name, both values with their source and last-updated timestamp, and a rationale field
- AND the steward can select which value to keep
- AND optionally check "Always use this rule" to create a persistent trust-config entry

#### Scenario: Persistent rule creation

- GIVEN the steward selected "Always use this rule"
- AND entered rationale "Shillinq bron geverifieerd via VAT-validatie EU-service"
- WHEN the wizard saves
- THEN the attribute value for this entity is resolved to the shillinq value
- AND a trust-configuration entry is created with entityType=account, attribute=vatNumber, sourceSystem=shillinq-debiteuren, trustTier=gold
- AND all other Master Entities are queued for recomputation with the new rule

### Requirement: REQ-MDM-013 — MDM Steward UI Deep-Linked to OpenRegister

The system MUST NOT host its own Master Data Management steward views. The app-local MDM views
(`MdmMasterEntityListView`, `MdmDuplicateCandidatesDashboard`, `MdmSyncQueueAdmin`), in-body
sections (`MdmDataQualitySection`, `MdmGoldenRecordSection`) and modals
(`MdmConflictResolutionModal`, `MdmMergeWizardModal`) MUST be removed, together with their
`src/registry.js` imports + registrations and their `manifest.d` pages and nav entries. In their
place the app MUST expose exactly ONE navigation entry that deep-links to OpenRegister's
Data-Quality surface (`/index.php/apps/openregister/#/quality`), where the steward selects the
pipelinq register and `masterEntity` schema in OpenRegister's own register/schema selector. No
app-local MDM dashboard, list, merge wizard or conflict-resolution modal may remain.

**Feature tier**: MVP
**Handoff**: Consumes OpenRegister `mdm-quality-api`, `mdm-survivorship`, `mdm-merge`, `duplicate-detection`, `mdm-conflict-resolution-ui` (steward views hosted by OR). Backend deletion is retired to the sibling backend link.

#### Scenario: App-local MDM views are removed

- WHEN the pipelinq `src/` tree is inspected after this change
- THEN none of `MdmMasterEntityListView`, `MdmDuplicateCandidatesDashboard`, `MdmSyncQueueAdmin`, `MdmDataQualitySection`, `MdmGoldenRecordSection`, `MdmConflictResolutionModal` or `MdmMergeWizardModal` MUST exist as a file or be imported / registered in `src/registry.js`
- AND the production build MUST resolve with no unresolved import from those deletions

`@e2e exclude` structural deletion — verified by a repo-wide grep for the seven component names + the `/mdm/` routes returning nothing under `src/`, and by a passing `npm run build`.

#### Scenario: A single deep-link nav entry replaces the three MDM entries

- WHEN `src/manifest.d/90-master-data-management.json` is inspected
- THEN it MUST declare no app-hosted MDM page and exactly one `href` nav entry labelled "Data quality"
- AND that entry's `href` MUST target OpenRegister's Data-Quality surface (`/index.php/apps/openregister/#/quality`), not an app-local route

`@e2e exclude` structural manifest assertion — verified by parsing the manifest fragment (one `href` menu entry, empty `pages`) in the build/lint step; the live steward surface it links to lives in OpenRegister's own e2e suite.

#### Scenario: Steward scopes the OR surface to pipelinq/masterEntity

- GIVEN the "Data quality" nav entry opens OpenRegister's Data-Quality index
- WHEN the steward selects the pipelinq register and the `masterEntity` schema in OpenRegister's in-page register/schema selector
- THEN OpenRegister's Data-Quality view MUST show the pipelinq `masterEntity` quality distribution and lowest-quality objects (query params are not required, because OpenRegister scopes via the selector, not the URL)

`@e2e exclude` cross-app surface owned by OpenRegister — the register/schema selection + quality rendering are covered by OpenRegister's `mdm-frontend` e2e suite; pipelinq only contributes the deep-link entry point.

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


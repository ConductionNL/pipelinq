# Tasks — mdm-consume-or-surface-backend (backend code link)

Code + config-cleanup, `lib/` + `appinfo/` + `tests/`. No `src/` touched (that is the sibling link
`mdm-consume-or-surface-frontend`). Depends on `mdm-consume-or-surface`.

- [x] Re-home the `masterEntity` / `sourceRecord` read helpers onto `MdmObjectRepository` (`SCHEMA_MASTER_ENTITY`, `SCHEMA_SOURCE_RECORD`, `findMasterEntity`, `findMasterEntities`, `linkedSourceRecords`), reading OR's materialised object.
- [x] Re-point `AVGWorkflowService` off `MasterEntityService` onto the re-homed repository helpers + slug constants (drop the `MasterEntityService` constructor dep).
- [x] Re-point `MdmApiController` off `MasterEntityService` onto the repository helpers; source `dataQualityScore` from OR's materialised `qualityScore` (retire the agreement blend).
- [x] Re-point `OpenRegisterSyncService` + `MdmOpenRegisterSyncJob` off `MasterEntityService` (kept — they maintain app-side `masterEntityRef` / `isMasterRecord` CRM mirrors OR does not own).
- [x] Add `ObjectsMergedSyncListener` subscribing to `OCA\OpenRegister\Event\ObjectsMergedEvent`; enqueue downstream sync per system (`merge` / `reverse-merge`) via the retained `SyncQueueService`.
- [x] Register `ObjectsMergedSyncListener` in `Application.php`; remove the `SourceRecordChangedListener` registration.
- [x] Add `SeedTrustConfigurationRows` Repair step seeding the three trust rows into OR's `trust-configuration` register (idempotent on natural key; no-op when OR absent); register it in `info.xml`.
- [x] Delete `MasterEntityService`, `DuplicateDetectionService`, `StringSimilarity`, `DataQualityScorer`, `MergeService`, `TrustConfigurationService`.
- [x] Delete `SourceRecordChangedListener`.
- [x] Delete controllers `MdmMasterEntityController`, `MdmMergeController`, `MdmTrustConfigController`, `MdmSyncQueueController`; remove their routes from `appinfo/routes.php`.
- [x] Delete jobs `MdmDataQualityScorerJob`, `MdmDuplicateDetectionJob`; remove them from `info.xml` background-jobs.
- [x] Schema cleanup in `register.d/90-master-data-management.json`: drop `matchName` / `matchEmail` / `matchKvkNumber` / `matchPhone`; remove the local `trustConfiguration` schema + its `schemas[]` entry + its 3 seed rows; drop `dataQualityScore` from `masterEntity.required`; fix the `dataQualityScore` / `qualityScore` descriptions.
- [x] Delete the unit tests for the deleted services (`MasterEntityServiceTest`, `DuplicateDetectionServiceTest`, `StringSimilarityTest`, `DataQualityScorerTest`, `MergeServiceTest`, `TrustConfigurationServiceTest`).
- [x] Update `AVGWorkflowServiceTest` to the new `(repository, syncQueue, logger)` constructor; add `tests/Stubs/Event/ObjectsMergedEvent.php`.
- [x] Add `ObjectsMergedSyncListenerTest` (per-system fan-out, `merge` vs `reverse-merge`, golden-record payload, non-event ignored) and `SeedTrustConfigurationRowsTest` (seeds 3, idempotent, no-op when OR absent).
- [x] Audit callers with grep — zero dangling references to any deleted class, route or job.
- [x] PHPUnit unit suite green (php:8.3-cli); `phpcs` 0 errors, `phpstan` OK, `psalm` no errors, `phpmd` no new violations on changed files.
- [x] `run-hydra-gates.sh --scope-to-diff --base development` — no NEW findings on changed files (redundant-controller, no-phantom-cross-app-rpc, route-reachability, spec-coverage all PASS).
- [x] `npm run build` exit 0 (frontend already stripped by the sibling link).
- [x] `openspec validate mdm-consume-or-surface-backend --strict` passes.

## Acceptance criteria

- The six MDM engine services, the source-record listener, the four steward controllers and the two
  jobs are gone; no route, background-job registration, DI wiring or test references a deleted class.
- `AVGWorkflowService`, `MdmApiController`, `OpenRegisterSyncService` and `MdmOpenRegisterSyncJob`
  read the golden record via `MdmObjectRepository`'s OR-materialised helpers; `MdmApiController`
  exposes OR's `qualityScore` as `dataQualityScore`.
- `ObjectsMergedSyncListener` is registered on `ObjectsMergedEvent` and enqueues one sync item per
  downstream system with the correct `changeType`; `SyncQueueService` still delivers via OR's
  `WebhookService`.
- The `SeedTrustConfigurationRows` Repair step seeds the three trust rows into OR's
  `trust-configuration` register idempotently.
- The register file declares no `match*` fields and no local `trustConfiguration` schema/rows.

## Quality checklist

- Object I/O via `ObjectService` (RBAC + multitenancy); no raw DB writes (ADR-022).
- Cross-app propagation via OR events, not phantom RPC (ADR-041).
- SPDX + `@spec` on every new/changed PHP file; placeholder nil UUIDs where an id is unknown.
- Register JSON re-parses; no dangling `schemas[]` reference; every retained property keeps a `title`.
- No frontend re-introduced; no shared test stub edited to force green.

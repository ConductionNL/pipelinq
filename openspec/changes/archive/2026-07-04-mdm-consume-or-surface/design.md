# Design — mdm-consume-or-surface (ADR-045 #D)

## Context

This is the **head config link** of a three-link chain (ADR-032) that migrates pipelinq to fully
consume OpenRegister's now-complete MDM surface (ADR-045 #D) and delete the app-side MDM engine +
UI. This link performs only the **schema-annotation migration** on `masterEntity` and the
**trustConfiguration row migration** into OR's register. The backend-deletion and
frontend-removal links depend on this one (see Deferred Questions for the split).

The design records the full consume-OR mapping so the two dependent code links can be authored
against a single, agreed target — even though this link only edits config.

## Consume-OR mapping

Every pipelinq MDM artifact, the OR capability that replaces it, and the disposition. `drop` =
delete; `rewire` = keep the file but re-point it at an OR event/annotation; `keep` = stays app-side
(genuine app policy or downstream read API); `config` = handled by this head link.

### Schema / config (this head link)

| pipelinq artifact | Replaced by (OR) | Disposition |
|---|---|---|
| `masterEntity.x-openregister-quality` | OR quality materialisation (already consumed) | keep (unchanged) |
| `masterEntity.x-openregister-dedup` flattened `match*` rules | OR nested-path dedup (`duplicate-detection`, #A) | **config**: rewrite matchRules to `goldenRecord.*` paths |
| `matchName` / `matchEmail` / `matchKvkNumber` / `matchPhone` fields | (no longer needed — dedup reads nested) | **config**: drop the four projection fields |
| *(new)* golden-record survivorship | `x-openregister-survivorship` + `SurvivorshipRecomputeListener` (#2) | **config**: add annotation |
| *(new)* merge annotation | `x-openregister-merge` + merge engine (#B) | **config**: add annotation |
| *(new)* per-object conflict override map | `overridesField` + `POST /api/objects/survivorship/{id}/override` (#E) | **config**: add `attributeOverrides` property + `overridesField` in annotation |
| pipelinq-local `trustConfiguration` schema + seed rows | OR `trust-configuration` register/schema (#2) | **config**: remove local schema; migrate rows to OR (Seed Data below) |

### Backend services (dependent link `…-backend`, code)

| pipelinq artifact | Replaced by (OR) | Disposition |
|---|---|---|
| `MasterEntityService` (survivorship recompute) | `SurvivorshipResolver` + `SurvivorshipRecomputeListener` on save | **drop** |
| `DuplicateDetectionService` | OR `DuplicateDetectionService` (nested paths) | **drop** |
| `DataQualityScorer` | OR quality API materialisation | **drop** (agreement-blend term is retired — see DQ note) |
| `MergeService` | OR merge engine + `ObjectsMergedEvent` | **drop** |
| `TrustConfigurationService` | OR `trust-configuration` register (CRUD via ObjectService) | **drop** |
| `StringSimilarity` | OR similarity calculator | **drop** |
| `OpenRegisterSyncService` | (assess — see DQ) | **assess** |
| `MdmObjectRepository` | (assess — see DQ) | **assess** |
| `SyncQueueService` | keep the schema/delivery, re-point the trigger | **rewire**: subscribe to `ObjectsMergedEvent` instead of `MergeService` enqueue |
| `AVGWorkflowService` | ADR-047 (AVG owned separately) | **keep** — but its `MasterEntityService` coupling must be re-pointed (see DQ) |

### Controllers (dependent link `…-backend`, code)

| pipelinq controller | Disposition |
|---|---|
| `MdmMasterEntityController` | **drop** (steward CRUD now OR-hosted) — assess |
| `MdmMergeController` | **drop** (OR merge REST) |
| `MdmTrustConfigController` | **drop** (OR trust register CRUD) |
| `MdmSyncQueueController` | **assess** (drop vs keep with SyncQueueService) |
| `MdmApiController` | **keep** — downstream-app read API (REQ-MDM-010) |
| `MdmAvgWorkflowController` | **keep** — ADR-047 AVG workflow |

### Background jobs (dependent link `…-backend`, code)

| job | Disposition |
|---|---|
| `MdmDataQualityScorerJob` | **drop** (OR materialises on save) |
| `MdmDuplicateDetectionJob` | **drop** (OR detects) |
| `MdmSyncQueueProcessorJob` | **keep** (drives the retained SyncQueueService delivery) |
| `MdmOpenRegisterSyncJob` | **assess** |
| `MdmHardDeleteConfirmationJob` | **keep** — AVG +30-day hard-delete callback (ADR-047) |

### Frontend (dependent link `…-frontend`, code)

| pipelinq frontend | Replaced by (OR) | Disposition |
|---|---|---|
| `MdmMasterEntityListView` | OR Master-entity list | **remove** |
| `MdmDuplicateCandidatesDashboard` | OR Duplicate Candidates view | **remove** |
| `MdmSyncQueueAdmin` | OR Queue / sync-health view | **remove** |
| `MdmDataQualitySection` | OR Data Quality dashboard | **remove** |
| `MdmGoldenRecordSection` + conflict modal | OR golden-record detail + conflict-resolution modal (#E) | **remove** |
| Merge wizard modal | OR merge wizard + MergeOperations UI (#C) | **remove** |
| `manifest.d/90-*.json` MDM pages + 3 nav entries | OR "Data quality" nav group | **remove**; add ONE deep-link entry point (see Deep-link) |
| `registry.js` MDM registrations | — | **remove** |

## Sync-queue → ObjectsMergedEvent rewire (ADR-041)

Today `MergeService::executeMerge()` calls `SyncQueueService::enqueueSync()` imperatively. After
this migration `MergeService` is deleted and OR owns the merge; OR fires `ObjectsMergedEvent`
(`survivorUuid`, `mergedFromUuids[]`, `mergeOperationId`, `isReversal`) after a merge or reversal.

The rewire (in the `…-backend` link): pipelinq registers an `IEventListener<ObjectsMergedEvent>`
that calls `SyncQueueService::enqueueSync()` for each downstream target, using
`changeType = reverse-merge` when `isReversal()` is true. This is the ADR-041 sanctioned pattern:
propagation is an **event subscription**, not a cross-app RPC. It also satisfies the
**no-phantom-cross-app-rpc** gate — the sync path never HTTP-calls OR or reaches into an OR service
to *trigger* work; it reacts to an OR event. `SyncQueueService` already **delivers** via OR's
`WebhookService::dispatchEvent()` (not a bespoke HTTP client), so the delivery path already
complies; only the trigger changes.

> Note (ADR-045 sync-health): ADR-045 favours declaring downstream targets as OR **webhook
> subscriptions** and reusing OR's `WebhookService`/`WebhookDeliveryJob`/`HookRetryJob` rather than
> a parallel queue. pipelinq's `SyncQueueService` already dispatches through `WebhookService`, so the
> retained queue is a thin trigger+status wrapper, not a second delivery engine. Whether even that
> wrapper should be retired in favour of pure webhook subscriptions is a Deferred Question.

## Deep-link approach

ADR-045: "the steward *navigates to OpenRegister* for the MDM surface; a leaf app links to it via
the deep-link registry rather than re-hosting the views." In the `…-frontend` link, the three MDM
nav entries (Master data / Data quality / Duplicates) and their pages are removed. Where a pipelinq
entry point is still wanted, a **single** nav entry deep-links to OR's Data-Quality surface
(`/apps/openregister` → MDM "Data quality" group, scoped to `register=pipelinq, schema=masterEntity`)
rather than rendering an app-local view. The deep-link carries the register/schema selection OR's
shared selector expects. This keeps a discoverable governance entry point in pipelinq without a
review-blocking app-local MDM dashboard.

## trustConfiguration row migration

OR now owns a generic `trust-configuration` register + `trustConfiguration` schema (keys default
`["entityType","attribute","sourceSystem"]`, with `trustTier`, `freshnessDecayDays`, `effectiveFrom`,
`manualOverrideAllowed`, `rationale`). pipelinq's three seeded rows (account/billingAddress→kvk gold,
account/phone→shillinq silver, account/vatNumber→kvk gold) carry the **same field shape**, so they
migrate one-to-one.

Migration in this head link: the pipelinq-local `trustConfiguration` schema declaration and its
three seeded objects are **removed** from `lib/Settings/register.d/90-master-data-management.json`;
the equivalent rows are seeded into OR's `trust-configuration` register (see Seed Data). OR's
`TrustTierResolver` reads them via `ObjectService` (RBAC + tenant scoped), so the survivorship
listener resolves tiers identically. HOW the rows are physically re-seeded into OR's register (an
OR-side seed edit vs. a pipelinq Repair step that writes into the OR register) is a Deferred
Question — it may require an OR-repo companion change since the OR register file lives in the OR
repo.

## Gate compliance

- **redundant-controller (ADR-022):** deleting `MdmMerge`/`MdmTrustConfig`/`MdmMasterEntity`
  controllers (in `…-backend`) removes pass-through wrappers over OR's ObjectService/merge surface,
  satisfying the gate. `MdmApiController` stays because it is a downstream **read** projection, not
  a pass-through of OR CRUD.
- **no-phantom-cross-app-rpc / ADR-041:** the sync trigger becomes an `ObjectsMergedEvent`
  subscription; delivery stays on OR's `WebhookService`. No server-to-server RPC into OR is
  introduced.
- **or-abstraction anti-patterns (gate-23, ADR-045):** the app-local survivorship engine, dedup
  scanner, merge tooling and MDM dashboards are all deleted, so the ADR-045 anti-pattern family no
  longer matches pipelinq.
- **manifest / schema-property-titles:** every retained/added schema property keeps a `title`; the
  dropped `match*` fields and local `trustConfiguration` schema are removed cleanly (no dangling
  refs from the register `schemas[]` list).
- **spec-coverage / e2e-coverage:** this head link is config-only (no changed PHP/Vue methods, no
  new page components), so no `@spec`/`@e2e`/`@visual` traceability is triggered here; those gates
  bind on the dependent code links.

## Seed Data

- **Removed from pipelinq register:** the three `trustConfiguration` seed objects and the local
  `trustConfiguration` schema declaration (+ its entry in the `pipelinq` register `schemas[]`).
- **Seeded into OR `trust-configuration` register** (one-to-one, same shape):
  - `(account, billingAddress, kvk-api)` → gold, freshnessDecayDays 180, effectiveFrom 2026-06-01
  - `(account, phone, shillinq-debiteuren)` → silver, freshnessDecayDays 90, effectiveFrom 2026-06-01
  - `(account, vatNumber, kvk-api)` → gold, freshnessDecayDays 365, manualOverrideAllowed false
- **masterEntity seed objects** keep their `goldenRecord` + `attributeProvenance`; after the
  annotation lands, OR's listener recomputes them on next save (values are unchanged for these seeds
  because provenance already reflects the winning tiers). No `match*` values are seeded (fields
  dropped). Placeholder nil UUID for any new seed row without a natural id:
  `00000000-0000-0000-0000-000000000000`.

# Design — mdm-consume-or-surface-frontend (ADR-045 #D, frontend link)

## Context

The head config link `mdm-consume-or-surface` declares `x-openregister-survivorship` +
`x-openregister-merge` on the `masterEntity` schema and re-points `x-openregister-dedup` at nested
`goldenRecord.*` paths, so OpenRegister now materialises the golden record and hosts the steward
Data-Quality / Master-entities / Duplicates / Queue-health / Merge / conflict-resolution surface.
This link removes the now-duplicate pipelinq-hosted MDM steward UI and leaves ONE deep-link into
OR. Backend service/controller/job/schema-property removal is the sibling link
`mdm-consume-or-surface-backend`.

## Removed artifacts and why

| Artifact | Type | Replaced by (OR) |
|---|---|---|
| `MdmMasterEntityListView` | view (page) | OR Master-entities index |
| `MdmDuplicateCandidatesDashboard` | view (page) | OR Duplicates index |
| `MdmSyncQueueAdmin` | view (page) | OR Queue-health index |
| `MdmDataQualitySection` | in-body section | OR Data-Quality index |
| `MdmGoldenRecordSection` | in-body section | OR golden-record detail |
| `MdmConflictResolutionModal` | modal | OR conflict-resolution UI (override endpoint) |
| `MdmMergeWizardModal` | modal | OR merge wizard + MergeOperations UI |

The MDM manifest pages that hosted these (Master data, Master-entity detail, Data quality,
Duplicates, Trust configuration, Sync queue) are removed with them. `MdmMasterEntityDetail` was a
declarative `type:"detail"` page whose only bespoke body widget was `MdmGoldenRecordSection`; with
that section gone the page has no app-specific surface left, so it is removed too (the object is
browsable in OR).

## Deep-link approach

ADR-045: "the steward navigates to OpenRegister for the MDM surface; a leaf app links to it via the
deep-link registry rather than re-hosting the views." The three MDM nav entries are replaced by ONE
`href` nav entry, "Data quality", pointing at `/index.php/apps/openregister/#/quality` — OR's
Data-Quality SPA route (`QualityIndex`).

OR's `QualityIndex` scopes to a register + schema through an **in-page selector**
(`RegisterSchemaSelector`, committing the pair to OR's `quality` Pinia store), **not** through URL
query parameters — verified by reading `or-mdm-override/src/views/quality/QualityIndex.vue` and
`RegisterSchemaSelector.vue`. A `?register=pipelinq&schema=masterEntity` query would therefore be
ignored. So the deep-link intentionally lands on OR's Data-Quality index page and the steward
selects the pipelinq register + `masterEntity` schema in OR's selector. (If OR later honours query
params on that route, the href can carry the scope; nothing else changes.)

The nav entry reuses the id `MdmDataQuality`, so the existing `menu-layout.json` relocation
`MdmDataQuality → AnalyticsGroup` keeps placing the governance entry in the "Reports & Compliance"
group. It is an `href` menu entry (no `route`), the same proven shape as the base manifest's
"Documentation" entry, so no vue-router route is registered for it and nothing dangles.

## Gate compliance

- **no-dangling-imports / build:** every deleted component's `import` and `componentRegistry`
  registration is removed from `src/registry.js`; a repo-wide grep for the seven component names
  and the `/mdm/` routes returns nothing under `src/`. `npm run build` (webpack) must pass.
- **modal-isolation:** N/A — the two inline-modal-hosting components are deleted, not refactored;
  no `<NcModal>`/`<NcDialog>` markup is added inline anywhere.
- **or-abstraction anti-patterns (gate-23, ADR-045):** removing the app-local MDM dashboards +
  merge/conflict modals is exactly what the gate asks for; pipelinq no longer re-hosts OR's surface.
- **spec-coverage / e2e-coverage:** this link deletes Vue components and edits JSON manifest/nav; it
  adds no new frontend method carrying behaviour to e2e. REQ-MDM-013's scenarios are structural
  (files absent, nav entry present) and are annotated `@e2e exclude` with the verifying grep/build
  reason, since the live steward surface now lives in OpenRegister's own e2e suite.

## Deferred Questions

- **Backend removal (sibling link `mdm-consume-or-surface-backend`):** deletes `MergeService`,
  `MasterEntityService`, `DuplicateDetectionService`, `DataQualityScorer`,
  `TrustConfigurationService`, the merge/trust/master-entity controllers, and the DQ/dedup jobs;
  removes the `match*` schema properties + their maintenance and the local `trustConfiguration`
  schema; rewires `SyncQueueService` onto `ObjectsMergedEvent`. Kept out of this link so the
  frontend strip and the backend deletion each stay single-surface (ADR-032).
- **MDM read API (`MdmApiController`, REQ-MDM-010):** retained by the backend link as a downstream
  projection; not deleted here. The deleted dashboards' fetches to `/api/mdm/dashboard` disappear
  with the dashboards, so no live caller is orphaned by removing the views.

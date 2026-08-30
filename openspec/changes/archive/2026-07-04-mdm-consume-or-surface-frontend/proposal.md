---
kind: code
depends_on: [mdm-consume-or-surface]
---

## Why

ADR-045 (#D) moves the generic MDM / data-governance surface out of every leaf app and into
OpenRegister. The head config link `mdm-consume-or-surface` migrates the `masterEntity` schema to
consume OR's surface (adds `x-openregister-survivorship` + `x-openregister-merge`, re-points
`x-openregister-dedup` to nested `goldenRecord.*` paths, adds the `attributeOverrides` override
map). With OR now materialising the golden record and hosting the steward Data-Quality /
Master-entities / Duplicates / Queue-health / Merge / conflict-resolution views, pipelinq's own
app-hosted MDM steward UI is redundant — and, per gate-23 (ADR-045 or-abstraction anti-patterns),
a review-blocking duplication of OR's surface.

This is the **frontend code link** of the chain. It removes the pipelinq-hosted MDM steward views,
sections and modals, unwires their registry + manifest registrations, and replaces the three MDM
nav entries with a **single deep-link** into OpenRegister's Data-Quality surface. No app-local MDM
dashboard survives; the steward "navigates to OpenRegister for the MDM surface" (ADR-045).

This link is code-only and additive-safe on the backend: it touches only `src/` (Vue views,
components, modals, the registry, the manifest fragment and the nav layout). It does not remove any
backend service, controller, job, schema property or seed — that is the separate backend code link
`mdm-consume-or-surface-backend`. The MDM read API (`MdmApiController`) that some of these deleted
dashboards fetched from is retained by that backend link (REQ-MDM-010, a downstream projection), so
nothing this link deletes leaves a live backend endpoint unreachable to a consumer that still needs
it.

## What Changes

- **DELETE** the seven app-local MDM Vue artifacts: `MdmMasterEntityListView`,
  `MdmDuplicateCandidatesDashboard`, `MdmSyncQueueAdmin` (views); `MdmDataQualitySection`,
  `MdmGoldenRecordSection` (in-body sections); `MdmConflictResolutionModal`, `MdmMergeWizardModal`
  (modals).
- **UNWIRE** their `import`s and `componentRegistry` registrations from `src/registry.js`.
- **REMOVE** the MDM pages (Master data list, Master-entity detail, Data quality dashboard,
  Duplicates, Trust configuration list, Sync queue) and the three MDM nav entries (Master data /
  Data quality / Duplicates) from `src/manifest.d/90-master-data-management.json`.
- **ADD** a single "Data quality" nav entry that deep-links to OpenRegister's Data-Quality surface
  (`/index.php/apps/openregister/#/quality`). OR's quality index selects register + schema with an
  in-page selector (`RegisterSchemaSelector`), not query params, so the deep-link lands on OR's
  Data-Quality page and the steward picks the pipelinq register + `masterEntity` schema there.
- **RECONCILE** `src/menu-layout.json`: drop the retired `MdmMasterEntities` / `MdmDuplicates`
  settings-section promotions; keep the `MdmDataQuality → AnalyticsGroup` relocation so the deep-link
  lands in the "Reports & Compliance" group.

## Impact

- **Affected capability spec (this repo):** `master-data-management` — ADDED requirement REQ-MDM-013
  (MDM steward UI is deep-linked to OpenRegister, not app-hosted). The app-hosted steward-view
  behaviour previously implied by the surface is retired.
- **Affected code:** `src/views/mdm/*`, `src/components/mdm/*`, `src/modals/Mdm*Modal.vue`,
  `src/registry.js`, `src/manifest.d/90-master-data-management.json`, `src/menu-layout.json`.
- **Consumes:** OpenRegister `mdm-quality-api`, `mdm-survivorship`, `mdm-merge`,
  `duplicate-detection`, `mdm-conflict-resolution-ui` (the steward views now hosted by OR).
- **References:** ADR-045 (#D payoff — steward navigates to OR), ADR-022 (apps consume OR
  abstractions), ADR-032 (spec sizing → chain).
- **Depends on:** `mdm-consume-or-surface` (the schema annotations that make OR host the surface).
- **Sibling link:** `mdm-consume-or-surface-backend` (deletes the app-side MDM services / jobs /
  merge/trust controllers, retains `MdmApiController`; removes the `match*` properties + local
  `trustConfiguration` schema).

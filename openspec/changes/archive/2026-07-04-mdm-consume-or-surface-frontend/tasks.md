# Tasks — mdm-consume-or-surface-frontend (frontend code link)

Code-only, `src/` only. No backend service / controller / job / schema property / seed is touched
(that is the sibling link `mdm-consume-or-surface-backend`).

- [x] Delete `src/views/mdm/MdmMasterEntityListView.vue`.
- [x] Delete `src/views/mdm/MdmDuplicateCandidatesDashboard.vue`.
- [x] Delete `src/views/mdm/MdmSyncQueueAdmin.vue`.
- [x] Delete `src/components/mdm/MdmDataQualitySection.vue`.
- [x] Delete `src/components/mdm/MdmGoldenRecordSection.vue`.
- [x] Delete `src/modals/MdmConflictResolutionModal.vue`.
- [x] Delete `src/modals/MdmMergeWizardModal.vue`.
- [x] Remove the now-empty `src/views/mdm/` and `src/components/mdm/` directories.
- [x] Remove the seven MDM `import` statements from `src/registry.js`.
- [x] Remove the five MDM `componentRegistry` registrations (`MdmMasterEntityListView`, `MdmGoldenRecordSection`, `MdmDataQualitySection`, `MdmDuplicateCandidatesDashboard`, `MdmSyncQueueAdmin`) from `src/registry.js`.
- [x] Remove the MDM pages (Master data, Master-entity detail, Data quality, Duplicates, Trust configuration, Sync queue) from `src/manifest.d/90-master-data-management.json`.
- [x] Remove the three MDM nav entries (Master data / Data quality / Duplicates) from `src/manifest.d/90-master-data-management.json`.
- [x] Add ONE `href` nav entry "Data quality" deep-linking to `/index.php/apps/openregister/#/quality` in `src/manifest.d/90-master-data-management.json`.
- [x] Reconcile `src/menu-layout.json`: drop `MdmMasterEntities` / `MdmDuplicates` from `settingsSection`; keep the `MdmDataQuality → AnalyticsGroup` relocation; update the note.
- [x] Grep `src/` for the seven deleted component names, the removed page ids and `/mdm/` routes — confirm zero dangling references.
- [x] `npm run lint` passes on the edited files; register + manifest fragment + nav-layout JSON re-parse.
- [x] `npm run build` (webpack) passes with no unresolved import from the deletions.
- [x] `openspec validate mdm-consume-or-surface-frontend --strict` passes.
- [ ] Live-browser: the "Data quality" nav entry opens OR's Data-Quality surface; the removed MDM routes no longer resolve. (deferred — live verify)

## Acceptance criteria

- The seven MDM Vue artifacts and their registry imports/registrations are gone; the build resolves.
- `src/manifest.d/90-master-data-management.json` declares no app-hosted MDM page and exactly one
  `href` deep-link nav entry into OpenRegister's Data-Quality surface.
- No `src/` reference to a deleted component, removed MDM page id, or `/mdm/` route remains.

## Quality checklist

- No dangling imports (build passes) — modal-isolation N/A (modals deleted, not refactored).
- Manifest fragment + `menu-layout.json` re-parse as valid JSON.
- No backend / schema property / seed touched (single-surface code link).
- i18n: no new user-facing strings beyond the reused English "Data quality" nav label.

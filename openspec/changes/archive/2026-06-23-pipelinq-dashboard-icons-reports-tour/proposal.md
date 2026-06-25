# pipelinq-dashboard-icons-reports-tour — consistent dashboard icons, a Reports & Compliance group, and a Restart-tutorial action

## Why

Three small navigation/settings polish items on the pipelinq shell:

1. The three dashboard pages (Commercial, Operational, Customer Support) each carried
   a different nav icon, so the "these are the dashboards" affordance was lost.
2. The reporting/analytics entries were scattered under a generically-named
   "Analytics" group while pipelinq actually surfaces both reports AND compliance
   dashboards (SLA attainment, MDM data-quality). The fleet pattern (shillinq) groups
   these under a single "Reporting & Compliance" surface.
3. The product walkthrough (ADR-043) only auto-shows on first visit; a returning user
   had no way to replay it.

## What changes

1. **Consistent dashboard icon.** `KccWerkplek` (Customer Support) and
   `OperationalDashboard` (Operational) now use `icon-category-dashboard`, matching
   `Dashboard` (Commercial). All three render the same dashboard glyph.

2. **"Reports & Compliance" nav group.** The `AnalyticsGroup` is relabelled
   "Reports & Compliance" and ALL reporting/analytics entries are consolidated under
   it via `src/menu-layout.json#relocations`: `Rapportage` (Reporting), `Analytics`,
   `PipelineAnalytics`, `BillingCategories`, plus `SlaAttainment` (moved out of
   Service) and `MdmDataQuality` (moved out of the Settings foldout — it is a
   data-quality dashboard, i.e. a report). The MDM steward views
   (`MdmMasterEntities`, `MdmDuplicates`) stay under Settings. Every report stays
   reachable; the report PAGES are untouched.

3. **"Restart tutorial" Settings action.** A new `RestartTutorial` menu entry
   (`action: "replay-walkthrough"`, `tourId: pipelinq:getting-started`) under a "Help"
   caption in the Settings foldout. Clicking it re-launches the walkthrough from step
   one. Wiring lives in `@conduction/nextcloud-vue`: `CnAppNav` now dispatches
   `action: "replay-walkthrough"` to the `cnReplayWalkthrough` inject CnAppRoot
   already provided, and `useWalkthrough().restart()` runs the tour in a new replay
   mode that ignores the persisted seen-version so a returning user replays the FULL
   tour (otherwise their seen-version filters every step out).

## Impact

- `src/manifest.json` (2 icons, group label, 2 new menu entries), `src/manifest.d/85-kcc-werkplek.json` (1 icon), `src/menu-layout.json` (relocations + settingsSection).
- `@conduction/nextcloud-vue`: `CnAppNav.vue` (replay-walkthrough dispatch + `icon-play` bridge), `useWalkthrough.js` (replay mode), plus tests.
- info.xml version bump for cache-bust.

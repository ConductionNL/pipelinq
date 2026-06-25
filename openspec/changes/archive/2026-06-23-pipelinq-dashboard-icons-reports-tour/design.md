# Design — pipelinq-dashboard-icons-reports-tour

**Kind:** code

This change is three independent navigation/settings edits. The canonical "WHERE
entries live" file is `src/menu-layout.json` (see `applyMenuRelocations` /
`applySettingsSection` in `src/main.js`); fragments stay the source of WHAT exists
(ADR-037).

## Task 1 — dashboard icon on all three dashboard menu entries

The three `type:"dashboard"` pages must share one glyph. Set the menu `icon` to
`icon-category-dashboard` (the glyph `Dashboard`/Commercial already uses):

- `KccWerkplek` (Customer Support) — `src/manifest.d/85-kcc-werkplek.json`, was `icon-comment`.
- `OperationalDashboard` (Operational) — `src/manifest.json` menu, was `icon-category-monitoring`.
- `Dashboard` (Commercial) — already `icon-category-dashboard`, unchanged.

Only the menu `icon` field changes; ids/routes are kept. CnAppNav bridges
`icon-category-dashboard` to the monochrome MDI `ViewDashboard`, so all three render
identically.

## Task 2 — "Reports & Compliance" group + relocate all reports

shillinq's "Reporting & Compliance" surface is a bespoke Vue landing component
(`ReportingComplianceOverview`), not a reusable declarative group landing — so the
sanctioned fallback (a clean nav group) is used here.

- Relabel the existing `AnalyticsGroup` group from "Analytics" to "Reports &
  Compliance" (`src/manifest.json` menu; id/order kept so relocations still target it).
- Consolidate every reporting/analytics entry under it via
  `src/menu-layout.json#relocations`: `Rapportage`, `Analytics`, `PipelineAnalytics`,
  `BillingCategories` (already there) + `SlaAttainment` (moved from `Service`) +
  `MdmDataQuality` (moved out of the Settings foldout).
- `MdmDataQuality` is removed from `settingsSection` because `applySettingsSection`
  runs AFTER `applyMenuRelocations` and would otherwise re-lift it into the foldout.
  The MDM steward views (`MdmMasterEntities`, `MdmDuplicates`) stay under Settings.
- Report PAGES (including the menu-less sub-views `RapportageContactmomenten`,
  `ChannelAnalyticsView`, `AgentPerformanceView`) are untouched and stay routable.

## Task 3 — "Restart tutorial" Settings action

The walkthrough engine (`useWalkthrough`, ADR-043) caches one machine per appId.
CnAppRoot mounts `CnWalkthrough` (which constructs that machine) and already provides
a `cnReplayWalkthrough(tourId)` inject calling `useWalkthrough(appId, manifest).restart(id)`
on the same cached machine. The gap: `CnAppNav.onItemClick` only dispatched
`action: "user-settings"`, never `replay-walkthrough`.

- **nc-vue `CnAppNav`** — inject `cnReplayWalkthrough` and dispatch
  `action: "replay-walkthrough"` (passing the item's `tourId`); add an `icon-play` →
  `PlayCircleOutline` bridge so the entry renders a play glyph.
- **nc-vue `useWalkthrough`** — `restart()` now sets a `replaying` flag; `composeSteps`
  ignores `seenVersion` while replaying. Without this a returning user (whose
  `seenVersion` already covers every step's `sinceVersion`) would replay an EMPTY
  tour. `start`/`dismiss`/`complete` clear the flag.
- **pipelinq manifest** — a `SettingsHelpCaption` ("Help") caption + a
  `RestartTutorial` entry (`action: "replay-walkthrough"`,
  `tourId: "pipelinq:getting-started"`, `icon: "icon-play"`), both promoted into the
  Settings foldout via `src/menu-layout.json#settingsSection`.

## Verification

Built against local `../nextcloud-vue`. Live (`:8080`, hard cache-bust): all three
dashboards show the dashboard glyph; the "Reports & Compliance" group holds Reporting,
Analytics, Pipeline Analytics, SLA attainment, Billing categories, Data quality (each
opens); the Settings "Restart tutorial" re-launches the tour at 1/11 even for a
returning user with `cn-walkthrough-seen:pipelinq = 1.0.0`. nc-vue jest + pipelinq
vitest at baseline; lint clean on changed source.

# Tasks — pipelinq-dashboard-icons-reports-tour

## 1. Dashboard icons
- [x] Set `KccWerkplek` menu `icon` to `icon-category-dashboard` (`src/manifest.d/85-kcc-werkplek.json`).
- [x] Set `OperationalDashboard` menu `icon` to `icon-category-dashboard` (`src/manifest.json`).
- [x] Confirm `Dashboard` (Commercial) already uses `icon-category-dashboard`.
- [x] Live-verify all three render the same `view-dashboard-icon`.

## 2. Reports & Compliance group
- [x] Relabel `AnalyticsGroup` from "Analytics" to "Reports & Compliance" (`src/manifest.json`).
- [x] Relocate `SlaAttainment` (from `Service`) and `MdmDataQuality` into `AnalyticsGroup` (`src/menu-layout.json#relocations`); keep `Rapportage`/`Analytics`/`PipelineAnalytics`/`BillingCategories`.
- [x] Remove `MdmDataQuality` from `settingsSection` (it re-lifts otherwise); keep the MDM steward views under Settings.
- [x] Live-verify the group holds Reporting, Analytics, Pipeline Analytics, SLA attainment, Billing categories, Data quality — each opens its report page.

## 3. Restart tutorial Settings action
- [x] nc-vue `CnAppNav`: inject `cnReplayWalkthrough` + dispatch `action: "replay-walkthrough"` (with `item.tourId`); add `icon-play` → `PlayCircleOutline` bridge.
- [x] nc-vue `useWalkthrough`: add replay mode (`restart()` shows the full tour ignoring `seenVersion`; cleared on start/dismiss/complete).
- [x] pipelinq manifest: add `SettingsHelpCaption` + `RestartTutorial` (`action:"replay-walkthrough"`, `tourId:"pipelinq:getting-started"`); promote both via `settingsSection`.
- [x] nc-vue tests: useWalkthrough replay spec + CnAppNav replay-walkthrough dispatch spec (87 + replay pass).
- [x] Live-verify: a returning user (`cn-walkthrough-seen:pipelinq = 1.0.0`) clicking "Restart tutorial" re-launches the tour at step 1/11.

## 4. Build / release
- [x] `npm run build` green (bundles `../nextcloud-vue`); `check:manifest` PASS.
- [x] pipelinq vitest at baseline (recurringRevenue orphan pre-existing); nc-vue jsdoc baseline + changed-file lint clean.
- [x] Bump `appinfo/info.xml` `<version>` (cache-bust).

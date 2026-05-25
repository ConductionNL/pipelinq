# Tasks: Fix dashboard-in-widget anti-pattern across Pipelinq manifest

All edits target `src/manifest.json`. Each task is atomic and edits
exactly one of the six `pages[]` entries. Line numbers are the
ranges at the `origin/development` tip (commit b657976) this change
branches from.

## 1. Manifest fixes (`src/manifest.json`)

1. Edit the `Dashboard` page entry (lines 154–181, `route: "/"`): change `type` from `"dashboard"` to `"custom"`, add `"component": "DashboardView"`, remove the `config` object, remove the `slots` object. Preserve `id`, `route`, `title`. Preserve the surrounding tab-indent style (the file uses hard tabs).
2. Edit the `SurveyAnalyticsView` page entry (lines 656–683, `route: "/surveys/:id/analytics"`): change `type` to `"custom"`, add `"component": "SurveyAnalyticsView"`, remove `config`, remove `slots`. Preserve `id`, `route`, `title`.
3. Edit the `MyWork` page entry (lines 698–725, `route: "/my-work"`): change `type` to `"custom"`, add `"component": "MyWorkView"`, remove `config`, remove `slots`. Preserve `id`, `route`, `title`.
4. Edit the `Rapportage` page entry (lines 744–771, `route: "/rapportage"`): change `type` to `"custom"`, add `"component": "RapportageDashboardView"`, remove `config`, remove `slots`. Preserve `id`, `route`, `title`.
5. Edit the `ChannelAnalyticsView` page entry (lines 772–799, `route: "/rapportage/channels"`): change `type` to `"custom"`, add `"component": "ChannelAnalyticsView"`, remove `config`, remove `slots`. Preserve `id`, `route`, `title`.
6. Edit the `AgentPerformanceView` page entry (lines 800–827, `route: "/rapportage/agents"`): change `type` to `"custom"`, add `"component": "AgentPerformanceView"`, remove `config`, remove `slots`. Preserve `id`, `route`, `title`.

## 2. Validation

7. Re-parse `src/manifest.json` to confirm it is still valid JSON (`node -e "JSON.parse(require('fs').readFileSync('src/manifest.json'))"` or equivalent).
8. Confirm none of the six target page entries still contain the substring `"type": "dashboard"` (grep should return only the legitimate `Kennisbank` entry).
9. Confirm all six target component names (`DashboardView`, `SurveyAnalyticsView`, `MyWorkView`, `RapportageDashboardView`, `ChannelAnalyticsView`, `AgentPerformanceView`) remain registered as top-level keys in `src/registry.js`.

## 3. Deferred follow-up (out of scope; file as separate issue)

10. File a follow-up GitHub issue tracking removal of `<CnDashboardPage>` from `src/views/Dashboard.vue:3,183,213,253`. Two viable paths to mention: (a) migrate the eight declarative widgets + `DEFAULT_LAYOUT` constant into the manifest so the route legitimately becomes `type: "dashboard"`, or (b) eliminate the inner `CnDashboardPage` by promoting it to a layout primitive while keeping widgets in Vue. Cross-link this PR and pipelinq#521.

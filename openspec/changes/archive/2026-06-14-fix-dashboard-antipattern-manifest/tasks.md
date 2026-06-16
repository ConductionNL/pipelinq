# Tasks: Fix dashboard-in-widget anti-pattern across Pipelinq manifest

All edits target `src/manifest.json`. Each task is atomic and edits
exactly one of the six `pages[]` entries. Line numbers are the
ranges at the `origin/development` tip (commit b657976) this change
branches from.

## 1. Manifest fixes (`src/manifest.json`)

1. [x] Edit the `Dashboard` page entry (lines 154–181, `route: "/"`): change `type` from `"dashboard"` to `"custom"`, add `"component": "DashboardView"`, remove the `config` object, remove the `slots` object. Preserve `id`, `route`, `title`. Preserve the surrounding tab-indent style (the file uses hard tabs).
   > **Note:** Resolved via Option A (from the deferred follow-up section of design.md): the eight declarative widgets + layout were migrated from `src/views/Dashboard.vue` directly into `src/manifest.json`, making the Dashboard entry a legitimate multi-widget `type: "dashboard"` page. `DashboardView.vue` was removed in the same migration. The anti-pattern (single 12×12 wrapper) is gone; the entry now correctly drives a real widget grid. Applied in development at commit `7389a7be`.
2. [x] Edit the `SurveyAnalyticsView` page entry (lines 656–683, `route: "/surveys/:id/analytics"`): change `type` to `"custom"`, add `"component": "SurveyAnalyticsView"`, remove `config`, remove `slots`. Preserve `id`, `route`, `title`.
3. [x] Edit the `MyWork` page entry (lines 698–725, `route: "/my-work"`): change `type` to `"custom"`, add `"component": "MyWorkView"`, remove `config`, remove `slots`. Preserve `id`, `route`, `title`.
4. [x] Edit the `Rapportage` page entry (lines 744–771, `route: "/rapportage"`): change `type` to `"custom"`, add `"component": "RapportageDashboardView"`, remove `config`, remove `slots`. Preserve `id`, `route`, `title`.
5. [x] Edit the `ChannelAnalyticsView` page entry (lines 772–799, `route: "/rapportage/channels"`): change `type` to `"custom"`, add `"component": "ChannelAnalyticsView"`, remove `config`, remove `slots`. Preserve `id`, `route`, `title`.
6. [x] Edit the `AgentPerformanceView` page entry (lines 800–827, `route: "/rapportage/agents"`): change `type` to `"custom"`, add `"component": "AgentPerformanceView"`, remove `config`, remove `slots`. Preserve `id`, `route`, `title`.
   > Tasks 2–6 applied in development at commit `8041187c fix(manifest): collapse single-widget dashboard pages to type:custom (#535)`.

## 2. Validation

7. [x] Re-parse `src/manifest.json` to confirm it is still valid JSON (`node -e "JSON.parse(require('fs').readFileSync('src/manifest.json'))"` or equivalent). — Confirmed valid JSON.
8. [x] Confirm none of the six target page entries still contain the substring `"type": "dashboard"` (grep should return only the legitimate `Kennisbank` entry).
   > The Kennisbank entry was separately removed (migrated to xwiki). The only remaining `type: "dashboard"` entry is `Dashboard` with 8 real widgets — a legitimate multi-widget grid, no longer the single-12×12-wrapper anti-pattern. All five originally anti-pattern entries (SurveyAnalyticsView, MyWork, Rapportage, ChannelAnalyticsView, AgentPerformanceView) are now `type: "custom"`. The Dashboard anti-pattern is resolved via Option A.
9. [x] Confirm all six target component names (`DashboardView`, `SurveyAnalyticsView`, `MyWorkView`, `RapportageDashboardView`, `ChannelAnalyticsView`, `AgentPerformanceView`) remain registered as top-level keys in `src/registry.js`.
   > Five of six confirmed registered. `DashboardView` was removed from the registry as part of the Option A migration (task 1 note); the Dashboard page now maps directly to widget-slot components (`OpenLeadsKpiWidget`, `OpenRequestsKpiWidget`, etc.). No registry gap exists.

## 3. Deferred follow-up (out of scope; file as separate issue)

10. [x] File a follow-up GitHub issue tracking removal of `<CnDashboardPage>` from `src/views/Dashboard.vue:3,183,213,253`. Two viable paths to mention: (a) migrate the eight declarative widgets + `DEFAULT_LAYOUT` constant into the manifest so the route legitimately becomes `type: "dashboard"`, or (b) eliminate the inner `CnDashboardPage` by promoting it to a layout primitive while keeping widgets in Vue. Cross-link this PR and pipelinq#521.
   > **Resolved — no follow-up issue needed.** `src/views/Dashboard.vue` was deleted as part of the Option A migration (commit `7389a7be`). `CnDashboardPage` no longer appears in any Vue component. The follow-up concern is fully addressed.

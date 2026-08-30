## Context

The `60-klantbeeld-360` manifest overlay (from the archived `klantbeeld-360` change)
introduced two analytics surfaces:

1. `/analytics` — a declarative `type:"dashboard"` page with four `stat` widgets
   sourced from `GET /api/analytics/summary`, filtered by a `period` pageFilter
   (week/month/quarter).
2. `/pipeline-analytics` — a custom `PipelineAnalyticsView.vue` that fetches one
   pipeline's leads client-side and derives four KPIs (incl. Win Rate and Average
   Deal Size as client-side ratios) plus a Stage Funnel, scoped per-pipeline with no
   period filter (effectively all-time).

Both pages compute overlapping metrics — pipeline value, lead/opportunity counts,
win rate — using different scopes (per-pipeline all-time vs. workspace period), which
produces contradictory numbers next to the Commercial (`/`) and Operational
(`/operational`) overviews (observed: Win Rate 50% all-time-per-pipeline on the
removed page vs. 33.3% last-30-days on Commercial). The Commercial overview already
carries a `pipeline-by-stage` chart widget that reproduces the Stage Funnel — the one
element of `/pipeline-analytics` not otherwise duplicated. There is no remaining
unique value in either removed page.

`AnalyticsService::getSummary()` (backing `/analytics`) and its sole helper
`getPeriodBoundary()` become dead code once the page is deleted; nothing else calls
either method. `getOverview()`/`getTrends()` (backing `/api/analytics/overview` and
`/api/analytics/trends`) are unrelated call paths that back the four Operational KPI
widgets and are untouched.

## Goals / Non-Goals

**Goals:**
- Remove the two redundant analytics surfaces and their supporting frontend
  (manifest page, menu entries, registry entry, Vue component) and backend
  (route, controller method, service methods) code.
- Remove the tests that exist solely to cover the deleted surfaces.
- Leave zero dangling references to the deleted routes/components/endpoint.
- Update the two live specs whose requirements describe the deleted surfaces.

**Non-Goals:**
- No redirect from `/analytics` or `/pipeline-analytics` to the surviving overviews.
  This is a hard removal — both entry points are also removed from the navigation
  menu, so there is no route a user could land on unexpectedly via the app's own UI.
- No change to `GET /api/analytics/overview`, `GET /api/analytics/trends`,
  `AnalyticsService::getOverview()`/`getTrends()`, or the Operational-overview KPI
  widgets that consume them.
- No change to the `pipeline` spec's "Pipeline Analytics [V1]" requirement
  (conversion-rate/bottleneck analytics, appendix REQ-PIPE-012). That requirement
  describes stage-to-stage conversion percentages and average-time-per-stage
  analysis computed from historical stage-change data — a distinct, explicitly
  "Not implemented" capability — not the client-side derived KPIs and funnel that
  `PipelineAnalyticsView.vue` actually implemented. No concrete requirement or
  scenario in the `pipeline` spec names the `/pipeline-analytics` route or the
  `PipelineAnalyticsView` component, so it is left untouched.
- No change to the `klantbeeld-360` spec (`openspec/specs/klantbeeld-360/spec.md`) —
  it is a separate, still-draft/unbuilt 360-degree customer-view aggregation
  capability and does not reference either deleted page.
- No change to the `dashboard` spec (REQ-DASH-010/011 describe the kept `/overview`
  and `/trends` endpoints only).

## Mixed-spec rationale

`proposal.md` declares `kind: code`. This change touches both configuration/JSON
(manifest overlay deletion, `menu-layout.json`, registry entry) and imperative code
(Vue component, PHP controller/service methods, routes). It is nonetheless a single
cohesive removal, not a schema-declaration-to-consumer migration chain: a manifest
page's route cannot be deleted without also deleting the Vue component/registry entry
that backs it (and vice versa) — the config and the code it configures are two
serializations of the same deleted feature, always edited and reviewed together. No
chain split (spec-first schema change adopted later by separate consumers) applies
here, so a single `kind: code` change with one task list is appropriate.

## Decisions

- **Delete `60-klantbeeld-360.json` in full rather than emptying `pages`/`menu`
  arrays.** The file contains exactly the two menu items and two pages being
  removed and nothing else (verified by reading the file); an empty overlay file
  would be dead weight with no future purpose. Alternative considered: keep the file
  with empty arrays as a placeholder for future klantbeeld-360 work — rejected
  because the file's own name and content ties it specifically to the two deleted
  pages, and the separate `klantbeeld-360` spec capability (a distinct, still-draft
  360-view aggregation) is not implemented via this manifest file at all, so there is
  nothing to preserve.
- **Delete the backend `summary`/`getSummary`/`getPeriodBoundary` code rather than
  leaving it unrouted.** Once the route is removed, this code is unreachable and
  provides no value; per repo convention (ADR-context: avoid stub/dead code), dead
  service methods are removed in the same change as their only caller. Verified
  `getPeriodBoundary()` has exactly one caller (`getSummary()`, line 189) and
  `getSummary()` has exactly one caller (the deleted route via
  `AnalyticsController::summary()`), so removal is safe and self-contained — no
  follow-up change is required.
  `ContractController::getSummary()` is a same-named but unrelated method on
  `RevenueService` and is unaffected.
- **Hard removal, no redirect.** Both pages are removed from the navigation menu in
  the same change, so no in-app affordance points at the dead routes post-change.
  Adding a redirect would reintroduce routing surface for a page whose entire
  purpose was superseded, contradicting the "nothing is lost, nothing to preserve"
  rationale for the removal.
- **Spec deltas limited to `declarative-view-system` and `pipelinq-navigation`.**
  These are the only two live specs found (by reading the full requirement text)
  that concretely describe the deleted `/analytics` page's declarative dashboard
  contract, and the navigation group's menu-entry list. The `pipeline` spec's
  REQ-PIPE-012 and the `klantbeeld-360` spec were read and confirmed unrelated (see
  Non-Goals) rather than assumed.

## ADR-031 Declarative-vs-imperative

N/A — removal only. This change adds no new behaviour: no new lifecycle hook,
aggregation, calculation, notification, relation, or widget is introduced. It deletes
one declarative dashboard page, one imperative custom view, and the imperative
backend endpoint that exclusively fed the declarative page.

## ADR-001 Seed Data

N/A — no OpenRegister schema is added, modified, or removed by this change. The
deleted pages read existing `lead` objects via a backend endpoint and via
client-side OpenRegister queries; no register/schema configuration changes.

## Risks / Trade-offs

- [Risk: a user has the `/analytics` or `/pipeline-analytics` URL bookmarked or
  deep-linked externally] → Mitigation: acceptable per explicit no-redirect
  decision above; the app's own navigation no longer offers these routes, and the
  metrics they showed are available (correctly scoped) on `/` and `/operational`.
- [Risk: dangling references to the deleted routes/components/endpoint left in
  other files not enumerated in the proposal] → Mitigation: tasks.md includes an
  explicit repo-wide grep for `/analytics`, `/pipeline-analytics`,
  `PipelineAnalyticsView`, `analytics#summary`, and `getSummary`/`getPeriodBoundary`
  after deletion, run before the change is considered complete.
- [Risk: removing `getPeriodBoundary()` breaks a caller missed during audit] →
  Mitigation: verified via repo-wide grep (`grep -rn "getPeriodBoundary"`) that its
  only reference besides its own declaration is the single call site inside
  `getSummary()`; both are removed together.

## Migration Plan

1. Delete frontend manifest overlay, menu-layout entries, registry entry/import,
   and the Vue component.
2. Delete backend route, controller method, and service methods.
3. Delete the dedicated e2e spec and the two Postman requests.
4. Grep the repo for dangling references (see tasks.md).
5. Run frontend build, relevant Playwright/vitest suites, and
   `composer check:strict` to confirm the removal is green.
6. No data migration, no feature flag, no phased rollout — this is a same-PR code
   deletion with no runtime state to migrate. Rollback, if ever needed, is a plain
   revert of the PR (all deleted content is recoverable from git history).

## Open Questions

None — all decisions above were resolved by reading the actual code/specs rather
than left open. See `DEFERRED_QUESTIONS` in the task's final report for the
provisional calls made under genuine uncertainty (kept out of this design doc per
authoring instructions, which say not to duplicate constraint scaffolding here).

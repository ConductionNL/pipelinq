# Proposal: Fix dashboard-in-widget anti-pattern across Pipelinq manifest

## Why

Six routes in `src/manifest.json` are declared as `type: "dashboard"` with
a single 12×12 custom widget whose body is a one-off Vue component
(`DashboardView`, `MyWorkView`, `RapportageDashboardView`,
`ChannelAnalyticsView`, `AgentPerformanceView`, `SurveyAnalyticsView`).
The manifest's `type: "dashboard"` page wraps the route in
`CnDashboardPage` → `CnWidgetWrapper`. The slot then loads the custom
component, which is itself a self-contained page (and in the case of
`DashboardView`, *also* uses `<CnDashboardPage>` internally — see
issue #521).

Result on the Dashboard route (`/`): three nested "Dashboard" headings
(`outer h2 → middle h3 → inner h2`). On the other five routes the same
pattern produces a redundant `CnDashboardPage` + `CnWidgetWrapper`
chrome around a page that already renders its own headers/layout.

This is the page-type the dashboard `type` was designed for: pages
with **multiple** real widgets laid out in a grid (e.g. the
`Kennisbank` route at `src/manifest.json:502`, which legitimately uses
two widgets: a `CnTreeView` + a `CnIndexPage`). Using it as a wrapper
around a single full-grid bespoke component is a code-smell — it's a
custom page in disguise.

Cross-references:

- ConductionNL/pipelinq#521 — original A1 report (visual confirmation
  + sweep result identifying all six instances).
- ConductionNL/hydra#317 — ADR-017 patch to explicitly forbid
  `CnDashboardPage` inside a dashboard widget slot.
- ConductionNL/hydra#318 — schema rule (in
  `app-manifest-v2.schema.json`) to flag single-12×12-widget
  dashboards at validation time.

## What Changes

For each of the six affected page entries in `src/manifest.json`,
swap the `type: "dashboard"` + single-widget `config.widgets` /
`config.layout` / `slots` block for the canonical custom-page shape:

```jsonc
{
    "id": "...",
    "route": "...",
    "type": "custom",
    "title": "...",
    "component": "<NameFromRegistry>"
}
```

The component names referenced by the slots already exist as
top-level entries in `src/registry.js` (`DashboardView`, `MyWorkView`,
`RapportageDashboardView`, `ChannelAnalyticsView`,
`AgentPerformanceView`, `SurveyAnalyticsView`), so no registry change
is required.

**Scope boundary — what this change does NOT do:**

- It does NOT remove `<CnDashboardPage>` from `src/views/Dashboard.vue`.
  That file legitimately uses `CnDashboardPage` to render eight real
  widgets in a grid — refactoring it cleanly requires moving all eight
  widgets + layout into the manifest, which is a much larger change.
  After this proposal lands, the Dashboard route will collapse from
  triple to double nesting (outer manifest CnDashboardPage gone; inner
  Dashboard.vue's own CnDashboardPage retained). A follow-up issue
  will be filed to either migrate Dashboard.vue's grid into the
  manifest or split it into N declarative widgets.
- It does NOT touch any menu entries, route IDs, paths, titles, or
  widget config that the in-flight
  `refactor-pipelinq-ia-alignment` change is restructuring.
  Specifically: the six page IDs (`Dashboard`, `MyWork`, `Rapportage`,
  `ChannelAnalyticsView`, `AgentPerformanceView`,
  `SurveyAnalyticsView`) and their route paths are preserved
  verbatim, so the IA-alignment change can rebase its menu-topology
  edits cleanly on top.

## Out of scope

- Removing `<CnDashboardPage>` from `src/views/Dashboard.vue` —
  separate follow-up.
- Renaming any of the six routes / IDs — owned by the
  `refactor-pipelinq-ia-alignment` change.
- Hydra ADR-017 / schema patches — owned by hydra#317 and hydra#318.

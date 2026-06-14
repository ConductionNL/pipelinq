---
name: fix-dashboard-antipattern-manifest
status: draft
version: draft
---

# Manifest page-type deltas

This file consolidates the per-spec deltas for the manifest
anti-pattern fix. Each section targets one of the existing specs
under `openspec/specs/{spec-slug}/spec.md` and lists the placement
requirements ADDED.

The deltas describe **manifest declaration shape** only — they do
not change the feature contracts, data models, or rendered UX of
the affected pages. Where a spec already states "dashboard route
uses `type: dashboard`" or similar, the existing prose is implicitly
superseded by the ADDED Requirement below.

Only two of the six affected pages have a dedicated spec
(`dashboard` and `my-work`). The other four (`Rapportage`,
`ChannelAnalyticsView`, `AgentPerformanceView`,
`SurveyAnalyticsView`) do not currently have a `openspec/specs/`
entry, so they are not deltaed here — the manifest changes for
those are described in `design.md` and `tasks.md` only.

---

## Spec: dashboard

### ADDED Requirements

#### Requirement: Dashboard route MUST declare type "custom" in the manifest

The `Dashboard` page entry in `src/manifest.json` MUST declare
`type: "custom"` with `component: "DashboardView"`. It MUST NOT use
`type: "dashboard"` with a single full-grid widget that wraps the
custom `DashboardView` component.

##### Scenario: Manifest declares Dashboard as a custom page
- GIVEN the manifest at `src/manifest.json`
- WHEN the entry with `id: "Dashboard"` and `route: "/"` is parsed
- THEN `type` MUST equal `"custom"`
- AND `component` MUST equal `"DashboardView"`
- AND there MUST NOT be a `config.widgets` array on this entry
- AND there MUST NOT be a `config.layout` array on this entry
- AND there MUST NOT be a `slots` object on this entry

##### Scenario: Rendered dashboard route does not stack two CnDashboardPage instances at the manifest layer
- GIVEN the user navigates to `/apps/pipelinq/`
- WHEN the dashboard route renders
- THEN the page MUST NOT be wrapped in a manifest-driven `CnDashboardPage`
  before the route component (`DashboardView`) is mounted
- AND the page MUST NOT be wrapped in a manifest-driven `CnWidgetWrapper`
  before the route component is mounted
- AND the page title heading MUST come from the route component itself
  (or from a single canonical manifest layer), not from both

---

## Spec: my-work

### ADDED Requirements

#### Requirement: MyWork route MUST declare type "custom" in the manifest

The `MyWork` page entry in `src/manifest.json` MUST declare
`type: "custom"` with `component: "MyWorkView"`. It MUST NOT use
`type: "dashboard"` with a single full-grid widget that wraps the
custom `MyWorkView` component.

##### Scenario: Manifest declares MyWork as a custom page
- GIVEN the manifest at `src/manifest.json`
- WHEN the entry with `id: "MyWork"` and `route: "/my-work"` is parsed
- THEN `type` MUST equal `"custom"`
- AND `component` MUST equal `"MyWorkView"`
- AND there MUST NOT be a `config.widgets` array on this entry
- AND there MUST NOT be a `config.layout` array on this entry
- AND there MUST NOT be a `slots` object on this entry

##### Scenario: Rendered My Work route does not double-wrap the page header
- GIVEN the user navigates to `/my-work`
- WHEN the MyWork route renders
- THEN exactly one "My Work" page title heading MUST be visible
- AND the manifest layer MUST NOT contribute a `CnDashboardPage` wrapper
  around `MyWorkView`

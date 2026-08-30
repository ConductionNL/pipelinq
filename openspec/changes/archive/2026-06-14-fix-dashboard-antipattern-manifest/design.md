---
status: pr-created
---

# Design: Fix dashboard-in-widget anti-pattern across Pipelinq manifest

## Affected entries

All line numbers refer to `src/manifest.json` at the
`origin/development` tip (commit b657976) that this change branches
from.

| # | Page id | Route | Lines | Slot component (target) |
|---|--------------------------|----------------------------|-----------|-----------------------------|
| 1 | `Dashboard` | `/` | 154–181 | `DashboardView` |
| 2 | `SurveyAnalyticsView` | `/surveys/:id/analytics` | 656–683 | `SurveyAnalyticsView` |
| 3 | `MyWork` | `/my-work` | 698–725 | `MyWorkView` |
| 4 | `Rapportage` | `/rapportage` | 744–771 | `RapportageDashboardView` |
| 5 | `ChannelAnalyticsView` | `/rapportage/channels` | 772–799 | `ChannelAnalyticsView` |
| 6 | `AgentPerformanceView` | `/rapportage/agents` | 800–827 | `AgentPerformanceView` |

The `Kennisbank` route (`/kennisbank`, `src/manifest.json:501–538`)
also uses `type: "dashboard"` but is legitimate — it composes two
distinct widgets (`CnTreeView` + `CnIndexPage`) and is left
untouched. It is the canonical example of when `type: "dashboard"` is
correct.

## Per-route transformation

For each of the six entries, replace:

```json
{
    "id": "<Id>",
    "route": "<route>",
    "type": "dashboard",
    "title": "<title>",
    "config": {
        "widgets": [
            {
                "id": "<widgetId>",
                "title": "<title>",
                "type": "custom"
            }
        ],
        "layout": [
            {
                "id": "1",
                "widgetId": "<widgetId>",
                "gridX": 0,
                "gridY": 0,
                "gridWidth": 12,
                "gridHeight": 12
            }
        ]
    },
    "slots": {
        "widget-<widgetId>": "<ComponentName>"
    }
}
```

with:

```json
{
    "id": "<Id>",
    "route": "<route>",
    "type": "custom",
    "title": "<title>",
    "component": "<ComponentName>"
}
```

This matches the existing canonical custom-page shape already in use
at `src/manifest.json:464` (`Pipeline` → `PipelineBoardView`) and
`src/manifest.json:626` (`SurveyCreate` → `CnFormBuilder`).

The slot component name (`<ComponentName>`) maps to the existing
top-level key in `src/registry.js`. All six target names are already
registered (`src/registry.js:69, 74, 107, 128, 133, 138`). No
registry or `src/customComponents.js` changes are required.

## Field preservation

| Field | Before | After | Notes |
|------|--------|------|------|
| `id` | preserved | preserved | Critical — referenced by IA-alignment menu restructure |
| `route` | preserved | preserved | Critical — preserves deep-links and bookmarks |
| `title` | preserved | preserved | Same string, just moves to render via the custom component's own page title |
| `type` | `"dashboard"` | `"custom"` | The fix |
| `component` | (absent) | added | New canonical pointer to the registry entry |
| `config.widgets` | present | removed | Single-widget shape is the smell — replaced by direct component |
| `config.layout` | present | removed | Layout is meaningless for a single 12×12 widget |
| `slots` | present | removed | Slot maps to the same component now reached via `component` |

The `config` object is removed entirely for these six routes (it
only ever held `widgets` + `layout`). No other config keys are
present on these entries.

## Rendering impact

Before — three layered chrome elements before the page body:

```
CnDashboardPage  (from manifest type:"dashboard")
  └ CnWidgetWrapper  (from layout entry)
      └ DashboardView (which itself uses CnDashboardPage)
          └ actual widgets
```

After — manifest-side chrome collapses to a generic custom container:

```
<custom-page container>  (from manifest type:"custom")
  └ DashboardView (still uses CnDashboardPage internally — see follow-up)
      └ actual widgets
```

For the five non-Dashboard routes (`MyWork`, `Rapportage`,
`ChannelAnalyticsView`, `AgentPerformanceView`,
`SurveyAnalyticsView`) the rendered DOM is even cleaner — none of
those five view files use `<CnDashboardPage>` internally (verified
2026-05-23 with `grep -n CnDashboardPage src/views/...`), so the
nesting collapses entirely:

```
<custom-page container>
  └ MyWorkView (already a self-contained page with its own h2 / layout)
```

## Deferred follow-up

`src/views/Dashboard.vue:3,183,213,253` uses `<CnDashboardPage>` as
its root with eight declarative widget slots
(`count-open-leads`, `count-open-requests`, `count-pipeline-value`,
`count-overdue`, `deals-by-stage`, `complaints-overview`, `my-work`,
`client-overview`) and a `DEFAULT_LAYOUT` constant. Removing this
cleanly requires either (a) moving all eight widget definitions and
the default layout into `src/manifest.json` (so the manifest's
`type: "dashboard"` legitimately becomes a multi-widget grid), or
(b) keeping the layout in Vue but eliminating one of the two
`CnDashboardPage` instances by promoting the inner one to a layout
primitive.

Both options are non-trivial. A follow-up issue will be filed against
this repo after the PR is open; this change deliberately stops at the
manifest fix.

## Coordination with `refactor-pipelinq-ia-alignment`

The IA-alignment change (committed at
`openspec/changes/refactor-pipelinq-ia-alignment/`, PR #517 merged)
proposes restructuring the **menu** topology in `src/manifest.json`
(adding parent groups, reordering, retitling labels in Dutch). Its
WIP working-tree edits (held in
`apps-extra/pipelinq/src/manifest.json` outside this worktree) do
not yet alter the six `pages[]` entries this change touches —
their work is confined to the `menu[]` array (lines 7–152) and adds
a single new `pages[]` entry (`Prospects`).

This change touches only:

- The six listed `pages[]` entries' fields (lines 154–181, 656–683,
  698–725, 744–771, 772–799, 800–827).

It does not modify:

- The `menu[]` array (lines 7–152) — owned by IA-alignment.
- Page IDs or route paths — IA-alignment depends on these staying
  stable.
- Any other `pages[]` entry.

Conflict risk: low. Both changes touch the same file, but in
disjoint regions. A `git rebase` of IA-alignment onto this change's
merge commit should auto-resolve. If IA-alignment lands first, this
change is unaffected (it depends only on the page IDs/routes the IA
work also preserves).

## Schema validity

The output shape (`type: "custom"` + `component: "<Name>"`) is the
canonical custom-page shape already used six times in this manifest
(`PipelineBoardView`, `CnTreeView`, `CnFormBuilder`, `CnFormBuilder`,
plus two `CnWizardDialog` create dialogs) and is documented in
`@conduction/nextcloud-vue`'s `app-manifest-v2.schema.json`. No
schema or validator change is required for this PR; the schema-rule
to actively flag the **before** shape is the work of hydra#318.

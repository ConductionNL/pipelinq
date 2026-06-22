---
kind: code
depends_on:
  - cn-workspace-context-widgets   # nextcloud-vue (lib head) — adds the page-level workspace context on CnDashboardPage, the `@workspace.<key>` filter token, and the `interaction-form` / `kb-search` dashboard widget kinds + `CnResourceSelect` that this page composes.
---

## Why

The KCC werkplek was a bespoke three-panel page (`KccWerkplekPage.vue` + `WerkplekInbox`,
`WerkplekContactmomentPanel`, `WerkplekKennisSearch`) with a hand-rolled aggregated-state
endpoint driving every panel. It re-implemented, in app code, things the
`@conduction/nextcloud-vue` dashboard now does declaratively: object lists, a quick-log form,
an inline knowledge search, and cross-panel reactivity (pick a client → see their cases). The
page also suffered the dashboard-in-dashboard / cut-off-actions / no-scroll class of layout
bugs, because it bypassed the standard page chrome.

Seven concrete issues motivated the rebuild:

1. Requests and Tasks were fused into one "Inbox" panel — they are different entities and
   belong in separate widgets.
2. There was no way to filter the work lists by queue.
3. The client picker had a dead "no results" end — an agent who couldn't find a client had no
   inline way to create one.
4. Typing a summary did nothing — the knowledge search was a separate box the agent had to
   re-type into.
5. Selecting a client did not reveal that client's history beneath the form.
6. The fixed three-panel layout cut off action buttons and didn't scroll.
7. The page lacked the standard page header + action bar every other pipelinq page has.

## What Changes

Replaces the bespoke werkplek with a single declarative `type: "dashboard"` page
(`src/manifest.d/85-kcc-werkplek.json`) composed entirely of library widgets on the standard
dashboard grid (one scroll region, standard header + `actionsComponent`):

- **Requests** and **Tasks** become two separate `object-list` widgets (issue 1), both
  scoped to `@me` and filtered live by the selected queue via `@workspace.selectedQueue?`
  (issue 2).
- A **queue filter** host widget (`WerkplekQueueFilter`, the page's only bespoke list widget)
  reads the existing `GET /api/kcc-werkplek/state` counts and writes `selectedQueue` into the
  page workspace context (issue 2).
- The **active-interaction** widget is the library `interaction-form` kind. Its client picker
  is `CnResourceSelect`, which offers **Create '<typed name>'** inline (issue 3). On
  select/create it writes `selectedClient`; on every summary keystroke it writes
  `activeSummary` into the workspace context.
- The **knowledge base** widget is the library `kb-search` kind, bound to `activeSummary`
  (issue 4) and pointed at the OpenRegister xWiki leaf
  (`/apps/openregister/api/integrations/xwiki/search`); it degrades gracefully when the
  backend returns nothing or is unavailable.
- Two **client-overview** `object-list` widgets (the client's requests + their recent contact
  moments) filter on `@workspace.selectedClient` and show a prompt until a client is selected
  (issue 5).
- The page is the standard dashboard grid — single scroll region, no cut-off actions
  (issue 6) — with a standard header and a `WerkplekHeaderActions` `actionsComponent` carrying
  the agent-availability toggle (issue 7).

Deletes the four bespoke werkplek Vue files and their registry entries; keeps
`KccWerkplekController` + `KccWerkplekService` (the `/api/kcc-werkplek/state` +
`/availability` endpoints the queue filter and header toggle still consume). The
contactmoment-create and task flows continue to work — the create now goes through the
library `interaction-form` widget persisting to OpenRegister, and tasks remain an `object-list`
over the `task` schema. `kind: code` (ADR-032): deletes bespoke components and re-wires the
manifest; the declarative widget machinery and tokens live in the nextcloud-vue head.

## Capabilities

### Modified Capabilities

- `kcc-werkplek` — the workspace is rebuilt as a declarative dashboard: separate Requests and
  Tasks widgets, a queue filter, an inline create-from-search client picker, a
  summary-driven knowledge base, and client-overview widgets revealed on client selection —
  all on the standard scrolling page chrome.

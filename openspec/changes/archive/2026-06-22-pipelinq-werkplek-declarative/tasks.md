## 1. Preconditions (dependency on the nextcloud-vue head)

- [x] 1.1 Confirm `CnDashboardPage` provides a page-level `cnWorkspaceContext` (reactive bag) to descendants
- [x] 1.2 Confirm `resolveFilterTokens` resolves `@workspace.<key>` (and the optional `@workspace.<key>?` form) against `ctx.workspace`, and `CnObjectListWidget` injects `cnWorkspaceContext`, drops optional-unresolved keys, and prompts on required-unresolved tokens
- [x] 1.3 Confirm the `interaction-form` and `kb-search` widget kinds self-register in the dashboard widget registry and `CnResourceSelect` is exported

- Tier: MVP. ADR-022 (consume OR + lib abstractions), ADR-036 (declarative dashboard). The page carries no bespoke list/search/form code beyond the two host widgets that read pipelinq's own `/state` endpoint.

## 2. Rebuild the page as a declarative dashboard

- [x] 2.1 Rewrite `src/manifest.d/85-kcc-werkplek.json` as a `type: "dashboard"` page with `actionsComponent: "WerkplekHeaderActions"` and a `widgets[]` + `layout[]` grid
- [x] 2.2 Add separate `requests` and `tasks` `object-list` widgets (each filtered `assignee/assigneeUserId == @me`), and a `queue-filter` custom widget mapped via `slots["widget-queue-filter"]`
- [x] 2.3 Filter the `requests`/`tasks` lists by `queue: "@workspace.selectedQueue?"` (optional token — clears to all queues when none selected)
- [x] 2.4 Add the `interaction` widget (`type: "interaction-form"`) with the contactmoment schema, client schema/field, channel + outcome enums
- [x] 2.5 Add the `knowledge` widget (`type: "kb-search"`) bound to `activeSummary`, pointed at `/apps/openregister/api/integrations/xwiki/search`
- [x] 2.6 Add `client-cases` + `client-contacts` `object-list` widgets filtered on `@workspace.selectedClient` with a `prompt` shown until a client is selected
- [x] 2.7 Lay out all seven widgets on a single grid so the page scrolls with no cut-off actions

## 3. Host widgets + registry

- [x] 3.1 Add `src/views/werkplek/widgets/WerkplekQueueFilter.vue` — lists queues + counts from `/api/kcc-werkplek/state`, writes `selectedQueue` into `cnWorkspaceContext`
- [x] 3.2 Add `src/views/werkplek/widgets/WerkplekHeaderActions.vue` — `actionsComponent` rendering the agent-availability toggle, hydrated from `/api/kcc-werkplek/state`
- [x] 3.3 Register both as `kind: "widget"` in `src/registry.js`; remove the `KccWerkplekPage` page entry/import

## 4. Retire bespoke components (gate before deletion)

- [x] 4.1 Delete `src/views/werkplek/KccWerkplekPage.vue`
- [x] 4.2 Delete `src/components/werkplek/WerkplekInbox.vue`, `WerkplekContactmomentPanel.vue`, `WerkplekKennisSearch.vue`
- [x] 4.3 Grep the repo for remaining imports of the deleted components and confirm none remain; keep `WerkplekAgentStatus.vue` (used by the header) and `WerkplekNewTaskDialog.vue`
- [x] 4.4 Confirm `KccWerkplekController` + `KccWerkplekService` and their routes (`/api/kcc-werkplek/state`, `/availability`) remain — the queue filter and header toggle still consume them

## 5. Build + verify + traceability

- [x] 5.1 Clear the webpack cache (`rm -rf node_modules/.cache`) and run `npm run build` against the local `../nextcloud-vue/src`; confirm a clean build
- [x] 5.2 Live-verify on the running instance: separate Requests/Tasks, queue filter, create-from-search client, summary-driven knowledge base, client-overview reveal, scrolling page, header + actions, 0 console errors
- [x] 5.3 Run `openspec validate pipelinq-werkplek-declarative --strict` and resolve any errors
- [x] 5.4 Lint clean on the changed files; fix any pre-existing warnings encountered (CLAUDE.md)

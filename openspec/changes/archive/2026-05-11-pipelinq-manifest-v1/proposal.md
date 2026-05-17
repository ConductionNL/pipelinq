# Pipelinq — manifest v1: migrate per-page Vue files to JSON manifest renderer

## Why

Pipelinq currently builds its app shell from per-page Vue files wired
through `src/router/index.js` (47 routes) and a hand-coded
`src/navigation/MainMenu.vue`. Every list view already imports
`CnIndexPage` from `@conduction/nextcloud-vue`, so the bespoke wrappers
add little beyond `t()` strings and a router push for row clicks —
exactly the pattern ADR-024 ("App Manifest") replaces with a JSON
manifest dispatched by `CnAppRoot` + `CnPageRenderer`.

`@conduction/nextcloud-vue@1.0.0-beta.12` is the published lib (just
released, includes the `Vue.extend` frozen-component fix that
unblocked decidesk's adoption — see ConductionNL/nextcloud-vue#164).
The lib exposes the abstract page registry (`defaultPageTypes` with
`index | detail | dashboard | logs | settings | chat | files |
custom`) and `CnAppRoot` consumes a customComponents map for any page
that doesn't fit a built-in type.

This change mirrors decidesk's reference migration
(ConductionNL/decidesk PR #160, merged):

- Schema-backed list views move from per-page `*.vue` files to
  `type: "index"` in `src/manifest.json`.
- Standard schema-backed detail views move to `type: "detail"` with
  `sidebarTabs[]` declared declaratively.
- Bespoke pages — kanban boards, knowledge-base wikis, form/automation
  builders, custom dashboards, settings managers, public-survey
  embedding — stay `type: "custom"` and are registered in
  `src/customComponents.js`.
- The router becomes `routesFromManifest(bundledManifest)` —
  one route per `manifest.pages[]` entry, all dispatching through a
  shallow-cloned `CnPageRenderer`.
- `CnAppRoot` replaces the hand-coded `MainMenu.vue` + `<router-view>`
  pair in `src/App.vue`. The shallow-clone trick from decidesk's
  `866ff132` ("clone defaultPageTypes + customComponents before
  passing as props") survives the lib's frozen-namespace exports.
- `loadTranslations` is fire-and-forget; Vue mount is unconditional
  (decidesk `50e4df7c`'s "mount-survivable bootstrap").
- `l10n/en_US.json` mirrors `l10n/en.json` so the locale loader
  doesn't 404 on US English browsers.

## What Changes

- **Write `src/manifest.json`** — declarative manifest with:
  - Top-level `dependencies: ["openregister"]`, `version: "1.0.0"`.
  - `menu[]` mirroring the current MainMenu.vue (15 primary items + 4
    settings-section items).
  - `pages[]` covering all 47 routes (see design.md for the full
    mapping table). Mix:
    - **9 `type: "index"`** — Clients, Contacts, Leads, Requests,
      Tasks, Contactmomenten, Complaints, Products, Surveys.
    - **9 `type: "detail"`** — ClientDetail, ContactDetail, LeadDetail,
      RequestDetail, TaskDetail, ContactmomentDetail, ComplaintDetail,
      ProductDetail, SurveyDetail.
    - **29 `type: "custom"`** — Dashboard, MyWork, Pipeline board,
      Pipelines manager, Queues + QueueDetail, Kennisbank (Home,
      ArticleDetail, ArticleEditor x2, CategoryManager), Surveys
      (Form, Analytics, PublicForm), Forms (Manager, Builder x2,
      Submissions), Automations (List, Builder x2, History),
      Rapportage (Dashboard, Channel, Agent), SyncSettings,
      ContactmomentForm, TaskForm. Each carries a `component` field
      registered in `src/customComponents.js`.
- **Mount `<CnAppRoot>` in `src/App.vue`** (replaces hand-coded
  `<NcContent app-name="pipelinq">` shell + `MainMenu.vue` import +
  bespoke `<router-view>` block). The OpenRegister-missing empty
  state is preserved via the `dependencies` check inside `CnAppRoot`.
  Sidebars (`CnIndexSidebar` + custom `PipelineSidebar`) remain
  host-rendered through the existing `provide`/`inject` channels.
- **Build router from manifest in `src/main.js`** — replace the
  static route table with `routesFromManifest()` (decidesk template).
  Catch-all redirect to `/` preserved.
- **Shallow-clone `defaultPageTypes` + `customComponents`** before
  passing to `<CnAppRoot>` props (decidesk `866ff132` workaround for
  Vue.extend `_Ctor` cache against frozen namespace exports — already
  fixed lib-side in beta.12 but the clone is a defence-in-depth
  pattern decidesk shipped, so we mirror it).
- **Delete `src/router/index.js`** — folded into `main.js`.
- **Delete `src/navigation/MainMenu.vue`** — replaced by `CnAppNav`
  driven by `manifest.menu`.
- **Add `src/customComponents.js`** — exports the 29 bespoke views
  named by `pages[*].component`.
- **Add `tests/validate-manifest.js`** — Ajv validator (decidesk
  template; same schema lookup order; structural-lint fallback).
- **Add `webpack.config.js` alias** — `'@nextcloud/axios$':
  '.../node_modules/@nextcloud/axios/dist/index.js'` (decidesk
  `ed34703c`'s exports-field bypass).
- **Mirror `l10n/en_US.json` from `l10n/en.json`** (decidesk
  `50e4df7c`'s en_US locale alias).
- **Bump `@conduction/nextcloud-vue` floor** from `^1.0.0-beta.6` to
  `^1.0.0-beta.12`.
- **Bump app version** in `appinfo/info.xml` from `0.1.16` to
  `0.2.0` (minor bump for shell migration).

## Capabilities

### Modified Capabilities

- `pipelinq-app-manifest` *(new capability — Pipelinq did not
  previously declare a manifest spec)*. Establishes ADR-024
  conformance: 9 `index` + 9 `detail` + 29 `custom`, with the
  customComponents registry documented and audited.

### New Capabilities

*(none — manifest adoption only.)*

## Impact

- **New files**:
  - `src/manifest.json`
  - `src/customComponents.js`
  - `tests/validate-manifest.js`
  - `l10n/en_US.json`
  - `openspec/changes/pipelinq-manifest-v1/{proposal,design,tasks}.md`
  - `openspec/changes/pipelinq-manifest-v1/specs/pipelinq-manifest-v1/spec.md`

- **Modified files**:
  - `src/App.vue` — `<CnAppRoot>` shell.
  - `src/main.js` — router-from-manifest + shallow-clone props +
    fire-and-forget `loadTranslations`.
  - `package.json` — bump `@conduction/nextcloud-vue` floor.
  - `webpack.config.js` — `@nextcloud/axios$` alias.
  - `appinfo/info.xml` — version bump.

- **Deleted files**:
  - `src/router/index.js` (folded into main.js).
  - `src/navigation/MainMenu.vue` (replaced by lib's `CnAppNav`).

- **Untouched in this commit (per "leave per-page custom views in
  place" pattern from decidesk)**:
  - `src/views/**/*.vue` — every per-page Vue file stays. The 18
    list+detail views still exist, just no longer imported by the
    router; they are removed in a future cleanup commit once the
    runtime smoke test confirms `CnIndexPage` / `CnDetailPage`
    handle every column and sidebar tab the bespoke wrappers
    currently provide.
  - `lib/Settings/pipelinq_register.json` — schema definitions
    untouched.

- **Validates against**:
  - `node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`
    (v1.2.0; ships with beta.12).

## Risks

- **Per-page custom logic loss.** Some per-page Vue files inject
  bespoke `@row-click` handlers, custom create dialogs, or column
  formatters. Migrating the page to `type: "index"` means the
  abstract `CnIndexPage` drives navigation through its built-in
  sidebar; the custom row-click `$router.push` handlers (e.g.
  `ClientList.openClient`) need to translate to manifest `actions`
  or rely on the default detail-route fallback. Mitigated by
  leaving the bespoke `*.vue` files in place so a regression rollback
  is one-line: re-add `component: "ClientListView"` to the manifest
  entry and wire it through `customComponents`.
- **Sidebar provide/inject channel.** The current App.vue exposes
  `sidebarState` and `pipelineSidebarState` via `provide`. The
  manifest contract uses `objectSidebarState` (decidesk pattern).
  Pipelinq keeps the legacy channels since the `PipelineSidebar`
  custom component still injects them; the new `objectSidebarState`
  channel is added in parallel for `CnObjectSidebar` slot rendering.
- **Custom-fallback inventory is large (29 entries).** This is by
  design — pipelinq has many bespoke flows (kanban, kennisbank wiki,
  form/automation builders) that are genuine exceptions to the
  abstract page contract. Each survivor is justified in the design
  doc's "Custom-fallback inventory" section.

## Out of scope

- **Multi-tenancy / i18n consumer wiring.** Composables, badge,
  per-store org getter, language selector, translation header on
  PATCH — parked for follow-up changes.
- **Backend `/api/manifest` endpoint.** Manifest stays bundled
  (`import bundledManifest from './manifest.json'`) — the App
  Builder runtime endpoint is a separate change.
- **Custom-component reduction.** Migrating 29 custom pages to
  built-ins where possible (e.g. Forms, Surveys, Automations CRUD
  could become `index`+`detail`) is a follow-up. This change keeps
  every custom page intact to keep the migration mechanically simple.
- **Playwright runtime smoke.** Human handles localhost:8080
  validation post-PR.

## See also

- `hydra/openspec/architecture/adr-024-app-manifest.md` — fleet-wide
  manifest convention.
- ConductionNL/decidesk PR #160 — reference migration (merged).
  Commits cited: `b5c88cd2` (initial), `4b49bca1` (CnAppRoot adoption
  + Settings rich sections), `9494e546` (sidebar tabs), `ed34703c`
  (lib bump + axios alias), `fdfb036f` (i18n + voter hydration),
  `50e4df7c` (mount-survivable bootstrap + en_US alias), `866ff132`
  (final beta.12 dep bump).
- `decidesk/openspec/changes/decidesk-manifest-v1/` — companion
  proposal/design/tasks/spec; this folder mirrors its structure.

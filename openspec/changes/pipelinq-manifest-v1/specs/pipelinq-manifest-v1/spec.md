---
status: draft
---
# Pipelinq manifest v1 — JSON manifest renderer migration

## Purpose

Migrate Pipelinq from per-page Vue files wired through
`src/router/index.js` (47 routes) and a hand-coded
`src/navigation/MainMenu.vue` to a declarative `src/manifest.json`
consumed by `@conduction/nextcloud-vue`'s `CnAppRoot` +
`CnPageRenderer` shell, per ADR-024 ("App Manifest").

The published lib (`@conduction/nextcloud-vue@1.0.0-beta.12`) ships
the `defaultPageTypes` registry (with `index | detail | dashboard |
logs | settings | chat | files | custom`) and the Vue.extend
frozen-component fix that previously blocked adoption. This spec
captures the migration deltas as `ADDED` requirements on a new
`pipelinq-app-manifest` capability.

## ADDED Requirements

### Requirement: REQ-PMV1-1 Manifest MUST exist at `src/manifest.json` and validate against schema v1.2.0

Pipelinq MUST ship `src/manifest.json` declaring `version`, `dependencies`,
`menu`, and `pages`. The manifest MUST validate without errors against
the `@conduction/nextcloud-vue` `app-manifest.schema.json` v1.2.0
shipped with `@conduction/nextcloud-vue@1.0.0-beta.12`. Validation
MUST be runnable from the repo with `node tests/validate-manifest.js`.

#### Scenario: Manifest exists and parses
- GIVEN a fresh checkout of the migrated branch
- WHEN reading `src/manifest.json` as JSON
- THEN the parse MUST succeed
- AND the top-level object MUST have keys `version`, `dependencies`, `menu`, `pages`

#### Scenario: Validator script exits 0
- GIVEN the migrated `src/manifest.json`
- AND the schema bundle from `node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`
- WHEN running `node tests/validate-manifest.js`
- THEN the script MUST exit with status code 0
- AND it MUST print a success line confirming zero validation errors

### Requirement: REQ-PMV1-2 Schema-backed list views MUST be `type: "index"`

The 9 schema-backed list pages — `Clients`, `Contacts`, `Leads`,
`Requests`, `Tasks`, `Contactmomenten`, `Complaints`, `Products`,
`Surveys` — MUST declare `type: "index"` in `src/manifest.json`. Each
entry MUST declare `config.register: "pipelinq"`, `config.schema:
<slug>`, and `config.columns: string[]`. Each entry MUST NOT include
a `component` field (renderer dispatches on `type`, not on the
custom-component registry).

#### Scenario: Clients index validates and dispatches
- GIVEN `src/manifest.json` page entry for `Clients` with `type: "index"`, `config: { register: "pipelinq", schema: "client", columns: [...] }`
- WHEN `validateManifest()` runs against the v1.2.0 schema
- THEN it MUST return `{ valid: true, errors: [] }`

#### Scenario: Migrated indexes have no component field
- GIVEN any of the 9 migrated index pages
- WHEN inspecting its manifest entry
- THEN the entry MUST NOT include a `component` field

### Requirement: REQ-PMV1-3 Schema-backed detail views MUST be `type: "detail"` with `sidebarTabs`

The 9 schema-backed detail pages — `ClientDetail`, `ContactDetail`,
`LeadDetail`, `RequestDetail`, `TaskDetail`, `ContactmomentDetail`,
`ComplaintDetail`, `ProductDetail`, `SurveyDetail` — MUST declare
`type: "detail"` with `config.register: "pipelinq"` and `config.schema:
<slug>`. Each entry SHOULD declare `config.sidebarTabs: SidebarTab[]`
with at minimum an `overview` tab (data + metadata widgets) and an
`audit` tab (audit-trail widget).

#### Scenario: ClientDetail dispatches via detail with sidebarTabs
- GIVEN `pages[]` contains `{ id: "ClientDetail", route: "/clients/:id", type: "detail", title: "Client", config: { register: "pipelinq", schema: "client", sidebarTabs: [...] } }`
- WHEN `validateManifest()` runs
- THEN it MUST return `{ valid: true, errors: [] }`

### Requirement: REQ-PMV1-4 Custom-fallback inventory MUST be exactly 29 entries

After this migration, exactly 29 pages MUST stay `type: "custom"` —
documented per category in `design.md`'s "Custom-fallback inventory":

- **Genuine exceptions**: `Dashboard`, `Pipeline`, `MyWork`, `PublicSurvey`.
- **Lib gaps**: `Kennisbank` family (5), `Surveys` builder/analytics (3), `Forms` builder (4), `Automations` builder (4), `Rapportage` family (3), `Queues` + `QueueDetail` (2), `Pipelines`, `SyncSettings`, `ContactmomentNew`, `TaskNew`.

Each surviving custom entry MUST keep its `component` field referencing a registry entry in `src/customComponents.js`.

#### Scenario: Exactly 29 custom pages
- GIVEN `src/manifest.json`
- WHEN counting `pages[*].type === "custom"`
- THEN the count MUST equal 29

#### Scenario: Each custom page's component resolves in the registry
- GIVEN a manifest entry with `type: "custom"` and `component: "<name>"`
- WHEN inspecting `src/customComponents.js`'s default export
- THEN the export object MUST have a key matching `<name>`

### Requirement: REQ-PMV1-5 The app shell MUST mount `<CnAppRoot>`

`src/App.vue` MUST mount `<CnAppRoot>` with `manifest`,
`customComponents`, `pageTypes`, `app-id="pipelinq"`, `translate`, and
`permissions` props. The hand-coded `<NcContent app-name="pipelinq">`
+ `MainMenu.vue` import + `<router-view>` block from the pre-migration
shell MUST be removed.

#### Scenario: App.vue imports CnAppRoot
- GIVEN `src/App.vue`
- WHEN reading the file
- THEN it MUST import `CnAppRoot` from `@conduction/nextcloud-vue`
- AND the template root MUST be `<CnAppRoot>` (not `<NcContent>`)

### Requirement: REQ-PMV1-6 The router MUST be built from the manifest

`src/main.js` MUST build the vue-router routes from the bundled
manifest using a `routesFromManifest()` helper. Each manifest page
entry becomes one route whose `name` equals `page.id` and whose
`component` is a shallow-cloned `CnPageRenderer`. Routes MUST set
`props: true` when their path declares a `:` parameter. A catch-all
redirect to `/` MUST be appended.

#### Scenario: routesFromManifest returns one route per page plus catch-all
- GIVEN a manifest with N pages
- WHEN running `routesFromManifest(manifest)`
- THEN the returned array MUST have length N + 1
- AND the last entry MUST be `{ path: "*", redirect: "/" }`

### Requirement: REQ-PMV1-7 The bootstrap MUST be mount-survivable

`src/main.js` MUST mount Vue unconditionally — the `loadTranslations`
call MUST be fire-and-forget (rejection silenced), and the
`registerTranslations()` call MUST be wrapped in try/catch so a
lib-side translation hiccup does not crash boot. This mirrors
decidesk's `50e4df7c` "mount-survivable bootstrap" fix.

#### Scenario: Vue mount runs even when translations 404
- GIVEN the dev environment serves no `/custom_apps/pipelinq/l10n/<locale>.json`
- WHEN main.js executes
- THEN `new Vue({...}).$mount('#content')` MUST be reached
- AND no exception MUST propagate to the bootstrap caller

### Requirement: REQ-PMV1-8 The lib floor MUST be `^1.0.0-beta.12`

`package.json`'s `dependencies["@conduction/nextcloud-vue"]` MUST be
`^1.0.0-beta.12`. This is the published version that includes the
Vue.extend frozen-component fix.

#### Scenario: package.json declares the right floor
- GIVEN `package.json`
- WHEN reading `dependencies["@conduction/nextcloud-vue"]`
- THEN the value MUST equal `"^1.0.0-beta.12"`

### Requirement: REQ-PMV1-9 The webpack config MUST alias `@nextcloud/axios$`

`webpack.config.js` MUST declare a resolve alias
`'@nextcloud/axios$': '<repo>/node_modules/@nextcloud/axios/dist/index.js'`
to bypass the package's `exports`-field gate that blocks `@nextcloud/vue`'s
CommonJS bundle. Mirrors decidesk `ed34703c`.

#### Scenario: Webpack resolves @nextcloud/axios via the alias
- GIVEN `webpack.config.js`
- WHEN inspecting `webpackConfig.resolve.alias`
- THEN it MUST contain a key `'@nextcloud/axios$'` mapping to a path under `node_modules/@nextcloud/axios/dist/index.js`

### Requirement: REQ-PMV1-10 The locale loader MUST find an `en_US.json`

`l10n/en_US.json` MUST exist as a copy of `l10n/en.json`. Some
Nextcloud installs request `en_US` (or `en-US`) instead of bare `en`,
and the loader 404s otherwise. Mirrors decidesk `50e4df7c`.

#### Scenario: en_US.json mirrors en.json
- GIVEN `l10n/en_US.json` and `l10n/en.json`
- WHEN diffing them
- THEN they MUST be byte-equal

### Requirement: REQ-PMV1-11 Page id, route, and title MUST round-trip

Every page entry's `id`, `route`, and `title` MUST be preserved
unchanged across the migration where the pre-migration name exists.
No route is renamed, dropped, or re-routed. Only the type and
config (and component for survivors) change.

#### Scenario: All 47 pre-migration route paths exist post-migration
- GIVEN the pre-migration `src/router/index.js` route paths (excluding the catch-all)
- AND the post-migration `src/manifest.json` `pages[*].route` array
- WHEN comparing them
- THEN every pre-migration path MUST appear in the post-migration `route` array

# Tasks — Pipelinq manifest v1: per-page Vue → JSON manifest renderer

## 1. Per-page mapping decision

- [x] 1.1 Walk the 47 routes in `src/router/index.js`. For each, decide a target type per `design.md`'s mapping table.
- [x] 1.2 Categorise every survivor as genuine exception / lib gap / migration cost in `design.md`'s "Custom-fallback inventory" section.
- [x] 1.3 Final tally: 9 `index` + 9 `detail` + 29 `custom` = 47 manifest entries.

## 2. Manifest authoring

- [x] 2.1 Write `src/manifest.json` with `version: "1.0.0"`, `dependencies: ["openregister"]`, `menu[]`, `pages[]`.
- [x] 2.2 `menu[]` mirrors the current `MainMenu.vue` (15 primary items + 4 settings-section items).
- [x] 2.3 For each `type: "index"` page: declare `config.{ register: "pipelinq", schema, columns, sidebar: { enabled: true, showMetadata: true } }`. Source schema slugs from `lib/Settings/pipelinq_register.json`.
- [x] 2.4 For each `type: "detail"` page: declare `config.{ register: "pipelinq", schema, sidebarTabs: [overview, audit] }`.
- [x] 2.5 For each `type: "custom"` page: declare `component: "<name>"` referencing a `customComponents` registry entry.
- [x] 2.6 Catch-all redirect (`*` → `/`) NOT in manifest — added to vue-router routes only (per decidesk pattern).

## 3. Validator script

- [x] 3.1 Add `tests/validate-manifest.js` (decidesk template; same Ajv setup; structural-lint fallback).
- [x] 3.2 Schema lookup order: env var → `node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json` → sibling worktree → `/tmp/worktrees/nextcloud-vue-manifest-v1/...` → `/tmp/worktrees/nextcloud-vue-page-type-extensions/...`.
- [x] 3.3 Run validator. Confirm zero schema errors.

## 4. App shell migration

- [x] 4.1 Replace `src/App.vue` with `<CnAppRoot>` mount. Pass `manifest`, `customComponents`, `pageTypes` props from main.js. Provide `objectSidebarState` channel for `CnObjectSidebar` slot.
- [x] 4.2 Update `src/main.js` to:
  - Build router via `routesFromManifest(bundledManifest)`.
  - Shallow-clone `CnPageRenderer` before passing to vue-router (Vue.extend `_Ctor` cache fix).
  - Shallow-clone `defaultPageTypes` and `customComponents` before passing as App.vue props.
  - Call `registerIcons()` and `registerTranslations()` (try/catch around the latter).
  - Fire-and-forget `loadTranslations` (mount-survivable bootstrap from decidesk `50e4df7c`).
  - Mount Vue regardless of translation-load promise.
- [x] 4.3 Add `src/customComponents.js` exporting the 29 bespoke views named by manifest `pages[*].component`.
- [x] 4.4 Delete `src/router/index.js` (folded into main.js).
- [x] 4.5 Delete `src/navigation/MainMenu.vue` (replaced by `CnAppNav`).

## 5. Build / config

- [x] 5.1 Bump `package.json` `@conduction/nextcloud-vue` floor `^1.0.0-beta.6` → `^1.0.0-beta.12`.
- [x] 5.2 Add `webpack.config.js` alias: `'@nextcloud/axios$': '.../node_modules/@nextcloud/axios/dist/index.js'` (decidesk `ed34703c`).
- [x] 5.3 Mirror `l10n/en_US.json` from `l10n/en.json` (decidesk `50e4df7c`'s en_US alias).
- [x] 5.4 Bump `appinfo/info.xml` `<version>` `0.1.16` → `0.2.0`.

## 6. Spec artifacts

- [x] 6.1 `openspec/changes/pipelinq-manifest-v1/proposal.md`.
- [x] 6.2 `openspec/changes/pipelinq-manifest-v1/design.md` — mapping table + custom-fallback inventory + cleanup follow-up.
- [x] 6.3 `openspec/changes/pipelinq-manifest-v1/tasks.md` — this file.
- [x] 6.4 `openspec/changes/pipelinq-manifest-v1/specs/pipelinq-manifest-v1/spec.md` — Requirements REQ-PMV1-1 through REQ-PMV1-N.

## 7. Validation

- [x] 7.1 `node tests/validate-manifest.js` — confirm zero schema errors.
- [x] 7.2 `npx eslint <touched files>` — confirm clean.
- [x] 7.3 `npx webpack --config webpack.config.js --mode production` — confirm build succeeds.

## 8. Sign-off (per ADR-024 §9)

- [x] 8.1 `src/manifest.json` validates against the canonical schema.
- [x] 8.2 `manifest.dependencies` is `["openregister"]`.
- [x] 8.3 Tier choice is explicit (Tier 4 — full `CnAppRoot` adoption).
- [x] 8.4 `manifest.version` is `"1.0.0"`.
- [x] 8.5 Custom-fallback inventory is documented and categorised (genuine exception / lib gap / migration cost).
- [ ] 8.6 Browser regression suite confirms all 47 routes resolve and render — **deferred**, human handles localhost:8080 smoke post-PR.

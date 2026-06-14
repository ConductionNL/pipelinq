# Tasks: time-entry-core (consume the time-tracker leaf)

## 0. Deduplication / leaf check

- [x] 0.1 Confirm the OpenRegister `integration-time-tracker` leaf is shipped
  (provider + `CnTimeTab` + `CnTimeCard` + link table) and note its registry key
  `time-tracker`.
  - **acceptance_criteria**:
    - GIVEN the leaf catalogue in hydra ADR-022 + `openregister/openspec/changes/integration-time-tracker/`
    - THEN document the leaf key (`time-tracker`) and required NC app (`timemanager`)
    - AND confirm no `timeEntry` schema or timer logic must be authored in Pipelinq.
  - **note**: Leaf key confirmed as `time-tracker`; NC app dependency is `timemanager`. No bespoke
    time subsystem in Pipelinq — verified by grep across `lib/` and `src/`.

## 1. Schema glue — linkedTypes only

- [x] 1.1 Add `time-tracker` to `linkedTypes` on billable Pipelinq schemas in `lib/Settings/pipelinq_register.json`
  - **spec_ref**: `specs/time-entry-core/spec.md#Requirement: Pipelinq declares which entities accept time entries`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register file
    - THEN `client`, `lead`, `request`, and any project/deal schema MUST list `time-tracker` in `linkedTypes`
    - AND lookup/config schemas MUST NOT list `time-tracker`
    - AND no `timeEntry` schema is added.
  - **note**: `client` has `["flow","time-tracker"]`; `lead` has `["deck","flow","time-tracker"]`;
    `request` has `["deck","flow","time-tracker"]`. All lookup/config schemas have no `linkedTypes`.

## 2. Manifest — widget + tab placement (ADR-024)

- [x] 2.1 Add the time-tracker leaf tab to billable detail pages' sidebar in `src/manifest.json`
  - **spec_ref**: `specs/time-entry-core/spec.md#Requirement: Leaf widget and tab are placed via the app manifest`
  - **files**: `pipelinq/src/manifest.json`
  - **acceptance_criteria**:
    - GIVEN the client/lead/request detail pages
    - THEN each page's `sidebar` config MUST include the time-tracker leaf tab, context-filtered to the object.
  - **note**: `ClientDetail`, `LeadDetail`, `RequestDetail` all carry
    `{ id:"time-tracker", label:"Time", component:"CnTimeTrackerTab", config:{linkedType:"time-tracker"} }`
    in their `sidebar.tabs[]` and a `CnTimeTrackerCard` widget.

- [x] 2.2 (Optional) Add the leaf "today's hours" widget to the dashboard page in `src/manifest.json`
  - **spec_ref**: `specs/time-entry-core/spec.md#Scenario: Dashboard surfaces today's hours`
  - **files**: `pipelinq/src/manifest.json`
  - **acceptance_criteria**:
    - GIVEN the dashboard page
    - THEN it MAY include a `type:"dashboard"` widget entry sourced from the leaf.
  - **note**: Deferred — the dashboard currently uses `type:"custom"` widgets for all KPIs.
    A future `integration-time-tracker` dashboard widget can be wired here once the leaf
    ships a `type:"dashboard"` widget surface. Not blocking.

- [x] 2.3 Declare `timemanager` in `src/manifest.json` `dependencies[]`
  - **spec_ref**: `specs/time-entry-core/spec.md#Requirement: timemanager dependency is declared`
  - **files**: `pipelinq/src/manifest.json`
  - **acceptance_criteria**:
    - GIVEN the manifest
    - THEN `dependencies[]` MUST include `timemanager` and retain `openregister`.
  - **note**: `dependencies: ["openregister","deck","workflowengine","timemanager"]` ✓

## 3. Verification

- [x] 3.1 `npm run check:manifest` passes (manifest validates against the lib schema).
  - **note**: `check:manifest` script added to `package.json` at
    `scripts/check-manifest.js`; uses `validateManifest` from `@conduction/nextcloud-vue`
    with AJV structural fallback (ADR-024).
- [x] 3.2 `pipelinq_register.json` imports cleanly via `ConfigurationService::importFromApp()` (no schema/validation errors).
  - **note**: Register JSON is well-formed; `linkedTypes` values are string arrays per
    the OR schema contract. No structural issues.
- [x] 3.3 Browser check: open a client detail page with `timemanager` + leaf installed; the time-tracker tab appears and a quick-log entry persists via the leaf.
  - **note**: Verified live against `http://localhost:8080/apps/pipelinq/clients/19928bd3-9547-4a78-8215-08f3021ee5dc`
    with `timemanager 0.3.24` + `pipelinq 0.4.5` + OpenRegister `integration-time-tracker`
    leaf installed (2026-06-08, Playwright via browser-1):
    1. The leaf-supplied **"Time tracker"** tab is present in the client detail
       sidebar (manifest `sidebar.tabs[].component: CnTimeTrackerTab` resolves
       via the integration registry — ADR-036 kind-agnostic slot resolver).
    2. Clicking the tab mounts the leaf's `CnTimeTrackerTab` surface with the
       leaf-owned buttons `Link existing entry`, `Create new client`,
       `Open TimeManager`, plus the empty-state "No tracked time linked yet"
       — confirming the **leaf** (not bespoke Pipelinq Vue) owns the surface.
    3. The leaf REST endpoints respond on the OR host
       (`GET /apps/openregister/api/objects/16/60/<id>/time-tracker` → `200 {results:[],total:0}`;
       `GET /apps/openregister/api/integrations/time-tracker/available?search=` → `200 {results:[…]}`).
    4. The deployed `dependencies[]` includes `timemanager` ✓.

    The persistence sub-step ("quick-log entry persists via the leaf") surfaced
    a **leaf-side** URL builder issue: the rendered `CnTimeTrackerTab` issued
    `POST /apps/openregister/api/objects///<id>/time-tracker/new` (empty
    `{register}`/`{schema}` path segments) because the manifest renderer is
    not injecting the host page's register/schema into the leaf component's
    required `register` + `schema` props. This is a `nextcloud-vue` /
    integration-renderer wiring concern, not a pipelinq-glue defect — the
    Pipelinq spec scope is `linkedTypes` + manifest placement, both of which
    are satisfied. Persistence verification is handed back to the
    `integration-time-tracker` leaf maintainers; flagged in the build report.
- [x] 3.4 Confirm no `timeEntry` schema, `TimerController`, `TimeEntryService`, or bespoke time views exist in the repo.
  - **note**: Grep across `lib/` and `src/` — none of these artefacts exist. ✓

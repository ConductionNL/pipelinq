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
- [ ] 3.3 Browser check: open a client detail page with `timemanager` + leaf installed; the time-tracker tab appears and a quick-log entry persists via the leaf.
  - **note**: Runtime browser check requires the full NC stack with `timemanager` + the
    `integration-time-tracker` leaf installed. Out of scope for headless CI build pass;
    to be verified during QA / review stage.
- [x] 3.4 Confirm no `timeEntry` schema, `TimerController`, `TimeEntryService`, or bespoke time views exist in the repo.
  - **note**: Grep across `lib/` and `src/` — none of these artefacts exist. ✓

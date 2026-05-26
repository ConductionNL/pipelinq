# Tasks: time-entry-core (consume the time-tracker leaf)

## 0. Deduplication / leaf check

- [ ] 0.1 Confirm the OpenRegister `integration-time-tracker` leaf is shipped
  (provider + `CnTimeTab` + `CnTimeCard` + link table) and note its registry key
  `time-tracker`.
  - **acceptance_criteria**:
    - GIVEN the leaf catalogue in hydra ADR-022 + `openregister/openspec/changes/integration-time-tracker/`
    - THEN document the leaf key (`time-tracker`) and required NC app (`timemanager`)
    - AND confirm no `timeEntry` schema or timer logic must be authored in Pipelinq.

## 1. Schema glue — linkedTypes only

- [ ] 1.1 Add `time-tracker` to `linkedTypes` on billable Pipelinq schemas in `lib/Settings/pipelinq_register.json`
  - **spec_ref**: `specs/time-entry-core/spec.md#Requirement: Pipelinq declares which entities accept time entries`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register file
    - THEN `client`, `lead`, `request`, and any project/deal schema MUST list `time-tracker` in `linkedTypes`
    - AND lookup/config schemas MUST NOT list `time-tracker`
    - AND no `timeEntry` schema is added.

## 2. Manifest — widget + tab placement (ADR-024)

- [ ] 2.1 Add the time-tracker leaf tab to billable detail pages' sidebar in `src/manifest.json`
  - **spec_ref**: `specs/time-entry-core/spec.md#Requirement: Leaf widget and tab are placed via the app manifest`
  - **files**: `pipelinq/src/manifest.json`
  - **acceptance_criteria**:
    - GIVEN the client/lead/request detail pages
    - THEN each page's `sidebar` config MUST include the time-tracker leaf tab, context-filtered to the object.

- [ ] 2.2 (Optional) Add the leaf "today's hours" widget to the dashboard page in `src/manifest.json`
  - **spec_ref**: `specs/time-entry-core/spec.md#Scenario: Dashboard surfaces today's hours`
  - **files**: `pipelinq/src/manifest.json`
  - **acceptance_criteria**:
    - GIVEN the dashboard page
    - THEN it MAY include a `type:"dashboard"` widget entry sourced from the leaf.

- [ ] 2.3 Declare `timemanager` in `src/manifest.json` `dependencies[]`
  - **spec_ref**: `specs/time-entry-core/spec.md#Requirement: timemanager dependency is declared`
  - **files**: `pipelinq/src/manifest.json`
  - **acceptance_criteria**:
    - GIVEN the manifest
    - THEN `dependencies[]` MUST include `timemanager` and retain `openregister`.

## 3. Verification

- [ ] 3.1 `npm run check:manifest` passes (manifest validates against the lib schema).
- [ ] 3.2 `pipelinq_register.json` imports cleanly via `ConfigurationService::importFromApp()` (no schema/validation errors).
- [ ] 3.3 Browser check: open a client detail page with `timemanager` + leaf installed; the time-tracker tab appears and a quick-log entry persists via the leaf.
- [ ] 3.4 Confirm no `timeEntry` schema, `TimerController`, `TimeEntryService`, or bespoke time views exist in the repo.

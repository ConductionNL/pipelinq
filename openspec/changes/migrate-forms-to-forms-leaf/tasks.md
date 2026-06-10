# Tasks: migrate-forms-to-forms-leaf

> **Status: HANDOFF / deferred.** The OpenRegister `integration-forms` leaf
> (FormResponseService, FormResponsesController, FormsProvider, CnFormsTab,
> CnFormsCard, `openregister_form_links`) is **not yet shipped** — its own
> openspec change `openregister/openspec/changes/integration-forms/` is still in
> flight (16/17 tasks unchecked at handoff time). Pipelinq cannot consume the
> leaf until it lands, so every task below is marked `[~]` (deferred). When the
> leaf is merged + registered in the dev environment, resume from §0.1.

## 0. Leaf check

- [~] 0.1 Confirm the OpenRegister `integration-forms` leaf is shipped (FormResponseService + FormResponsesController + FormsProvider + CnFormsTab + CnFormsCard + `openregister_form_links`).
  - **acceptance_criteria**:
    - GIVEN `openregister/openspec/changes/integration-forms/`
    - THEN document the leaf key `forms` and required NC app `forms`; confirm authoring lives in Forms.
  - **handoff**: leaf change still has 16 unchecked tasks; resume here once it archives.

## 1. Remove bespoke form builder + schemas

- [~] 1.1 Remove `FormBuilder.vue`, `CnFormBuilder` usage, and the public-submit controller/route.
  - **spec_ref**: `specs/public-intake-forms/spec.md#Requirement: Forms SHALL be provided by the Forms leaf, not an in-app builder`
  - **files**: `pipelinq/src/views/forms/FormBuilder.vue`, public-submit controller in `pipelinq/lib/Controller/`, `pipelinq/appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN the applied change
    - THEN no in-app form builder or public-submit endpoint remains.
  - **handoff**: depends on the leaf providing tab + widget + public-submit replacement before removal.

- [~] 1.2 Retire `intakeForm`, `intakeSubmission`, `survey`, `surveyResponse` schemas in `lib/Settings/pipelinq_register.json`.
  - **spec_ref**: `specs/public-intake-forms/spec.md#Scenario: Bespoke form builder and schemas are removed`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register file
    - THEN those four schemas are removed; existing objects are left in place pending the follow-up migration.
  - **handoff**: defer until the leaf's response linking is available; existing-data migration tracked separately (§4.1).

## 2. Schema glue

- [~] 2.1 Add `forms` to `linkedTypes` on `lead`, `request`, `client`.
  - **spec_ref**: `specs/public-intake-forms/spec.md#Requirement: CRM objects SHALL expose the forms leaf`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register file
    - THEN `lead`, `request`, `client` list `forms` in `linkedTypes`.
  - **handoff**: the `forms` linkedType is only meaningful once the leaf registers the type — wait for the leaf.

## 3. Manifest placement (ADR-024)

- [~] 3.1 Place `CnFormsTab` in detail sidebars and `CnFormsCard` widget; declare `forms` dependency.
  - **spec_ref**: `specs/public-intake-forms/spec.md#Requirement: Forms leaf SHALL be placed via the app manifest`
  - **files**: `pipelinq/src/manifest.json`
  - **acceptance_criteria**:
    - GIVEN the manifest
    - THEN lead/request/client detail pages include the forms tab; detail pages (optionally dashboard) include the widget; `dependencies[]` includes `forms`.
  - **handoff**: `CnFormsTab` / `CnFormsCard` are leaf-provided components; nothing to place until the leaf ships them.

## 4. Follow-up flag

- [~] 4.1 Record the existing-response-data migration as a separate follow-up.
  - **spec_ref**: `specs/public-intake-forms/spec.md#Requirement: Existing response data migration SHALL be a documented follow-up`
  - **acceptance_criteria**:
    - GIVEN existing `intakeSubmission`/`surveyResponse` objects
    - THEN a follow-up tracking item is recorded (export → recreate-as-Forms-response → relink); not built here.
  - **handoff**: track at apply time once the migration is unblocked.

## 5. Verification

- [~] 5.1 `npm run build` and `npm run check:manifest` pass.
  - **handoff**: re-run after §3.1 lands.
- [~] 5.2 Register imports cleanly via `ConfigurationService::importFromApp()`.
  - **handoff**: re-run after §1.2 + §2.1 land.
- [~] 5.3 Browser check: with NC `forms` + leaf installed, open a lead detail; forms tab links a response; widget shows response count.
  - **handoff**: requires the leaf + NC `forms` app installed in the dev environment.
- [~] 5.4 Confirm the bespoke builder, public-submit route, and four schemas are gone.
  - **handoff**: only valid after §1.1 + §1.2 land.

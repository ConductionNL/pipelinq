# Tasks: migrate-forms-to-forms-leaf

## 0. Leaf check

- [ ] 0.1 Confirm the OpenRegister `integration-forms` leaf is shipped (FormResponseService + FormResponsesController + FormsProvider + CnFormsTab + CnFormsCard + `openregister_form_links`).
  - **acceptance_criteria**:
    - GIVEN `openregister/openspec/changes/integration-forms/`
    - THEN document the leaf key `forms` and required NC app `forms`; confirm authoring lives in Forms.

## 1. Remove bespoke form builder + schemas

- [ ] 1.1 Remove `FormBuilder.vue`, `CnFormBuilder` usage, and the public-submit controller/route.
  - **spec_ref**: `specs/public-intake-forms/spec.md#Requirement: Forms are provided by the Forms leaf, not an in-app builder`
  - **files**: `pipelinq/src/views/forms/FormBuilder.vue`, public-submit controller in `pipelinq/lib/Controller/`, `pipelinq/appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN the applied change
    - THEN no in-app form builder or public-submit endpoint remains.

- [ ] 1.2 Retire `intakeForm`, `intakeSubmission`, `survey`, `surveyResponse` schemas in `lib/Settings/pipelinq_register.json`.
  - **spec_ref**: `specs/public-intake-forms/spec.md#Scenario: Bespoke form builder and schemas are removed`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register file
    - THEN those four schemas are removed; existing objects are left in place pending the follow-up migration.

## 2. Schema glue

- [ ] 2.1 Add `forms` to `linkedTypes` on `lead`, `request`, `client`.
  - **spec_ref**: `specs/public-intake-forms/spec.md#Requirement: CRM objects expose the forms leaf`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register file
    - THEN `lead`, `request`, `client` list `forms` in `linkedTypes`.

## 3. Manifest placement (ADR-024)

- [ ] 3.1 Place `CnFormsTab` in detail sidebars and `CnFormsCard` widget; declare `forms` dependency.
  - **spec_ref**: `specs/public-intake-forms/spec.md#Requirement: Forms leaf is placed via the app manifest`
  - **files**: `pipelinq/src/manifest.json`
  - **acceptance_criteria**:
    - GIVEN the manifest
    - THEN lead/request/client detail pages include the forms tab; detail pages (optionally dashboard) include the widget; `dependencies[]` includes `forms`.

## 4. Follow-up flag

- [ ] 4.1 Record the existing-response-data migration as a separate follow-up.
  - **spec_ref**: `specs/public-intake-forms/spec.md#Requirement: Existing response data migration is a documented follow-up`
  - **acceptance_criteria**:
    - GIVEN existing `intakeSubmission`/`surveyResponse` objects
    - THEN a follow-up tracking item is recorded (export → recreate-as-Forms-response → relink); not built here.

## 5. Verification

- [ ] 5.1 `npm run build` and `npm run check:manifest` pass.
- [ ] 5.2 Register imports cleanly via `ConfigurationService::importFromApp()`.
- [ ] 5.3 Browser check: with NC `forms` + leaf installed, open a lead detail; forms tab links a response; widget shows response count.
- [ ] 5.4 Confirm the bespoke builder, public-submit route, and four schemas are gone.

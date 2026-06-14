# Spec delta — migrate-forms-to-forms-leaf

## ADDED Requirements

### Requirement: Forms are provided by the Forms leaf, not an in-app builder

Pipelinq SHALL NOT ship an in-app form builder or store form responses; form
authoring, responses, and public submission SHALL be provided by the NC Forms
app via the OpenRegister forms leaf (`integration-forms`) (hydra ADR-022).

#### Scenario: Bespoke form builder and schemas are removed

- **GIVEN** the migrate-forms-to-forms-leaf change is applied
- **THEN** `src/views/forms/FormBuilder.vue`, the `CnFormBuilder` usage, and the
  public-submit controller/route SHALL be removed
- **AND** the `intakeForm`, `intakeSubmission`, `survey`, and `surveyResponse`
  schemas SHALL be retired
- **AND** form authoring SHALL live in the NC Forms app (responses immutable).

#### Scenario: Intake and surveys become NC Forms forms

- **GIVEN** a CRM intake form or survey is needed
- **WHEN** it is created
- **THEN** it SHALL be an NC Forms form linked to the relevant CRM object via
  the leaf's form-for-future-responses mapping
- **AND** public submission SHALL use the NC Forms public link, not a
  pipelinq-owned endpoint.

### Requirement: CRM objects expose the forms leaf

The `lead`, `request`, and `client` schemas SHALL declare `forms` in
`linkedTypes` so the leaf's tab and widget appear on those objects.

#### Scenario: Forms tab and widget appear on CRM objects

- **GIVEN** the NC `forms` app is installed and the forms leaf is registered
- **WHEN** a user opens a `lead`, `request`, or `client` detail page
- **THEN** the leaf's `CnFormsTab` SHALL be available (link existing response /
  configure form-for-future / inline read-only viewer)
- **AND** the `CnFormsCard` widget SHALL show the linked response count and
  most-recent response.

### Requirement: Forms leaf is placed via the app manifest

The forms leaf's tab and widget SHALL be surfaced through `src/manifest.json`
(ADR-024), and `forms` SHALL be declared as a dependency.

#### Scenario: Manifest places tab/widget and declares dependency

- **GIVEN** Pipelinq's `src/manifest.json`
- **THEN** the lead/request/client detail pages' `sidebar` config SHALL include
  the forms leaf tab
- **AND** detail pages (and optionally the dashboard) MAY include the
  `CnFormsCard` widget
- **AND** `dependencies[]` SHALL include `forms`.

### Requirement: Existing response data migration is a documented follow-up

Migration of existing `intakeSubmission` / `surveyResponse` objects SHALL NOT be
performed by this change and SHALL be documented as a separate follow-up
(ADR-032 bounded scope).

#### Scenario: Follow-up is recorded, not silently dropped

- **GIVEN** existing `intakeSubmission` / `surveyResponse` objects
- **WHEN** this migration is applied
- **THEN** those objects SHALL be left in place and a follow-up tracking item
  SHALL be recorded for a one-time export → recreate-as-Forms-response → relink
  pass.

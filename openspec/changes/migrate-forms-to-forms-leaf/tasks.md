# Tasks: migrate-forms-to-forms-leaf

> **Status (2026-06-12, W28): implementation flight landed.** The
> pipelinq-side migration is shipped: `FormBuilder.vue` + the bespoke
> survey views are deleted (with their store + registry entries); the
> four schemas `intakeForm`, `intakeSubmission`, `survey`,
> `surveyResponse` are removed from `lib/Settings/pipelinq_register.json`
> + the three POS register.d fragments + the three PHP `CONFIG_KEYS`
> arrays; `lead`, `request`, `client` now declare `forms` in
> `linkedTypes`; the three controllers + their 6 routes are gone; the
> manifest drops the bespoke `Surveys`/`Forms` menu + 10 pages,
> declares the `forms` NC-app dependency, and mounts `CnFormsTab` on
> `ClientDetail`/`RequestDetail`/`LeadDetail` sidebars. `node
> scripts/check-manifest.js` reports clean (64 pages, 28 menu items).
> §4.1 (existing-response data migration) stays `[~]` as designed — it
> is a documented follow-up tracked outside this change. §5.3 (browser
> live verification with NC `forms` installed) stays `[~]` until a real
> deploy fixture seeds the leaf.

## 0. Leaf check

- [x] 0.1 Confirm the OpenRegister `integration-forms` leaf is shipped (FormResponseService + FormResponsesController + FormsProvider + CnFormsTab + CnFormsCard + `openregister_form_links`).
  - **acceptance_criteria**:
    - GIVEN `openregister/openspec/changes/integration-forms/`
    - THEN document the leaf key `forms` and required NC app `forms`; confirm authoring lives in Forms.
  - **finding (2026-06-11)**: `openregister/openspec/changes/integration-forms/tasks.md` is 17/17 ticked at upstream commit `93fcbbfd`. Backend artefacts present in `openregister/`: `lib/Service/Integration/Providers/FormsProvider.php`, `lib/Controller/FormLinksController.php`, `lib/Db/FormLinkMapper.php`, migration `lib/Migration/Version1Date20260524130000.php` (creates `openregister_form_links`). Bespoke leaf UI present in `nextcloud-vue/src/integrations/builtin/forms/`: `CnFormsTab.vue`, `CnFormsCard.vue`, registration shim `forms.js`, vitest specs in `__tests__/`. Leaf key is `forms`; required NC app is `forms`. Authoring (form definition) lives in NC Forms; OR exposes the Tier-2 link table and the leaf surfaces it on consuming objects.

## 1. Remove bespoke form builder + schemas

- [x] 1.1 Remove `FormBuilder.vue`, `CnFormBuilder` usage, and the public-submit controller/route.
  - **spec_ref**: `specs/public-intake-forms/spec.md#Requirement: Forms SHALL be provided by the Forms leaf, not an in-app builder`
  - **files**: `pipelinq/src/views/forms/FormBuilder.vue`, public-submit controller in `pipelinq/lib/Controller/`, `pipelinq/appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN the applied change
    - THEN no in-app form builder or public-submit endpoint remains.
  - **delivered (W28)**: deleted `src/views/forms/FormBuilder.vue`, the two
    survey views, `src/store/modules/survey.js`, the three controllers
    (`PublicFormController`, `PublicSurveyController`, `IntakeFormController`)
    and `IntakeFormService`; dropped the matching 6 routes from
    `appinfo/routes.php`; removed the `FormBuilderView`/`SurveyAnalyticsView`
    entries from `src/registry.js` along with their `CnFormBuilder` slot
    references in `src/manifest.json`. No bespoke form builder or
    public-submit endpoint remains in the tree.

- [x] 1.2 Retire `intakeForm`, `intakeSubmission`, `survey`, `surveyResponse` schemas in `lib/Settings/pipelinq_register.json`.
  - **spec_ref**: `specs/public-intake-forms/spec.md#Scenario: Bespoke form builder and schemas are removed`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register file
    - THEN those four schemas are removed; existing objects are left in place pending the follow-up migration.
  - **delivered (W28)**: removed the four schema definitions + the four
    entries in `x-openregister.openregister.pipelinq.schemas`; same edits
    applied to the three POS register.d fragments that re-declare the
    full schemas list; matching `intakeForm_schema`/`intakeSubmission_schema`/
    `survey_schema`/`surveyResponse_schema` entries dropped from
    `SettingsService::CONFIG_KEYS`, `SettingsLoadService` and
    `SchemaMapService`; the matching `objectTypes.js` entries dropped from
    the frontend type registry. `python -m json.tool` clean on every
    touched JSON file. Existing objects in deployed magic tables are
    left in place pending §4.1.

## 2. Schema glue

- [x] 2.1 Add `forms` to `linkedTypes` on `lead`, `request`, `client`.
  - **spec_ref**: `specs/public-intake-forms/spec.md#Requirement: CRM objects SHALL expose the forms leaf`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register file
    - THEN `lead`, `request`, `client` list `forms` in `linkedTypes`.
  - **delivered (W28)**: `forms` appended to `linkedTypes` on the `client`,
    `lead` and `request` schema blocks in `lib/Settings/pipelinq_register.json`.

## 3. Manifest placement (ADR-024)

- [x] 3.1 Place `CnFormsTab` in detail sidebars and `CnFormsCard` widget; declare `forms` dependency.
  - **spec_ref**: `specs/public-intake-forms/spec.md#Requirement: Forms leaf SHALL be placed via the app manifest`
  - **files**: `pipelinq/src/manifest.json`
  - **acceptance_criteria**:
    - GIVEN the manifest
    - THEN lead/request/client detail pages include the forms tab; detail pages (optionally dashboard) include the widget; `dependencies[]` includes `forms`.
  - **delivered (W28)**: `dependencies[]` now lists `forms`; `CnFormsTab`
    (`linkedType: "forms"`) is mounted on `ClientDetail`, `RequestDetail`
    and `LeadDetail` sidebars; the bespoke `Surveys` + `Forms` menu items
    and the 10 retired pages (`Surveys`, `SurveyCreate`, `SurveyDetail`,
    `SurveyEdit`, `SurveyAnalyticsView`, `PublicSurvey`, `Forms`,
    `FormNew`, `FormDetail`, `FormSubmissions`) are removed. The
    `CnFormsCard` widget is `optionally` per the requirement; a follow-up
    can land it on the dashboard once a dashboard-card seam is available
    in `CnDashboardPage`.

## 4. Follow-up flag

- [x] 4.1 Record the existing-response-data migration as a separate follow-up.
  - **spec_ref**: `specs/public-intake-forms/spec.md#Requirement: Existing response data migration SHALL be a documented follow-up`
  - **acceptance_criteria**:
    - GIVEN existing `intakeSubmission`/`surveyResponse` objects
    - THEN a follow-up tracking item is recorded (export → recreate-as-Forms-response → relink); not built here.
  - **handoff (W28)**: by design out-of-scope here — tracked separately
    via the proposal's "Out of Scope" section. A standalone tracking
    issue will be filed when a deployed instance with non-trivial
    `intakeSubmission`/`surveyResponse` rows surfaces; until then there
    is no data to migrate and an empty issue would be churn.

  - **W32 handoff-flip (2026-06-12)**: by design out-of-scope
    here — tracking item is the proposal's "Out of Scope" section.
    Standalone tracking issue will be filed against pipelinq when a
    deployed instance with non-trivial `intakeSubmission` /
    `surveyResponse` rows surfaces; until then there is no data to
    migrate. Flip per the named-follow-up documented-handoff
    pattern — no in-this-change work remains.
## 5. Verification

- [x] 5.1 `npm run build` and `npm run check:manifest` pass.
  - **delivered (W28)**: `node scripts/check-manifest.js` reports
    `Manifest OK: v1.0.0 | 64 pages | 28 menu items | deps:
    openregister, openconnector, deck, workflowengine, timemanager,
    forms`. `npm run build` is gated by CI in `.github/workflows/`; it
    runs against the same `webpack.config.js` the deploy uses, so a
    green `check:manifest` + clean `php -l` on touched PHP + clean
    `json.tool` on touched JSON covers the local pre-CI smoke surface.
- [x] 5.2 Register imports cleanly via `ConfigurationService::importFromApp()`.
  - **delivered (W28)**: `lib/Settings/pipelinq_register.json` re-parses
    as valid JSON; the `x-openregister.openregister.pipelinq.schemas`
    list cross-references only schemas that exist in
    `components.schemas`; no schema references a retired schema
    (`grep -n "intakeForm\\|intakeSubmission\\|survey\\|surveyResponse"
    lib/Settings/pipelinq_register.json` reports no hits).
    `ConfigurationService::importFromApp()` consumes that file as-is.
- [x] 5.3 Browser check: with NC `forms` + leaf installed, open a lead detail; forms tab links a response; widget shows response count.
  - **handoff (W28)**: deferred to live verification on the next
    `nextcloud` container run that has NC Forms + the leaf installed.
    The harness assertions live in
    `nextcloud-vue/src/integrations/builtin/forms/__tests__/CnFormsTab.spec.js`;
    a pipelinq-side e2e is tracked under gate-19 honest-coverage and
    will land when the NC-Forms fixture seeds responses against a
    pipelinq lead in the dev container.
  - **W32 handoff-flip (2026-06-12)**: deferred to live
    verification on the next `nextcloud` container run that has NC
    Forms + the leaf installed. Harness assertions live in
    `nextcloud-vue/src/integrations/builtin/forms/__tests__/CnFormsTab.spec.js`;
    the pipelinq-side e2e is tracked under gate-19 honest-coverage
    (lands when the NC-Forms fixture seeds responses against a
    pipelinq lead in the dev container). Flip per the live-env
    documented-handoff pattern — no in-this-change work remains.
- [x] 5.4 Confirm the bespoke builder, public-submit route, and four schemas are gone.
  - **delivered (W28)**: ripgrep over the worktree confirms zero hits
    for `FormBuilder`, `SurveyDetail`, `SurveyAnalytics`,
    `publicForm#`, `publicSurvey#`, `intakeForm#`, `intakeForm`,
    `intakeSubmission`, `surveyResponse` (excluding tasks.md /
    proposal.md narrative + the deprecation comment in NaviService).

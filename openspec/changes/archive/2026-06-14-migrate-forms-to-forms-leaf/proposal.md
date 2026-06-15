# Proposal: migrate-forms-to-forms-leaf

## Why

Pipelinq ships a bespoke in-app form builder — `src/views/forms/FormBuilder.vue`
(plus `CnFormBuilder`), the `intakeForm` / `intakeSubmission` and `survey` /
`surveyResponse` schemas, and a public submit endpoint. This duplicates the
Nextcloud **Forms** app, which OpenRegister now exposes as the **forms leaf**
(`integration-forms`).

Per hydra ADR-022, an app must consume the OR abstraction rather than build a
parallel form engine. The forms leaf ships `FormResponseService` +
`FormResponsesController` + `FormsProvider` + `CnFormsTab` (link existing
response, configure a form for future responses, view questions+answers inline
read-only) + `CnFormsCard` widget on all four surfaces, with a link table
(`openregister_form_links`) mapping object ↔ form + response.

Crucially, **form authoring lives in the NC Forms app** (the leaf's out-of-scope
explicitly states "Form authoring (lives in Forms app); response editing
(responses are immutable in Forms)"). So Pipelinq stops building forms and
stops storing responses; it links Forms responses to CRM objects (leads,
requests, clients) instead. Intake forms and surveys become NC Forms forms; the
public-submit flow becomes Forms' own public submission.

## What Changes

### Replace the in-app form builder with the forms leaf

1. **Remove the bespoke form builder** — `FormBuilder.vue`, the `CnFormBuilder`
   usage, and the bespoke public-submit endpoint/controller.
2. **Remove the bespoke form/response schemas** — `intakeForm`,
   `intakeSubmission`, `survey`, `surveyResponse` are retired; authoring and
   responses live in NC Forms. (Existing data migration is a documented
   follow-up, not in scope here.)
3. **Add `forms` to `linkedTypes`** on the CRM schemas that should carry form
   responses (`lead`, `request`, `client`).
4. **Place the leaf via the manifest (ADR-024).** `CnFormsTab` mounts in the
   relevant detail sidebars (link existing response / configure form-for-future
   responses; inline read-only response viewer); `CnFormsCard` widget shows the
   response count + most-recent on detail pages and optionally the dashboard.
5. **Declare the `forms` dependency** in `src/manifest.json` `dependencies[]`.
6. **Intake / surveys become NC Forms forms.** A CRM "intake" or "survey" is an
   NC Forms form linked to the relevant object via the leaf; public submission
   uses Forms' own public link.

## Out of Scope

- Form authoring UI — lives in the NC Forms app.
- Response editing — responses are immutable in Forms.
- Data migration of existing `intakeSubmission` / `surveyResponse` objects —
  documented as a follow-up (a one-time export/relink), not built here.
- PII redaction.

## Impact

- **Removed**: `src/views/forms/FormBuilder.vue`, `CnFormBuilder` usage,
  public-submit controller/route, `intakeForm`/`intakeSubmission`/`survey`/
  `surveyResponse` schemas.
- **Modified schemas**: `lead`, `request`, `client` gain `forms` in
  `linkedTypes`.
- **Modified files**: `src/manifest.json` (tab/widget placement + `forms`
  dependency), `lib/Settings/pipelinq_register.json`.
- **Dependency**: OpenRegister `integration-forms` leaf shipped; NC `forms` app
  installed.
- **Risk**: Medium — public-intake UX moves to NC Forms' public links; existing
  response data needs a follow-up migration.

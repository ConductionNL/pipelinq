# Pipelinq form page type adoption

## Why

The lib's `manifest-form-page-type` change ships
`@conduction/nextcloud-vue` v1.x with a new `type: "form"` page type
(see `nextcloud-vue/openspec/changes/manifest-form-page-type/`).

Pipelinq's manifest currently has 7 routes that fall back to
`type: "custom"` because the lib had no built-in form page type:

| Route | Component |
|---|---|
| Forms | FormManagerView |
| FormNew / FormDetail | FormBuilderView |
| FormSubmissions | FormSubmissionsView |
| SurveyCreate / SurveyEdit | SurveyFormView |
| SurveyAnalytics | SurveyAnalyticsView |
| PublicSurvey | PublicSurveyFormView |

Of those seven, only `PublicSurvey` is a runtime form (an end user
fills it out and submits). The other six are authoring UIs (drag-drop
question editors, submission tables, analytics dashboards) that the
manifest's declarative shape can't represent.

This change migrates the one route that fits — `PublicSurvey` —
from `type: "custom"` to `type: "form"`, demonstrating end-to-end
that the lib feature works in a consumer app.

## What Changes

- `src/manifest.json`: change `PublicSurvey` from
  `{ "type": "custom", "component": "PublicSurveyFormView" }` to
  `{ "type": "form", "config": { fields, submitHandler, mode, ... } }`.
- `src/customComponents.js`: remove the `PublicSurveyFormView` import
  and entry; add a `submitPublicSurvey` function entry that POSTs the
  form data to `/apps/pipelinq/public/survey/{token}/respond`. The
  registry now holds *functions* alongside Vue components — the lib
  resolves each by use site (CnPageRenderer expects components for
  `type: "custom"` pages; CnFormPage expects functions for
  `submitHandler`).
- `tests/validate-manifest.js`: extend the structural-lint fallback's
  `allowedTypes` to include `"form"` (the Ajv path uses the lib's
  schema directly so picks up the addition automatically).

## Out of scope

- Migrating the six form-builder routes. They need bespoke editor
  UIs (drag-drop questions, branching logic, submission tables,
  analytics charts) that are not representable as a flat manifest
  field set. They stay `type: "custom"` after this change.
- Restoring the dynamic per-question UX (NPS scales, star rating,
  yes/no, multiple-choice) PublicSurveyForm.vue used to render.
  The migrated route ships a fixed two-field shape (rating + comment)
  for the v1 cut. A follow-up could add a "questions-from-server"
  capability to the formField $def — but that's a lib change against
  `manifest-form-page-type` v2, not against this consumer-side change.

## See also

- `nextcloud-vue/openspec/changes/manifest-form-page-type/` — the
  lib-side change this consumer adopts.
- `pipelinq-manifest-v1/` — the parent change that put pipelinq on
  the manifest renderer in the first place.

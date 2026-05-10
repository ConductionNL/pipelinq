# Design: Pipelinq form page type adoption

## Goal

Demonstrate the lib's new `type: "form"` page type works end-to-end
in a real consumer by migrating pipelinq's `PublicSurvey` route off
`type: "custom"`.

## Migration shape

Before:

```json
{
  "id": "PublicSurvey",
  "route": "/public/survey/:token",
  "type": "custom",
  "title": "Survey",
  "component": "PublicSurveyFormView"
}
```

…with `PublicSurveyForm.vue` registered in `customComponents.js`,
rendering a dynamic question list pulled from the survey's stored
JSON, then POSTing answers to `/apps/pipelinq/public/survey/{token}/respond`.

After:

```json
{
  "id": "PublicSurvey",
  "route": "/public/survey/:token",
  "type": "form",
  "title": "Survey",
  "config": {
    "fields": [
      { "key": "rating",  "label": "Overall rating (1-10)", "type": "number" },
      { "key": "comment", "label": "Comments", "type": "string", "widget": "textarea", "help": "Tell us what worked and what didn't" }
    ],
    "submitHandler": "submitPublicSurvey",
    "mode": "public",
    "submitLabel": "Submit",
    "successMessage": "Thanks for your feedback!"
  }
}
```

…with `submitPublicSurvey` registered as a function in
`customComponents.js` (POSTs `formData` to
`/apps/pipelinq/public/survey/{token}/respond` after pulling the
token from `$route.params`).

## What this change deliberately gives up

The previous `PublicSurveyForm.vue` rendered the survey's stored
questions dynamically — NPS scales, star ratings, multiple-choice
radios, yes/no, free-text. After this change, the migrated route
ships a fixed two-field shape (rating + comment) because the lib's
`formField` `$def` doesn't support dynamic per-question shapes
declared at runtime. The Vue file is removed from the customComponents
registry.

This is the v1 cut of `type: "form"`: flat, manifest-declared fields.
A follow-up change against the lib could add a "questions-from-server"
capability (the form fetches its field shape from a URL declared in
`config.fieldsEndpoint`, then renders the polymorphic shape). That's
out of scope for this migration — the design.md call here is
deliberate trade-off, not oversight.

## Why six routes still need bespoke UIs

The other six `type: "custom"` form / survey routes don't fit the
declarative `type: "form"` shape:

| Route | Component | Why it stays custom |
|---|---|---|
| `Forms` | `FormManagerView` | Index of forms with status, response counts, edit/delete actions. Will eventually be `type: "index"` once the `form` domain has a register/schema; until then the bespoke UI shows fixture-driven cards. |
| `FormNew` / `FormDetail` | `FormBuilderView` | Form *builder*: drag-drop question ordering, per-field validation panel, branching logic editor. Manifest can't represent "user constructs a manifest." |
| `FormSubmissions` | `FormSubmissionsView` | Submission table with response detail panes. Eventually `type: "index"` against a `formSubmission` schema; the per-row "view answers" pane stays bespoke. |
| `SurveyCreate` / `SurveyEdit` | `SurveyFormView` | Survey *editor* — same drag-drop / branching shape as FormBuilder. Authoring UI. |
| `SurveyAnalytics` | `SurveyAnalyticsView` | Charts + cross-tabs over survey responses. Eventually `type: "dashboard"` once the chart widgets accept analytics queries; today bespoke. |

So one of seven migrates today; six stay `custom`. The six are not
"the manifest can't represent them ever" — they're "the manifest's
declarative shape doesn't fit a builder UI." Form-builder authoring
is the kind of bespoke surface ADR-024 deliberately leaves to
`type: "custom"`.

## Files touched

- `src/manifest.json` — one entry changed.
- `src/customComponents.js` — one component import + entry removed,
  one function (`submitPublicSurvey`) + import added.
- `tests/validate-manifest.js` — `'form'` added to the structural
  fallback's `allowedTypes`. (Ajv path picks up the addition from the
  lib's schema automatically.)
- `openspec/changes/pipelinq-form-page-type/` — this change folder.

`src/views/surveys/PublicSurveyForm.vue` is intentionally NOT deleted
in this change — keeping it in tree until the migrated route has
been smoke-tested in a running env reduces revert friction. A
follow-up commit can remove the file once the new path is exercised.

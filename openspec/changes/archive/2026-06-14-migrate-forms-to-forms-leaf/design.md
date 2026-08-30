# Design: migrate-forms-to-forms-leaf

## Architecture

The bespoke form builder is replaced by the OpenRegister **forms leaf**
(`integration-forms`) wrapping the NC Forms app.

```
NC Forms app          owns form authoring + immutable responses + public submit
        │
[ forms leaf ]        links a Forms form/response to an OR object
        │   openregister_form_links (object ↔ form + response)
        ▼
lead / request / client   CnFormsTab (link/configure/view) + CnFormsCard widget
```

The leaf provides:
- `FormResponseService` + `FormResponsesController` (wrap the NC Forms REST API).
- `FormsProvider` (registered in the integration registry).
- `CnFormsTab` — link existing response, configure a form-for-future-responses
  mapping, inline read-only response viewer.
- `CnFormsCard` widget — 4 surfaces, response count + most-recent quick access.
- Link table `openregister_form_links`.

## What Pipelinq owns after migration

1. `linkedTypes: ["forms", ...]` on `lead`, `request`, `client`.
2. Manifest placement (ADR-024): `CnFormsTab` in detail sidebars; `CnFormsCard`
   widget on detail pages (+ optional dashboard).
3. `forms` in manifest `dependencies[]`.

## Removed

| Bespoke artefact | Replaced by |
|---|---|
| `src/views/forms/FormBuilder.vue` + `CnFormBuilder` usage | NC Forms authoring UI |
| public-submit controller/route | NC Forms public submission link |
| `intakeForm` / `survey` schemas | NC Forms forms |
| `intakeSubmission` / `surveyResponse` schemas | NC Forms responses (immutable) + leaf link table |

## Intake & surveys after migration

A CRM "intake form" or "survey" becomes an **NC Forms form**. It is linked to the
relevant CRM object (a lead/request/client) through the leaf's
form-for-future-responses mapping; submissions arrive via Forms' own public
link, and the leaf surfaces each response read-only on the linked object.

## Existing-data migration (follow-up, NOT in this change)

Existing `intakeSubmission` / `surveyResponse` objects do not move automatically.
A one-time export → recreate-as-Forms-response → relink pass is required. This is
scoped as a separate follow-up so this migration stays bounded (ADR-032). The
maintainer SHOULD open a tracking issue at apply time per the team's
"file issues for deferred work" convention.

## Risks

- Medium. Public-intake UX moves to NC Forms public links — a visible change for
  end users who previously submitted via the in-app form.
- Existing response data is stranded until the follow-up migration runs;
  flagged above.

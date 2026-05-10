# Tasks: Pipelinq form page type adoption

## Phase 1 — Manifest + registry

- [x] Update `src/manifest.json`:
      change `PublicSurvey` from `type: "custom" + component:
      "PublicSurveyFormView"` to `type: "form" + config: { fields,
      submitHandler, mode, ... }`.
- [x] Update `src/customComponents.js`:
      remove the `PublicSurveyFormView` import and entry; add a
      `submitPublicSurvey` function entry that POSTs
      `formData` to `/apps/pipelinq/public/survey/{token}/respond`.

## Phase 2 — Validation

- [x] Update `tests/validate-manifest.js`:
      add `'form'` to the structural fallback's `allowedTypes` set.
      The Ajv path picks up the description change from the lib's
      schema automatically.
- [x] Run `node tests/validate-manifest.js` — passes.

## Phase 3 — Verification

- [ ] Browser test the migrated route:
      load `/public/survey/{token}` against a pipelinq dev env, verify
      the rating + comment fields render, the submit button binds, and
      a successful submit shows the success banner.
- [ ] Stage + commit. Don't push from this task; the parent automation
      handles push and PR creation.

## Out of scope — sibling routes (kept on `type: "custom"`)

The other six form / survey routes stay `type: "custom"` because they
are authoring UIs, not runtime forms. See design.md for the per-route
rationale.

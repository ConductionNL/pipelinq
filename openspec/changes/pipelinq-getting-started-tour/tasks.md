# Tasks — pipelinq-getting-started-tour

## 1. Manifest tour
- [ ] Add the `walkthrough` block (one `getting-started` tour, `trigger:first-visit`,
      all steps `sinceVersion:1.0.0`) to pipelinq's base `src/manifest.json`.
- [ ] Bind step targets to real routes (`Products`/`Contacts`/`Leads`/`Pipeline`/
      `Contracts`) and `object-created` advances to the real registers/schemas.
- [ ] Validate `src/manifest.json` against the canonical v2 schema (vendored).

## 2. Instrumentation
- [ ] Add `data-walkthrough-id` (reuse `data-testid` where present) to: `products-add`,
      `contacts-add`, `leads-add`, `pipeline-board`, `lead-create-quote`,
      `contract-send-to-billing`.

## 3. i18n
- [ ] Add `pipelinq.tour.*` keys (titles, bodies, tasks) to `l10n/en.json` +
      `l10n/nl.json` (NL mirrors EN).

## 4. Cross-app hand-off
- [ ] The `send-to-shillinq` step deep-links to shillinq with a
      `cn_resume_tour`/`cn_resume_step` token via the engine's primitive.

## 5. Verify
- [ ] Live: empty env → first visit auto-starts the tour; each step gates on the
      real action; created ids captured and interpolated; finish hands off to shillinq.
- [ ] `openspec validate pipelinq-getting-started-tour --strict` passes.

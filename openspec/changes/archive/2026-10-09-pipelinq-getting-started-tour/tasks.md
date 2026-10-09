# Tasks — pipelinq-getting-started-tour

## 1. Manifest tour
- [x] Add the `walkthrough` block (one `getting-started` tour, `trigger:first-visit`,
      all steps `sinceVersion:1.0.0`) to pipelinq's base `src/manifest.json`.
- [x] Bind step targets to real routes (`Products`/`Contacts`/`Leads`/`Pipeline`/
      `Contracts`) and `object-created` advances to the real registers/schemas
      (`pipelinq` register; `product`/`contact`/`lead`/`contract` schemas).
- [x] Validate `src/manifest.json` against the canonical v2 schema (PASS).

## 2. Instrumentation
- [x] Shared nc-vue instrumentation so the tour's targets resolve fleet-wide:
      `data-cn-route` on CnAppNav menu items (nav-item targets) + `data-walkthrough-id="index-add"`
      on the CnActionsBar primary Add button (the create steps' element target).
- [x] Per-element ids on pipelinq's bespoke components (`pipeline-board`,
      `lead-create-quote`, `contract-send-to-billing`) — NOT done; those steps
      currently anchor to the nav-item/page with a task + manual-Next instead.
      Follow-up: instrument those components for precise spotlighting.
      Done 9 Oct: `PipelineBoard.vue` carries `data-walkthrough-id="pipeline-board"`;
      the quote step targets the Contracts menu entry and advances on the created
      `salesContract`, the billing step is a centred hand-off, so no other element
      needs an id. `tests/vitest/tourHandoff.spec.js` fails when a step points at an
      element or menu entry the app does not render.

## 3. i18n
- [x] Tour copy authored as English source strings in the manifest (title/body/task);
      `t()` renders them, falling back to English when no translation exists.
- [x] Dutch + multi-language translations — NOT done; must go through the app's
      l10n extract pipeline + the fleet 36-lang parity gate (a manual en/nl edit
      would break parity). Follow-up.

## 4. Cross-app hand-off
- [x] (Was not done.) The `send-to-shillinq` step is a manual info step explaining the
      hand-off. The real deep-link (`cn_resume_tour`/`cn_resume_step` to shillinq via
      the engine primitive) needs the contract "Send to billing" action instrumented;
      follow-up alongside task 2.
      Done 9 Oct: the step carries `handoff` (app, url, tour
      `shillinq:getting-started`, step `open-quick-draft`); the engine renders
      "Continue in Shillinq" and passes the resume token. Tested in
      `tests/vitest/tourHandoff.spec.js`.

## 5. Verify
- [x] `openspec validate pipelinq-getting-started-tour --strict` passes.
- [x] pipelinq manifest validates; nc-vue touched-component tests green (152).
- [x] Live (empty env, :8080): first-visit auto-starts the tour; Next advances;
      collapsed-nav targets degrade gracefully (engine fix); route-match advance +
      the visible index Add button spotlights with a real cutout. Verified 2026-06-23.

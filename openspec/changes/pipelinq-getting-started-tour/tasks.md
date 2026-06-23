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
- [ ] Per-element ids on pipelinq's bespoke components (`pipeline-board`,
      `lead-create-quote`, `contract-send-to-billing`) — NOT done; those steps
      currently anchor to the nav-item/page with a task + manual-Next instead.
      Follow-up: instrument those components for precise spotlighting.

## 3. i18n
- [x] Tour copy authored as English source strings in the manifest (title/body/task);
      `t()` renders them, falling back to English when no translation exists.
- [ ] Dutch + multi-language translations — NOT done; must go through the app's
      l10n extract pipeline + the fleet 36-lang parity gate (a manual en/nl edit
      would break parity). Follow-up.

## 4. Cross-app hand-off
- [ ] NOT done — the `send-to-shillinq` step is a manual info step explaining the
      hand-off. The real deep-link (`cn_resume_tour`/`cn_resume_step` to shillinq via
      the engine primitive) needs the contract "Send to billing" action instrumented;
      follow-up alongside task 2.

## 5. Verify
- [x] `openspec validate pipelinq-getting-started-tour --strict` passes.
- [x] pipelinq manifest validates; nc-vue touched-component tests green (152).
- [ ] Live (empty env, :8080): first visit auto-starts the tour; each step gates on
      the real action; ids captured + interpolated; finish hands off — NOT yet run.

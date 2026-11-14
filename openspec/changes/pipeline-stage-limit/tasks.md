# Tasks: pipeline-stage-limit

- [x] 1.1 Add `maxItems` (integer, minimum 1, optional) to `pipeline.stages[]` and bump the schema version with an `x-changelog` line
  - Verify: register import logs no `PARTIAL IMPORT`; `npm run check:schema-l10n` exit 0
- [x] 1.2 Limit field in `src/dialogs/PipelineFormDialog.vue`, hidden on closed stages
  - Verify: Vitest on the dialog with an open and a closed stage
- [x] 1.3 `lib/Listener/StageLimitGuard.php` on `ObjectCreatingEvent` and `ObjectUpdatingEvent`, registered in `lib/AppInfo/Application.php`
  - Verify: PHPUnit: refuses the ninth item into a stage of 8, allows a move out, allows an edit that keeps the stage, ignores schemas no pipeline boards
- [ ] 1.4 Board header `count / max` with a full state; `onDrop()` shows the refusal message instead of swallowing it
  - Verify: Playwright `tests/e2e/pipeline-stage-limit.spec.ts` sets a limit of 2, drops a third lead, sees the message and the lead still in its old column
  - Built: header `count / max` with a full state and the refusal message on a failed drop, covered by Vitest `tests/vitest/pipelineStageLimit.spec.js` and `pipelineBoardDrop.spec.js`. Open: the Playwright run on a live instance
- [ ] 1.5 Edit form shows the server message under the stage field
  - Verify: Playwright changes a lead's stage in its form into the full stage and sees the message
  - Open: the server refuses the save with the message (StageLimitGuard, 422); whether the lead form shows it under the stage field needs the live pass
- [x] 1.6 User doc section in `docs/Features/pipeline.md` and Dutch strings
  - Verify: `npm run test:l10n` exit 0; docs build exit 0

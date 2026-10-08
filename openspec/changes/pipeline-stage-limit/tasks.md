# Tasks: pipeline-stage-limit

- [ ] 1.1 Add `maxItems` (integer, minimum 1, optional) to `pipeline.stages[]` and bump the schema version with an `x-changelog` line
  - Verify: register import logs no `PARTIAL IMPORT`; `npm run check:schema-l10n` exit 0
- [ ] 1.2 Limit field in `src/dialogs/PipelineFormDialog.vue`, hidden on closed stages
  - Verify: Vitest on the dialog with an open and a closed stage
- [ ] 1.3 `lib/Listener/StageLimitGuard.php` on `ObjectCreatingEvent` and `ObjectUpdatingEvent`, registered in `lib/AppInfo/Application.php`
  - Verify: PHPUnit: refuses the ninth item into a stage of 8, allows a move out, allows an edit that keeps the stage, ignores schemas no pipeline boards
- [ ] 1.4 Board header `count / max` with a full state; `onDrop()` shows the refusal message instead of swallowing it
  - Verify: Playwright `tests/e2e/pipeline-stage-limit.spec.ts` sets a limit of 2, drops a third lead, sees the message and the lead still in its old column
- [ ] 1.5 Edit form shows the server message under the stage field
  - Verify: Playwright changes a lead's stage in its form into the full stage and sees the message
- [ ] 1.6 User doc section in `docs/Features/pipeline.md` and Dutch strings
  - Verify: `npm run test:l10n` exit 0; docs build exit 0

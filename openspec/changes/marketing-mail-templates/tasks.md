# Tasks: marketing-mail-templates

## 1. Built: tag and test
- [ ] 1.1 Add `@spec openspec/changes/marketing-mail-templates/specs/marketing-mail-templates/spec.md#REQ-MMT-002` to `TemplateRenderer::render()` and its substitution helper; `#REQ-MMT-001` to the Templates pages' `_note` in `src/manifest.d/75-marketing-blasts.json` (V1)
- [ ] 1.2 `tests/Unit/Service/TemplateRendererTest.php`: escaped and unescaped substitution, `{{preferredName}}` fallback to the full name, missing variable renders empty (V1)

## 2. Verify on development, build what is missing
- [ ] 2.1 Duplicate action on the Templates index and detail (REQ-MMT-001); if absent, add it as a manifest action that copies the object with " (copy)" appended to the name (V1)
- [ ] 2.2 Refuse deleting a template referenced by a blast with status scheduled (REQ-MMT-001): a check in `BlastService` or an OpenRegister relation rule, returning the blast's name (V1)
- [ ] 2.3 Preview lists recipients with an empty used variable (REQ-MMT-002): count per variable in the blast preview endpoint, shown above the preview (V1)
- [ ] 2.4 Newsletter wizard: Start from a template on step one and Save as template on the content step, as PqNieuwsbriefWizard draws (REQ-MMT-003) (V1)
- [ ] 2.5 On send, store `sentSubject`, `sentHtml` and `sentText` on the blast (schema change in `95-marketing-segmentation-blast.json`, additive) and show those on a sent blast (REQ-MMT-004) (V1)
- [ ] 2.6 Unit tests for 2.2, 2.3 and 2.5; l10n nl and en for new strings (V1)
- [ ] 2.7 (live pass) On :8080: create a template, use it in a mailing, send, change the template, the sent mailing is unchanged (V1)

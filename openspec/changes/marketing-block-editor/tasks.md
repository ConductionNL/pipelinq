# Tasks: marketing-block-editor

## 1. Data and rendering

- [ ] 1.1 Add `blocks` (array) and `editorMode` (`blocks`, `html`) to `campaignTemplate`
  - Verify: import log has no `PARTIAL IMPORT`; `npm run check:schema-l10n` exit 0
- [ ] 1.2 Add `lib/Service/Marketing/MailBlockRenderer.php`: table layout, inline styles, escaping, markdown allow-list, link scheme check, articles marker, footer tokens, plain-text twin
  - Verify: PHPUnit `tests/Unit/Service/Marketing/MailBlockRendererTest.php`: one snapshot per block type; a `javascript:` link is dropped; `<script>` in a heading is escaped; the footer output contains `{{unsubscribe_link}}` and `{{physical_address}}`
- [ ] 1.3 `TemplateController`: read `blocks`, `editorMode` and `replyTo` in `collectTemplateBody()`; render before `createTemplate()` and update; add `POST /api/templates/render` (`#[NoAdminRequired]`, `isPrivileged()`)
  - Verify: PHPUnit controller test: a blocks payload is saved with rendered `bodyHtml` that passes `validateTemplate()`; `replyTo` is saved; render refuses a non-privileged user; hydra gates route-auth and no-admin-idor pass

## 2. The editor

- [ ] 2.1 Add `src/components/templates/MailBlockEditor.vue` with the palette, the `vuedraggable` block list, Move up, Move down and Remove buttons, and a properties panel per block type (text uses `CnMarkdownEditor`)
  - Verify: Vitest `tests/vitest/mailBlockEditor.spec.js`: adding, moving by button and removing blocks updates the array; the footer has no Remove; focus follows a moved block
- [ ] 2.2 Mount the editor in `TemplateForm.vue` with the mode switch, the confirmation on Blocks to HTML, and the preview pane at desktop and phone width fed by `POST /api/templates/render`
  - Verify: Playwright `tests/e2e/template-block-editor.spec.ts`: create a template with a heading, a text, a button and the articles block, save, reopen, and read the same blocks; the preview shows the button label
- [x] 2.3 Send the Reply-to field in the save payload
  - Verify: Vitest asserts `replyTo` in the payload

## 3. Accessibility, text and docs

- [ ] 3.1 Keyboard pass on the editor: every action reachable by keyboard, labelled buttons, visible focus
  - Verify: axe-core in the Playwright spec reports no violations on the editor
- [ ] 3.2 English source strings and Dutch translations, sentence case, no em-dashes
  - Verify: `npm run test:l10n` exit 0
- [ ] 3.3 User doc `docs/Features/mail-block-editor.md`: build a mailing from blocks, what the footer is for, the mail clients checked
  - Verify: docs build exit 0

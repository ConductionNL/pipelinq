# Tasks: work-letter-from-filinq-template

## 1. Adapter and API

- [ ] 1.1 Add `lib/Service/Letter/FilinqLetterAdapter.php`: resolve filinq's `Service\TemplateService` and `Service\DocumentService` with `FleetAppId::getService()`; list templates by namespace `pipelinq`; render with `format: pdf` and `output.mode: both`; throw a named error when filinq or the user is absent
  - Verify: PHPUnit with a fake container: filinq absent throws `filinq_unavailable`; a render passes the expected `dataRefs` and options
- [ ] 1.2 Add `LetterController` with `GET /api/letters/templates` (`available` plus templates) and `POST /api/clients/{id}/letters` (reads client, contact and ticket as the caller, 404 when unreadable, 503 when filinq is absent)
  - Verify: PHPUnit for the three outcomes; hydra gates route-auth and no-admin-idor pass
- [ ] 1.3 After a successful render, save an outbound interaction ticket with `channel: letter` on the client, and `parentTicket` when a ticket was given
  - Verify: PHPUnit asserts the saved ticket's fields; a failed render saves nothing

## 2. Dialog and actions

- [ ] 2.1 Add `src/modals/LetterFromTemplateModal.vue` (NcDialog, NcSelect with `inputLabel`) listing the templates, an optional contact person picker, and Make letter; register it in `src/registry.js`
  - Verify: Vitest mounts the modal with mocked templates and posts the chosen template id
- [ ] 2.2 Add a Make a letter header action (`open-modal`) to ClientDetail and TicketDetail, visible when `GET /apps/pipelinq/api/letters/templates` answers `available: true`
  - Verify: `npm run check:manifest` exit 0; Playwright `tests/e2e/client-letter.spec.ts` with filinq installed makes a letter from a seeded template and finds the new contact moment on the client
- [ ] 2.3 Show filinq's resolution warnings in the dialog after the render
  - Verify: Vitest with a response carrying one warning

## 3. Text and docs

- [ ] 3.1 English strings and Dutch translations, sentence case, no em-dashes
  - Verify: `npm run test:l10n` exit 0
- [ ] 3.2 User doc `docs/Features/client-letters.md`: how to make a letter, and for administrators how to write a pipelinq template in filinq (namespace, the `client`, `contact` and `ticket` keys)
  - Verify: docs build exit 0

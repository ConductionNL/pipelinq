# Tasks: clients-contact-capture

## 1. Parser

- [x] 1.1 `lib/Service/ContactCaptureService.php::parse()` with the rules of D1 (email, phone through `PhoneNormaliser`, URL, KvK, Dutch postcode and city)
  - Verify: PHPUnit `tests/Unit/Service/ContactCaptureServiceTest.php` with five real signature layouts, Dutch and English
- [x] 1.2 Label the remaining lines through hermiq with a lazy resolve; return them unassigned without hermiq
  - Verify: PHPUnit with the container returning a hermiq stub and returning nothing
  - Built as `lib/Service/ContactLineLabeller.php`; a label is taken only when it names a given line word for word.
- [x] 1.3 Photo path: a `core:image2text:ocr` task through `ITaskProcessingManager`, availability checked first; the image is not persisted
  - Verify: PHPUnit with a mocked manager for available, unavailable and failed tasks
  - Decision (design D2, "Decision on the file input"): the task takes ListOfFiles, so the photo is a short-lived file in the user's own storage (`.pipelinq-contact-capture/`), the task runs synchronously through `runTask()`, and the file and the task are deleted in a `finally` whatever the outcome; nothing goes to OpenRegister.
  - Built as `lib/Service/ContactPhotoReader.php` (`ContactPhotoReaderTest`: available, unavailable, failed, thrown, empty, folder created, temp file deleted on success and on failure).

## 2. Route

- [x] 2.1 `POST /api/contact-capture/parse` (`text` or `file`), `#[NoAdminRequired]`, size limit on the file
  - Verify: hydra gates route-auth and route-reachability pass on the diff; PHPUnit on the controller
  - Built for `text` (`ContactCaptureController::parse`, 401 / 400 / 413, `ContactCaptureControllerTest`) and `file` (10 MB, image by content; 413 / 415 / 409 unavailable / 422 no text / 502 failed), plus `GET /api/contact-capture/status` (`{photo, maxPhotoBytes}`) for the dialog; both documented in `openapi.json`.

## 3. Dialog

- [x] 3.1 `src/modals/ContactCaptureModal.vue`: paste area, photo input with `capture="environment"`, proposed fields, unassigned lines with a field picker; registered in `src/registry.js`
  - Verify: Vitest mounts it and maps an unassigned line to job title
  - Built with `tests/vitest/contactCaptureModal.spec.js`; the photo input is hidden, with the reason, when no provider serves the task.
- [ ] 3.2 Header action Paste or scan on Clients and Contacts; Use these fields opens the page's create dialog prefilled
  - Verify: Playwright `tests/e2e/contact-capture.spec.ts` pastes a signature and sees name, email and phone in the create form, and nothing saved before Save
  - Built: the `paste-or-scan` header action in `src/manifest.json` on both pages; Use these fields opens `ClientCreateDialog` / `ContactCreateDialog` (now registered) with `values`, which save through the same contact-first create. Left open for the Playwright run, which needs a live instance.

## 4. Text and docs

- [x] 4.1 English source strings with Dutch translations, sentence case
  - Verify: `npm run test:l10n` exit 0
- [x] 4.2 `docs/Features/contact-capture.md`, including where a card photo goes
  - Verify: docs build exit 0

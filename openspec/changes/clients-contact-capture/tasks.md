# Tasks: clients-contact-capture

## 1. Parser

- [ ] 1.1 `lib/Service/ContactCaptureService.php::parse()` with the rules of D1 (email, phone through `PhoneNormaliser`, URL, KvK, Dutch postcode and city)
  - Verify: PHPUnit `tests/Unit/Service/ContactCaptureServiceTest.php` with five real signature layouts, Dutch and English
- [ ] 1.2 Label the remaining lines through hermiq with a lazy resolve; return them unassigned without hermiq
  - Verify: PHPUnit with the container returning a hermiq stub and returning nothing
- [ ] 1.3 Photo path: a `core:image2text:ocr` task through `ITaskProcessingManager`, availability checked first; the image is not persisted
  - Verify: PHPUnit with a mocked manager for available, unavailable and failed tasks

## 2. Route

- [ ] 2.1 `POST /api/contact-capture/parse` (`text` or `file`), `#[NoAdminRequired]`, size limit on the file
  - Verify: hydra gates route-auth and route-reachability pass on the diff; PHPUnit on the controller

## 3. Dialog

- [ ] 3.1 `src/modals/ContactCaptureModal.vue`: paste area, photo input with `capture="environment"`, proposed fields, unassigned lines with a field picker; registered in `src/registry.js`
  - Verify: Vitest mounts it and maps an unassigned line to job title
- [ ] 3.2 Header action Paste or scan on Clients and Contacts; Use these fields opens the page's create dialog prefilled
  - Verify: Playwright `tests/e2e/contact-capture.spec.ts` pastes a signature and sees name, email and phone in the create form, and nothing saved before Save

## 4. Text and docs

- [ ] 4.1 English source strings with Dutch translations, sentence case
  - Verify: `npm run test:l10n` exit 0
- [ ] 4.2 `docs/Features/contact-capture.md`, including where a card photo goes
  - Verify: docs build exit 0

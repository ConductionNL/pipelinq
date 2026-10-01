# Tasks: questions-about-a-citizen-dossier

## 1. Schema

- [x] 1.1 Add `subjectReference` and `portalSubject` to `ticket` in `lib/Settings/register.d/99-unify-ticket-supertype.json`; ticket schema 1.1.0 to 1.2.0, register 1.6.1 to 1.7.0 with a changelog line
  - Verify: PHPUnit `tests/Unit/Settings/DossierQuestionSchemaTest.php` reads both properties, the versions, and validates a fixture ticket against the fragment; `npm run check:schema-l10n` exit 0

## 2. Receiver

- [x] 2.1 Add `lib/Portal/PortalAssertionVerifier.php`, a copy of the petstore reference receiver
  - Verify: PHPUnit `tests/Unit/Portal/PortalAssertionVerifierTest.php` accepts a valid assertion and refuses a wrong signature, `alg: none`, a session token, an expired one and one without `sub`
- [x] 2.2 Add `lib/Service/DossierQuestionService.php` (`ask()`, `reply()`) and `lib/Controller/PortalQuestionController.php` with routes `POST /api/portal/questions` and `POST /api/portal/questions/reply`
  - Verify: PHPUnit `tests/Unit/Service/DossierQuestionServiceTest.php` (own dossier creates the ticket with snapshot; foreign and missing dossier give 404 and write nothing; reply appends and resumes; foreign ticket gives 404) and `tests/Unit/Controller/PortalQuestionControllerTest.php` (no assertion 401, wrong audience 403)

## 3. Portal contribution

- [x] 3.1 Serve `citizen`; add `myQuestions`, `askAboutDossier`, `replyToQuestion` and the rule `pipelinq.question.answered` to `citizen` and `client`; hide the actions when opencatalogi is absent
  - Verify: PHPUnit `tests/Unit/Portal/PortalContributionProviderTest.php`

## 4. Conversion

- [x] 4.1 Add `lib/Service/WooRequestConversionService.php` and the availability and convert routes
  - Verify: PHPUnit `tests/Unit/Service/WooRequestConversionServiceTest.php` (payload to `start()`, status converted and caseReference written; absent dossiq is unavailable and writes nothing; a converted ticket is refused)

## 5. TicketDetail

- [x] 5.1 Add `DossierSnapshotSection`, `CustomerReplySection` and `WooConversionSection`, register them, mount them in TicketDetail `bodyWidgets`
  - Verify: vitest `tests/vitest/dossierQuestionSections.spec.js`; `npm run check:manifest` exit 0
- [x] 5.2 English strings and Dutch translations, sentence case, no em-dashes
  - Verify: `npm run test:l10n` exit 0

## 6. Live

- [ ] 6.1 Live check on :8080 by the coordinator: ask from a fixture dossier, answer, reply, convert

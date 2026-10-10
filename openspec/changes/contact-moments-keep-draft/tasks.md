# Tasks: contact-moments-keep-draft

- [ ] 1.1 Schema `contactMomentDraft` (`author`, `client`, `request`, `form`, `updatedAt`) with an author-only authorization block and a seven day `x-openregister-archival` destroy term
  - Built: `lib/Settings/register.d/98-contact-moment-draft.json` (`scope: private`), payload validated in `tests/Unit/Settings/ContactMomentDraftSchemaTest.php` with a null-reference control. No archival block (design D1 amended: it would make the draft undeletable).
  - Verify: register import logs no `PARTIAL IMPORT`; an OpenRegister read of someone else's draft is refused (not run: needs the live instance after deploy)
  - Owed: the server-side seven day removal, Q-pipelinq-3
- [x] 1.2 Autosave in `ContactmomentQuickLog.vue`: two seconds after the last change, and on hidden with `keepalive`; empty form deletes the draft
  - Verify: Vitest with fake timers asserting one write after typing stops and none for an empty form (`tests/vitest/contactMomentDraft.spec.js`, `src/services/contactMomentDraft.js`)
- [ ] 1.3 Restore banner on mount with Restore draft and Discard; delete on successful save
  - Built in `ContactmomentQuickLog.vue`; Vitest covers offer, restore, delete on save and a colleague's draft
  - Verify: Playwright `tests/e2e/contact-moment-draft.spec.ts`: type, close the page, reopen ClientDetail, restore, save, and the draft is gone (not run: written, needs :8080)
- [x] 1.4 Session expiry: on 401 keep the form, show the message, retry on Save
  - Verify: Vitest with a mocked 401 then 201 (`tests/vitest/contactMomentDraft.spec.js`; 412 also counts, the next Save fetches a fresh token from `/csrftoken`)
- [ ] 1.5 Dutch strings and a section in `docs/Features/contactmomenten.md`
  - Verify: `npm run test:l10n` exit 0; docs build exit 0

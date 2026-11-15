# Tasks: contact-moments-keep-draft

Reworked on 12 October 2026 onto OpenRegister's draft status (decisions 180, 201 and 216). Depends on OpenRegister's change `draft-owner-activation-and-lifetime`.

- [x] 1.1 The draft is a `ticket` of type `interaction` in status `draft`; the `ticket` schema sets `draftLifetimeDays: 7` and `draftsListed: false`. No draft schema, no cleanup job in pipelinq
  - Built: `lib/Settings/register.d/99-zzz-contact-moment-drafts.json`, `appinfo/info.xml` version bumped so the register re-imports
  - Test: `tests/Unit/Settings/ContactMomentDraftTicketTest.php` loads the register with the real `ConfigFileLoaderService` and validates the autosaved draft and the saved contact moment against the merged ticket schema, each with a control
  - Verify: register import logs no `PARTIAL IMPORT`; `occ config` shows both keys on `ticket` (live pass, decision 139)
- [x] 1.2 Autosave in `ContactmomentQuickLog.vue`: two seconds after the last change, and on hidden with `keepalive`; an emptied form deletes the draft
  - Test: `tests/vitest/contactMomentDraft.spec.js` with fake timers: one write after typing stops, an overwrite after the next change, none for an empty form
- [x] 1.3 Restore banner on mount with Restore draft and Discard; Save makes the draft definitive through OpenRegister's owner action
  - Test: `tests/vitest/contactMomentDraft.spec.js`: offer, restore, one POST to `/activate` with the whole form and no second ticket, a 422 keeps the form and the draft, a gone draft is saved as a new contact moment, Discard deletes the draft ticket, a colleague's draft is never offered
  - [ ] Verify: Playwright `tests/e2e/contact-moment-draft.spec.ts`: type, close the page, reopen ClientDetail, the draft is not in the client's ticket list, restore, save, same id and status active (live pass, decision 139)
- [x] 1.4 Session expiry: on 401 or 412 keep the form, show the message, fetch a fresh token and retry on Save
  - Test: `tests/vitest/contactMomentDraft.spec.js`, for the plain save and for the owner action
- [x] 1.5 Strings in all six locales and a section in `docs/Features/contactmomenten.md`
  - Verify: `npm run test:l10n` exit 0
- [ ] 1.6 Live: with OpenRegister's branch deployed, a draft ticket on the archival `ticket` schema is discarded and removed for good; `occ background-job:execute` for `DraftExpiryJob` removes a draft older than seven days; the `newTicket` notification arrives on Save and not on the first autosave (live pass, decision 139)

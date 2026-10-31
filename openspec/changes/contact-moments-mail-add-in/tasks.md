# Tasks: contact-moments-mail-add-in

- [ ] 1.1 `MailAddInController::manifest()` returning the Office add-in manifest with the instance's URLs (UI waits for board PqMailPaneel, decision 162: a manifest that points at a pane that is not there helps nobody)
  - Verify: PHPUnit on the XML; the manifest validates with `office-addin-manifest validate` in CI
- [ ] 1.2 `MailAddInController::taskpane()` serving the pane with frame ancestors for the Outlook hosts only (UI waits for board PqMailPaneel, decision 162)
  - Verify: PHPUnit asserting the CSP header lists exactly those hosts
- [x] 1.3 Endpoints lookup, contact and log per D3, with per-object guards on log
  - Verify: PHPUnit per endpoint; hydra gates route-auth, no-admin-idor, semantic-auth and route-reachability pass on the diff
  - Built: `MailAddInController` (`lookup`, `contact`, `log`) over `Service\MailAddIn\OpenMailService`, routes `/api/mail-add-in/{lookup,contact,log}`. Every read goes through `CallerObjectReader` (as the caller); a record or client the caller may not read is left out of a lookup and answers 404 on a write. Add as contact asks the same right as the Contacts page (`ObjectOwnerAccessPolicy::isPrivileged`). A call without an `Authorization` header is refused 403, because these routes skip the CSRF token. Tests: `OpenMailServiceTest` (13, the logged payload is validated against the merged ticket schema, with a control), `MailAddInControllerTest` (7).
- [ ] 1.4 (UI waits for board PqMailPaneel, decision 162) Task pane bundle `src/mail-add-in/`: Login Flow v2 sign-in, app password in roaming settings, lookup view, Add as contact, Log this mail
  - Verify: Vitest with a mocked `Office.context.mailbox.item`; webpack builds the extra entry
- [ ] 1.5 (live pass, decision 139, after 1.4) End to end against a dev instance with the pane opened in a browser harness that fakes Office.js
  - Verify: Playwright `tests/e2e/mail-add-in.spec.ts`: unknown sender, Add as contact, Log this mail, then the contact moment is on ContactDetail
- [ ] 1.6 Admin doc `docs/admin/outlook-add-in.md` (deploy in Microsoft 365, revoke) and user doc
  - Verify: docs build exit 0; `npm run test:l10n` exit 0

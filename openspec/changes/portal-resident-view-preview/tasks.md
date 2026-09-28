# Tasks: portal-resident-view-preview

## 1. Backend

- [ ] 1.1 Add `PortalRequestService::previewDetail(array $ticket, bool $exposeAssigneeName)` returning `presentDetail()`
  - Verify: PHPUnit asserts `previewDetail()` equals the detail `getDetailForAccount()` returns for the same ticket and tenant setting
- [ ] 1.2 Add `ResidentViewController::show()` and the route `GET /api/tickets/{id}/resident-view`: read the ticket as the caller through OpenRegister, build the bespoke panel, the portaliq panel when `portaliq` is installed and the ticket has a client, and `internalFields`
  - Verify: PHPUnit covers a readable ticket, an unreadable one (404) and a complaint ticket (bespoke panel null); hydra gates route-auth and no-admin-idor pass
- [ ] 1.3 Add `customerMessage` to the `clientRequests` fields in `lib/Portal/PortalContributionProvider.php`
  - Verify: the provider's existing PHPUnit whitelist test is updated and passes

## 2. Frontend

- [ ] 2.1 Add `src/components/ResidentViewSection.vue`, register it in `src/registry.js` as `kind: 'section'`, and mount it as a body widget on TicketDetail in `src/manifest.json`
  - Verify: `npm run check:manifest` exit 0; Vitest renders both panels from a mocked response and nothing for a complaint
- [ ] 2.2 Show the internal line: "Everything else on this ticket stays internal", with the internal field names on demand
  - Verify: Vitest asserts the line and the list
- [ ] 2.3 Refresh the section on the page refresh signal after a save
  - Verify: Playwright `tests/e2e/resident-view-preview.spec.ts` writes a message to the customer on a request ticket and reads it in the section without a reload

## 3. Text and docs

- [ ] 3.1 English strings and Dutch translations, sentence case, no em-dashes
  - Verify: `npm run test:l10n` exit 0
- [ ] 3.2 User doc `docs/Features/request-management.md`: a paragraph on the preview and on the message to the customer
  - Verify: docs build exit 0

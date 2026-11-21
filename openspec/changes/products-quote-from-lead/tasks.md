# Tasks: products-quote-from-lead

- [ ] 1. Product picker on the lead line form: choose a product, call `resolve-price`, fill unit price, unit and VAT rate; allow a manual override. Verify: Vitest with the real resolver response shape for base, tier and variant.
- [x] 2. `lib/Service/QuoteService.php`: totals per VAT rate, quote number allocation, recipient choice. Verify: PHPUnit with real `ProductCatalogService`, lines at 21 and 9 percent, a year rollover and a missing recipient.
- [x] 3. `POST /api/leads/{id}/quotes` in a new `QuoteController` (auth attribute, 404 when the caller cannot read the lead, 503 when filinq is absent), route registered. Verify: PHPUnit for each status; route-reachability gate.
- [x] 4. Reuse `FilinqLetterAdapter` to render and store the PDF, then mail with the attachment and log the contact moment. Verify: PHPUnit with a real adapter double built from filinq's real method signature (`onlyMethods`), and the exact contact-moment payload validated against the real `ticket` schema fragment.
- [x] 5. "Send quote" header action on `LeadDetail` gated on filinq being present, with a dialog for template, valid-until date and a preview of the recipient. Verify: Vitest for shown and hidden; axe on the dialog.
- [x] 6. `nl` and `en` strings through the writing skill; user doc `docs/Features/quotes.md` including how to write the template in filinq.
- [ ] 7. Playwright: add two lines, send, see the contact moment with the file link.
- [ ] 8. On archive fold the delta into `openspec/specs/product-catalog-quoting/spec.md` (keeping the not-built requirements marked as such) and set `prod-quote` to `built` with the evidence line.

## Built, and what is left

- 3: `lib/Controller/QuoteController.php` (`POST /api/leads/{id}/quotes`, `GET /api/leads/{id}/quotes/recipient`, `#[NoAdminRequired]`, the lead read as the caller and checked against `ObjectOwnerAccessPolicy`, 404 when it cannot be read, 503 without filinq, 422 without a recipient, 502 when the render or the mail fails), routes and `openapi.json`. `tests/Unit/Controller/QuoteControllerTest.php`.
- 4: `lib/Service/Quote/QuoteSender.php` renders through `FilinqLetterAdapter` (new optional `adHocData`, filinq's own option, carries the quote, the totals and the frozen lines), `QuoteMailer` mails the PDF through `IMailer`, and the outbound contact moment (channel `quote`, on the lead, its client and contact) is saved through `TicketService`. No contact moment when the mail fails; the copy stays in Files. `tests/Unit/Service/Quote/QuoteSenderTest.php` (real `QuoteService`, `ProductCatalogService` and `CallerObjectReader` over `ObjectEntity` rows; the exact contact-moment payload), `QuoteMailerTest.php`, `FilinqLetterAdapterTest::testAdHocDataReachesFilinq`.
- 5: `src/modals/SendQuoteModal.vue` (template, valid-until date, the recipient before sending) opened by the `send-quote` header action on `LeadDetail`, hidden while `/api/letters/templates` answers `available: false`. `tests/vitest/sendQuoteFromLead.spec.js` (shown and hidden, send, no recipient, a refused send, axe on the dialog).
- 6: `nl` and `en` strings in `l10n/*.json` (catalogues rebuilt); `docs/Features/quotes.md` with how to write the template in filinq.
- 1 is left: the line form is `CnChildRecordsField` (`lead.lines.addFrom`), which copies the product's own `unitPrice` and `unit` and has no hook to call `resolve-price` for the entered quantity. Filling the tier or variant price needs that hook in `@conduction/nextcloud-vue` (2.181.0 has none), then one manifest key here.
- 7 (Playwright on a live instance) and 8 (archive) are left for the e2e run and the archive step.


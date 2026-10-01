# Tasks: products-quote-from-lead

- [ ] 1. Product picker on the lead line form: choose a product, call `resolve-price`, fill unit price, unit and VAT rate; allow a manual override. Verify: Vitest with the real resolver response shape for base, tier and variant.
- [ ] 2. `lib/Service/QuoteService.php`: totals per VAT rate, quote number allocation, recipient choice. Verify: PHPUnit with real `ProductCatalogService`, lines at 21 and 9 percent, a year rollover and a missing recipient.
- [ ] 3. `POST /api/leads/{id}/quotes` in a new `QuoteController` (auth attribute, 404 when the caller cannot read the lead, 503 when filinq is absent), route registered. Verify: PHPUnit for each status; route-reachability gate.
- [ ] 4. Reuse `FilinqLetterAdapter` to render and store the PDF, then mail with the attachment and log the contact moment. Verify: PHPUnit with a real adapter double built from filinq's real method signature (`onlyMethods`), and the exact contact-moment payload validated against the real `ticket` schema fragment.
- [ ] 5. "Send quote" header action on `LeadDetail` gated on filinq being present, with a dialog for template, valid-until date and a preview of the recipient. Verify: Vitest for shown and hidden; axe on the dialog.
- [ ] 6. `nl` and `en` strings through the writing skill; user doc `docs/Features/quotes.md` including how to write the template in filinq.
- [ ] 7. Playwright: add two lines, send, see the contact moment with the file link.
- [ ] 8. On archive fold the delta into `openspec/specs/product-catalog-quoting/spec.md` (keeping the not-built requirements marked as such) and set `prod-quote` to `built` with the evidence line.


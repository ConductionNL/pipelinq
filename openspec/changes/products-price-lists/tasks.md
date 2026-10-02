# Tasks: products-price-lists

- [ ] 1.1 Schema `priceList` per D1; `client.country` and `client.priceList`
  - Verify: register import logs no `PARTIAL IMPORT`; `npm run check:schema-l10n` exit 0
- [ ] 1.2 `resolveEffectivePrice()` with `clientId` and `currency` per D3, unchanged behaviour without a client
  - Verify: PHPUnit `tests/Unit/Service/ProductCatalogServiceTest.php`: own list wins, segment beats country, priority and tie order, wrong currency ignored, expired list ignored, entry tiers, no client gives today's answer
- [ ] 1.3 `resolvePrice()` accepts `clientId` and `currency`; the client is read with the caller's rights
  - Verify: PHPUnit on the controller; hydra gates route-auth, no-admin-idor and route-reachability pass on the diff
- [ ] 1.4 `LeadProducts.vue` asks the resolver on add and on quantity change, shows the list, keeps a typed price
  - Verify: Vitest with a mocked resolver; Playwright `tests/e2e/price-lists.spec.ts`: a public-sector client gets the public-sector price on a new line
- [ ] 1.5 Price lists index and detail pages; ClientDetail country and price list fields
  - Verify: Playwright creates a list with two entries and sees them on the detail page
- [ ] 1.6 `docs/Features/price-lists.md` and Dutch strings
  - Verify: docs build exit 0; `npm run test:l10n` exit 0

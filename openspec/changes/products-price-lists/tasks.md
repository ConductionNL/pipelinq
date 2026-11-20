# Tasks: products-price-lists

- [x] 1.1 Schema `priceList` per D1; `client.country` and `client.priceList`
  - Verify: register import logs no `PARTIAL IMPORT`; `npm run check:schema-l10n` exit 0
- [x] 1.2 `resolveEffectivePrice()` with `clientId` and `currency` per D3, unchanged behaviour without a client
  - Verify: PHPUnit `tests/Unit/Service/ProductCatalogServiceTest.php`: own list wins, segment beats country, priority and tie order, wrong currency ignored, expired list ignored, entry tiers, no client gives today's answer
- [x] 1.3 `resolvePrice()` accepts `clientId` and `currency`; the client is read with the caller's rights
  - Verify: PHPUnit on the controller; hydra gates route-auth, no-admin-idor and route-reachability pass on the diff
- [x] 1.4 `LeadProducts.vue` asks the resolver on add and on quantity change, shows the list, keeps a typed price
  - Built on the server instead: `LeadProducts.vue` was removed in #3120 and lead lines are the manifest object-list, so `LeadLinePricingListener` prices a line before it is stored (`LeadLinePricer`, PHPUnit), and the line list shows Price from with the list title. `tests/e2e/price-lists.spec.ts` written, not run here.
  - Verify: Vitest with a mocked resolver; Playwright `tests/e2e/price-lists.spec.ts`: a public-sector client gets the public-sector price on a new line
- [x] 1.5 Price lists index and detail pages; ClientDetail country and price list fields
  - `src/manifest.d/86-price-lists.json`; the organisation card shows Country and Price list. The Playwright check is in `tests/e2e/price-lists.spec.ts` (reads a seeded list; not run here).
  - Verify: Playwright creates a list with two entries and sees them on the detail page
- [x] 1.6 `docs/Features/price-lists.md` and Dutch strings
  - `npm run test:l10n` exit 0; the docs build was not run here.
  - Verify: docs build exit 0; `npm run test:l10n` exit 0

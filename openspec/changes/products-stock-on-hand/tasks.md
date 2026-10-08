# Tasks: products-stock-on-hand

## 1. Read stock from shillinq

- [ ] 1.1 Add `lib/Service/ShillinqStockReader.php` on the pattern of `ShillinqInvoiceReader`: probe `OCA\Shillinq\AppInfo\Application`, lazy object service, one read of register `shillinq` schema `InventoryStock` filtered on `productId`, capped at 50 rows, as the current user [V1]
  - Verify: PHPUnit `tests/Unit/Service/ShillinqStockReaderTest.php` with real shillinq-shaped rows: sum over two locations, available falls back to on hand minus reserved, empty answer when the probe fails
- [ ] 1.2 Add `ProductStockController::show()` and route `GET /api/products/{id}/stock` (`#[NoAdminRequired]`, product read checked through OpenRegister), answering `{tracked, available, onHand, reserved, unit, locations[], state}` with `state` one of `ok`, `untracked`, `no-shillinq`, `no-access` [V1]
  - Verify: PHPUnit for the four states; hydra gates route-auth and no-admin-idor pass on the diff

## 2. Supply card

- [ ] 2.1 Add a Supply card widget to ProductDetail in `src/manifest.json` (right column, under Related), as on board `PqProduct`; if `product-vendor-master` has already added it, add only the Stock tracked line [V1]
  - Verify: `npm run check:manifest` exit 0
- [ ] 2.2 Render the Stock tracked line from 1.2: "Yes, <available> <unit>", the location line for two or more locations, the tooltip with on hand and reserved, and the texts for the three other states [V1]
  - Verify: Vitest for the widget's four states; Playwright `tests/e2e/product-stock.spec.ts` opens a seeded product with stock in shillinq and reads the number

## 3. Seed and text

- [ ] 3.1 Give two demo products `stockTracked` true and one service `stockTracked` false in `lib/Settings/pipelinq_example_register.json`, with `productId` matching shillinq's stock seeds [V1]
  - Verify: after a clean install with both apps, ProductDetail of a seeded product shows a number
- [ ] 3.2 Add every new string to `l10n/en.json` and `l10n/nl.json` [V1]
  - Verify: `npm run check:l10n-js` exit 0

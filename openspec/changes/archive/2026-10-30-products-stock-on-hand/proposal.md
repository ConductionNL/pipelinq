---
kind: code
depends_on: []
---

# Proposal: products-stock-on-hand

## Summary

Open a product and see how many you have left before you promise it. The
product page gets a Stock line in its Supply card: the quantity available,
summed over every location shillinq keeps, with a link to the stock in
shillinq. Pipelinq reads the number. Shillinq keeps it.

## Motivation

One row of the pipelinq capability matrix (`openspec/parity/capabilities.json`).

**`stock-levels`**, "Know how many you have left before you promise it to
somebody". Rated partial, built.state building, owner ConductionNL/shillinq.
Matrix evidence: `lib/Service/PosTransactionService.php:692` emits the stock
movement to shillinq, "no stock page exists here". providerHow: "pipelinq
emits a stock movement and Shillinq keeps the count, so no pipelinq page shows
a level".

Shillinq already holds the count. Its `InventoryStock` schema
(`shillinq lib/Settings/register.d/inventory-stock-tracking.json`, spec
`inventory-stock-tracking`) keeps one record per product and location with
`quantityOnHand`, `quantityReserved` and a computed `quantityAvailable`, keyed
on pipelinq's `product.productId`. What is missing is the read on the pipelinq
side, where a salesperson looks before quoting.

Competitors, from the matrix: odoo-crm yes, espocrm partial, hubspot-crm and
pipedrive unknown, kiss no.

## What changes

- A read-only service in pipelinq fetches shillinq's `InventoryStock` records
  for one product and sums `quantityAvailable` and `quantityOnHand`.
- ProductDetail gets the Supply card the board draws, with a Stock line:
  "Yes, 1,860" with the unit, or "Not tracked" when the product's
  `stockTracked` is false.
- The Stock line names the locations when there are several, and links to the
  product's stock in shillinq ("Open in shillinq").
- Without shillinq the Stock line says "Stock is kept in shillinq, which is
  not installed" and nothing else changes.

## Out of scope

- Writing stock. Every movement stays shillinq's (`inventory-pos-decrement`,
  `inventory-stock-movement-ledger`).
- Reserving stock for a quote or a lead.
- A warning on a lead or quote line when the quantity exceeds stock. The
  boards `PqLead` and `PqOfferte` draw no stock column; that needs a board
  first.
- The rest of the Supply card (manufacturer, unit, weight, vendor). It belongs
  to `product-vendor-master`; this change adds the Stock line to it and draws
  the card if it is not there yet.

## Impact

- New `lib/Service/ShillinqStockReader.php`, in the pattern of
  `lib/Service/ShillinqInvoiceReader.php` (duck-typed probe, lazy object
  service, read-only).
- One route, `GET /api/products/{id}/stock`, in `appinfo/routes.php` with
  `ProductStockController`.
- `src/manifest.json` page ProductDetail: the Supply card widget.
- Cross-repo: none. Shillinq's schema already carries everything read here.

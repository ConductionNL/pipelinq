---
kind: code
depends_on: []
---

# Proposal: products-price-lists

## Summary

Keep a separate price list for a customer group, such as public sector, or for a
country, and let a quote pick it up by itself for the client it is for. You can
also give one client its own list. Today a product has one base price, variant
prices and quantity tiers, and a quote line copies the base price in the
browser.

## Motivation

One row of the pipelinq capability matrix (`openspec/parity/capabilities.json`,
compared 2026-09-24), decided `build` by the OpenSpec pass of 2026-09-27.

**`prod-price-lists`**, "Keep a separate price list for a customer group or a
country and quote from it". Rated no, built.state none. Matrix evidence: "a
product has one base unitPrice, variant overrides and quantity tiers
(lib/Service/ProductCatalogService.php:163 resolveTier 'highest minQuantity that
is at most the quantity'); no price list per customer group or country exists in
the register (grep for priceList, customerGroup, country)". Note: "quantity tiers
only; a price list per group or country is missing". Demand: changelog,
https://developers.hubspot.com/changelog/fall-2026-spotlight. Two competitors
rate it yes:

- hubspot-crm: https://developers.hubspot.com/changelog/fall-2026-spotlight, "The
  Price Books API is now generally available for Commerce Hub Professional and
  Enterprise accounts", with price books and price book items managed in app and
  over the API.
- odoo-crm: source read, `addons/product/models/product_pricelist.py:49`
  `country_group_ids` and :36 `currency_id`: a pricelist per country group or
  currency, assigned per customer on the contact form and applied on the quote.

pipedrive and espocrm rate it partial.

## What changes

- A Price lists page under Products: a list has a name, a currency, a validity
  period, the customer segments and countries it applies to, a priority, and a
  price per product (optionally per variant and from a minimum quantity).
- A client can be given its own price list, and gets a country.
- The server decides a quote line's price: the client's own list, else the
  highest-priority active list for the client's segment, else for its country,
  else the product's own tiers and base price. The line shows which list priced
  it.
- A salesperson can still type a different price; the line then says it was
  set by hand.

## Out of scope

- Converting a list's prices into another currency. A lead in a currency with no
  list in that currency falls back to the product's own price
  (`pipeline-live-exchange-rates` converts for the forecast only).
- Discount rules (percentage off a whole list). A list holds prices.

## Impact

- New schema `priceList`; `client` gains `priceList` and `country`.
- `lib/Service/ProductCatalogService.php::resolveEffectivePrice()` and
  `POST /api/products/resolve-price` take the client.
- `src/components/LeadProducts.vue` calls the resolver when a product is added.
- New Price lists index and detail pages in the manifest.

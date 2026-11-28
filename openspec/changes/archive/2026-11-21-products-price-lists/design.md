# Design: products-price-lists

## Context (read at pipelinq development 418bdc4ad)

- **Product prices.** `lib/Settings/pipelinq_register.json` `product` has
  `unitPrice`, `variants`, `priceTiers`, `vatClass` among others.
  `ProductCatalogService::resolveEffectivePrice(product, quantity, variantSku)`
  (:207) starts from `unitPrice`, applies a variant override
  (`resolveVariantPrice`) and a quantity tier (`resolveTier`, :168, "highest
  minQuantity that is at most the quantity"), and returns the price, its source
  and the tax rate. It is served at `POST /api/products/resolve-price`
  (`appinfo/routes.php:238`, `ProductCatalogController::resolvePrice()` :132,
  `#[NoAdminRequired]`, rejects a negative quantity).
- **Quote lines.** `leadProduct` has `lead, product, quantity, unitPrice, unit,
  discount, discountType, total, notes`. `src/components/LeadProducts.vue:351`
  copies `product.unitPrice` into the add form in the browser; the resolver is
  not called.
- **Clients.** `client.address` is a free string (no country);
  `client.segment` (`register.d/15-unify-client-contact.json`) is a facetable
  string such as SMB, enterprise or public-sector.

## Decisions

### D1. A price list is one record with its entries

`priceList`: `title`, `currency` (ISO 4217), `validFrom`, `validUntil`,
`segments[]`, `countries[]` (ISO 3166-1 alpha-2), `priority` (integer),
`isActive`, `entries[]` (`product` ref, optional `variantSku`, `minQuantity`
default 1, `unitPrice`). Entries live inside the list so a list is copied,
archived and audited as one thing.

### D2. The client carries a country and may carry its own list

`client` gains `country` (alpha-2, default from app config, NL) and `priceList`
(ref). No attempt is made to parse `address`.

### D3. Resolution order, on the server

`resolveEffectivePrice()` gains an optional `clientId` and `currency`. With a
client it looks for an entry for the product (and variant) in, in order: the
client's own `priceList`; active lists valid today whose `segments` contain the
client's `segment`, highest `priority` first; active lists whose `countries`
contain the client's `country`, highest `priority` first. Only lists whose
`currency` equals the requested currency (default the reporting currency)
count. Within a list the entry with the highest `minQuantity` at most the
quantity wins. Without a match the current variant and tier logic runs
unchanged. The response adds `priceListId` and `priceListTitle`, and `source`
becomes `price-list` when a list priced it.

### D4. Quote lines ask the server

`LeadProducts.vue` calls `resolve-price` with the lead's client, currency and
the quantity when a product is added or its quantity changes, and shows "Price
from list {title}" under the line. When the user types a price, the line keeps
it and shows "Price set by hand"; changing the quantity then does not overwrite
it.

### D5. Pages

Price lists index (`/price-lists`) and detail with an entries table, under the
Products menu group. ClientDetail shows its country and price list, editable.

## Risks

- A product in several matching lists of equal priority: ties go to the list
  with the most recent `validFrom`, then the lowest id, so the answer is stable.
- Existing quote lines are not repriced. Only new lines and quantity changes ask
  the resolver.

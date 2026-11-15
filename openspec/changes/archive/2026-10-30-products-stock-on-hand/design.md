# Design: products-stock-on-hand

## Context (read at pipelinq development 920f14e5f, shillinq development)

- Pipelinq's `product` schema carries `productId` and `stockTracked`
  (`lib/Settings/register.d/92-product-supply-master.json:10` and `:90`).
  `productId` is "the FK" shillinq stock-keeping uses.
- Shillinq's `InventoryStock` (`shillinq
  lib/Settings/register.d/inventory-stock-tracking.json`) has `productId`,
  `locationCode`, `locationName`, `quantityOnHand`, `quantityReserved`,
  `quantityAvailable` (computed on read) and `status`, one record per product
  and location.
- `lib/Service/ShillinqInvoiceReader.php` is the precedent for reading
  shillinq's register from pipelinq: a probe on
  `OCA\Shillinq\AppInfo\Application`, the object service fetched lazily from
  the container, paged reads, and an empty answer when shillinq is absent.
- ProductDetail (`src/manifest.json:3716`) is a `type: detail` page with the
  widgets product-data, product-related, product-kpis, product-lines and
  product-files. There is no Supply card yet.

## Board

Canvas `5NkFW28vZUUij43xzxHg5a`, board `PqProduct` (pipelinq: product). Its
Supply card lists Manufacturer, Unit of measure, Weight, Dimensions, "Stock
tracked: Yes, 1,860 blank cards", Vendor, and a link "Open in shillinq" with
the note "The vendor master lives in shillinq as a payee."

This change implements the "Stock tracked" line exactly as drawn: the label
"Stock tracked", the value "Yes, " followed by the available quantity and the
unit, in the Supply card on the right of the product page. "Open in shillinq"
stays the card's one link.

## Decisions

**D1. Available, not on hand.** The board shows one number. The number a
salesperson can promise is `quantityAvailable` (on hand minus reserved). The
tooltip on the number gives on hand and reserved.

**D2. Sum over locations.** One product can sit in several locations. The line
shows the sum; when there are two or more locations, a second line lists them
("Amsterdam 1,200 · Utrecht 660").

**D3. Read on page load, no copy.** Pipelinq stores no stock number. A copy
would go stale at the first sale (ADR-022: the owner keeps the data). The read
is one query filtered on `productId`, capped at 50 locations.

**D4. A thin controller, not a frontend read of shillinq's register.** The
frontend could query OpenRegister for shillinq's schema directly, but then it
needs shillinq's register and schema ids and the shillinq probe in the
browser. The controller keeps the probe server-side, as the invoice reader
does, and answers `{tracked, available, onHand, reserved, unit, locations[]}`.

**D5. The user's own rights apply.** The read runs as the logged-in user. A
user who may not read shillinq's stock gets "No access to stock in shillinq"
and the rest of the card.

## Risks

- `quantityAvailable` is computed on read in shillinq. If shillinq stops
  computing it, the line would show nothing; the reader falls back to
  `quantityOnHand - quantityReserved`.

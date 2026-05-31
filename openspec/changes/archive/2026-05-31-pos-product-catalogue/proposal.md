# Proposal: pos-product-catalogue

## Problem

The pipelinq `product` entity is a flat CRM catalog item: name, SKU, price, category, type, and taxRate. This is adequate for CRM deal-line items but insufficient as a POS-grade product master. Market intelligence covering 13/13 sampled competitors reveals four structural gaps:

1. **No variant support** — Competitors (Lightspeed, Square, Odoo, Korona, Unicenta) all support size × colour matrices with per-variant SKUs and price overrides. Without variants, a single T-shirt in three sizes requires three separate product records; there is no way to express that they are the same item.
2. **No modifier groups** — Competitors (Square, Toast, Mews) use modifier groups (e.g. "Melksoort", "Extra topping") to capture configurable add-ons at checkout. POS lines cannot express these combinations without embedded modifier data on the product.
3. **No quantity-based price tiers** — Bulk pricing (e.g. single bottle €2.50; case of 12 €2.10) is common in retail POS (Korona, Lightspeed) but absent from the current schema.
4. **BTW class not explicit** — `taxRate` stores a raw percentage (default 21). Dutch retail requires an explicit BTW class (`hoog`, `laag`, `nul`, `vrijgesteld`) so that a POS receipt can print the correct BTW label per item without recomputing class from the rate.

Additionally, barcode lookup (EAN/UPC) is present in all 13 sampled competitors but there is no dedicated barcode field; SKU is used as a proxy.

Shillinq (the invoicing app) treats pipelinq as the single product master. These gaps mean shillinq cannot render a legally compliant Dutch sales invoice nor support a full POS checkout flow.

## Solution

Extend the existing `product` schema in `pipelinq_register.json` with six new properties:

| New property | Type | Purpose |
|---|---|---|
| `barcode` | string | EAN-13/UPC-A for scanner lookup (schema:gtin) |
| `btwClass` | string enum | Dutch BTW class (hoog/laag/nul/vrijgesteld) |
| `variants` | array of objects | SKU × attribute matrix with per-variant price/barcode |
| `modifierGroups` | array of objects | Named add-on groups with options and price adjustments |
| `priceTiers` | array of objects | Quantity break pricing (minQuantity → unitPrice) |
| `duration` | integer | Service duration in minutes (behandelingen, afspraken) |

Add a `ProductVariantPanel.vue` component to the product detail view for managing the variant matrix inline. Add a `ModifierGroupPanel.vue` for modifier CRUD. Extend the product form with barcode field and BTW class selector.

No new OpenRegister schemas are introduced — all additions are properties on the existing `product` schema and embedded sub-objects within it.

## Scope

- `product` schema extension with six new properties
- `productCategory` schema unchanged
- `ProductVariantPanel.vue` — inline variant matrix management on ProductDetail
- `ModifierGroupPanel.vue` — inline modifier group management on ProductDetail
- `PriceTierTable.vue` — inline price tier table on ProductDetail
- Product form: barcode field, BTW class dropdown, duration field (conditional on type=service)
- Seed data: 5 realistic Dutch product objects demonstrating all new fields
- Deduplication check

## Out of scope

- Stock/inventory management (min stock, reorder points, expiry tracking)
- Serial number tracking
- Kit / assembly products
- Barcode label printing (DYMO/Zebra)
- Subscription/weight pricing
- Matrix bulk import from CSV
- Shillinq integration API (separate change)

## Success Criteria

- A product with 6 size × colour variants can be defined; each variant has its own SKU, price override, and barcode
- A food item can have a required modifier group "Melksoort" with priced options
- A product with 3 price tiers shows the correct unit price when quantity thresholds are applied
- Every product has an explicit `btwClass` that maps to `taxRate` without manual rate entry
- Barcode field is searchable and a scan input in the product search triggers lookup by barcode

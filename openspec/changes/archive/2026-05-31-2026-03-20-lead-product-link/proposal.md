# Proposal: lead-product-link

## Problem

The `LeadProducts` component is partially implemented but has three functional gaps that reduce usability for sales reps:

1. **SKU search unavailable**: The "Add Product" dialog only supports name-based matching. Sales reps who know a product's SKU code cannot use it to find the product, forcing them to recall the exact product name.
2. **Notes not displayed**: The `notes` field on `LeadProduct` line items is saved to the register but is not visible or editable in the line items table. This data is silently inaccessible from the UI after creation.
3. **Auto-recalculation broken**: The lead value only auto-updates from line items when the current value is `0` or `null`. Per the original specification, it should automatically recalculate whenever line items change, unless a manual override has been explicitly set by the user.

These gaps were identified post-implementation and represent missing behaviour from the original `2026-03-15-add-product-and-prospect-widget` specification.

## Proposed Change

Fix the three gaps in `LeadProducts.vue` and `LeadDetail.vue`:

1. **SKU search**: Include the product SKU in option labels (`"Product Name (SKU-001)"`) so that NcSelect's built-in string filter matches on both product name and SKU.
2. **Notes column**: Add a "Notes" column to the line items table. Display `item.notes` inline and make it editable with save-on-change behaviour.
3. **Auto-recalculation**: Introduce a `_valueOverride` flag in component state. When line items change, auto-sync the lead value unless a manual override exists. Override is detected by comparing the current lead value against the previous computed product total.

## Scope

### In scope
- SKU included in product dropdown option labels and filter matching
- Notes column visible and inline-editable in the line items table
- Auto-recalculation of lead value on every line item change (add, edit quantity/price/discount, remove)
- Manual override detection — lead value not overwritten when user has set a custom value

### Out of scope
- Currency configuration (EUR assumed throughout)
- Multi-currency support
- New register schemas or backend changes — `lead` and `leadProduct` entities are unchanged
- A "Recalculate from products" reset button for overrides (future enhancement)

## Impact

- **Sales reps**: Can search products by SKU; can view and edit line item notes; lead value stays accurate automatically after any line item change
- **Data integrity**: Notes stored on `LeadProduct` objects are now surfaced in the UI, preventing silent data loss
- **Pipeline board**: Lead values on kanban cards and stage totals update immediately when line items change
- **No data migration**: All changes are frontend-only; no stored data is affected

## Dependencies

- `2026-03-15-add-product-and-prospect-widget` (archived) — original `LeadProducts.vue` and `LeadDetail.vue` implementation that this change builds on
- OpenRegister `lead` schema (ADR-000: `lead.value`) — no changes to schema required
- OpenRegister `leadProduct` schema (ADR-000: `leadProduct.notes`) — field already exists; only UI surfacing is missing
- OpenRegister `product` schema (ADR-000: `product.sku`) — field already exists; only included in labels

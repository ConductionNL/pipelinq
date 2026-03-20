# Design: lead-product-link

## Changes

### LeadProducts.vue

1. **SKU search**: Update `productOptions` computed to include SKU in label: `"Product Name (SKU-001)"`. The NcSelect filter will then match on both name and SKU.

2. **Notes column**: Add a "Notes" column to the line items table. Display `item.notes` inline, with an editable input that saves on change.

3. **Auto-recalculation**: Add a `_valueOverride` flag. When the user explicitly sets a lead value different from the product total, track it. When line items change and no override exists, auto-sync the lead value.

### LeadDetail.vue

Update `onProductValueChanged` to always auto-update unless a manual override flag is set. The override is detected by comparing current lead value to previous product total.

## Files Changed

- `src/components/LeadProducts.vue` (modified)
- `src/views/leads/LeadDetail.vue` (modified)

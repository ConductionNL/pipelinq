# Proposal: product-catalog margin display, safe delete, and active filter

## Problem

The product-catalog spec identifies V1 gaps:
1. No margin calculation display on product detail page (unitPrice - cost, margin %)
2. Deleting a product linked to leads uses simple confirm() without checking for linked leads
3. Lead product dialog shows inactive products — should filter to active only
4. Inactive products on existing leads are not visually distinguished

## Proposed Change

1. Add margin display to ProductDetail.vue showing margin EUR and margin % when cost is set
2. Enhance `confirmDelete()` to check for linked LeadProduct items and warn/offer to deactivate instead
3. Filter LeadProducts.vue product dropdown to only show active products
4. Add visual marking for inactive products in existing lead line items

### Out of Scope
- Product image upload UI
- Product import/export (CSV)
- Product bundling (Enterprise)
- Category hierarchy tree UI
- Category-level revenue aggregation

## Impact
- **Files modified**: 2 (ProductDetail.vue, LeadProducts.vue)
- **Risk**: Low — additive UI changes only

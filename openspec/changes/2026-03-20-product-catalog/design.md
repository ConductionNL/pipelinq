# Design: product-catalog margin display, safe delete, and active filter

## Margin Display

Add a "Margin" row in the ProductDetail.vue info grid. When `cost` is set and > 0:
- Show margin EUR: `unitPrice - cost`
- Show margin %: `((unitPrice - cost) / unitPrice * 100).toFixed(1)`
- Color: green for positive margin, red for negative

## Safe Delete with Linked Leads Check

In `confirmDelete()`:
1. Check if `linkedLeads.length > 0`
2. If yes, show a warning dialog with count of linked leads
3. Offer "Set to inactive" as primary action, "Delete anyway" as secondary
4. "Set to inactive" sets `status: 'inactive'` and redirects to list

## Active-Only Product Filter in Lead Dialog

In LeadProducts.vue `productOptions` computed:
- Filter `this.products` to only include products where `status !== 'inactive'`
- This ensures inactive products don't appear in the "Add Product" dropdown

## Inactive Product Visual Marking

In LeadProducts.vue line items table:
- Check each line item's product status by looking up the product in `this.products`
- If product status is 'inactive', add a visual badge "(inactive)" with greyed-out styling

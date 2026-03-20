# Delta Spec: product-catalog margin display, safe delete, and active filter

## Newly Implemented

- **Margin calculation display**: ProductDetail.vue shows margin (unitPrice - cost) and margin percentage when cost is set. Positive margins styled green, negative red.
- **Delete product linked to leads**: ProductDetail.vue checks for linked LeadProduct items before deletion. Warns user with lead count and offers "Set to inactive" as primary action alternative.
- **Only active products in lead dialog**: LeadProducts.vue filters product dropdown to only show products with `status !== 'inactive'`.
- **Inactive product visual marking on leads**: LeadProducts.vue shows "(inactive)" badge next to product names in line items where the product has been deactivated.

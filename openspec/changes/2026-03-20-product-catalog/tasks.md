# Tasks: product-catalog margin display, safe delete, and active filter

## 1. Margin calculation display
- [ ] 1.1 Add margin EUR and margin % to ProductDetail.vue info grid
  - **spec_ref**: `specs/product-catalog/spec.md#Margin calculation display`
  - **files**: `pipelinq/src/views/products/ProductDetail.vue`

## 2. Safe delete with linked leads check
- [ ] 2.1 Enhance confirmDelete() to check linkedLeads and offer deactivation
  - **spec_ref**: `specs/product-catalog/spec.md#Delete a product linked to leads`
  - **files**: `pipelinq/src/views/products/ProductDetail.vue`

## 3. Active-only filter in lead product dialog
- [ ] 3.1 Filter product dropdown to active products only in LeadProducts.vue
  - **spec_ref**: `specs/product-catalog/spec.md#Only active products in lead dialog`
  - **files**: `pipelinq/src/components/LeadProducts.vue`

## 4. Inactive product visual marking on leads
- [ ] 4.1 Add inactive badge to line items with inactive products
  - **spec_ref**: `specs/product-catalog/spec.md#Inactive product on existing lead`
  - **files**: `pipelinq/src/components/LeadProducts.vue`

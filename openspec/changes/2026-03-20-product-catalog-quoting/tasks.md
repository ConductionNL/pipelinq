# Tasks: product-catalog-quoting foundation

## 1. Schema definitions
- [ ] 1.1 Add quote and quoteLineItem schemas to pipelinq_register.json
  - **spec_ref**: `specs/product-catalog-quoting/spec.md#Quote entity`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`

## 2. Store registration
- [ ] 2.1 Register quote and quoteLineItem in store initialization
  - **spec_ref**: `specs/product-catalog-quoting/spec.md#Quote entity`
  - **files**: `pipelinq/src/store/store.js`

## 3. Quote list view
- [ ] 3.1 Create QuoteList.vue with status filtering and search
  - **spec_ref**: `specs/product-catalog-quoting/spec.md#Quote list view`
  - **files**: `pipelinq/src/views/quotes/QuoteList.vue`

## 4. Quote detail view
- [ ] 4.1 Create QuoteDetail.vue with line items and financial summary
  - **spec_ref**: `specs/product-catalog-quoting/spec.md#Quote detail view`
  - **files**: `pipelinq/src/views/quotes/QuoteDetail.vue`

## 5. Quote line items component
- [ ] 5.1 Create QuoteLineItems.vue for managing quote line items
  - **spec_ref**: `specs/product-catalog-quoting/spec.md#Quote line items`
  - **files**: `pipelinq/src/components/QuoteLineItems.vue`

## 6. Routes and navigation
- [ ] 6.1 Add quote routes and navigation entry
  - **spec_ref**: `specs/product-catalog-quoting/spec.md#Quote list and overview views`
  - **files**: `pipelinq/src/router/index.js`, `pipelinq/src/navigation/navigation.js`

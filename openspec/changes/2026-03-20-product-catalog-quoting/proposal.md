# Proposal: product-catalog-quoting foundation

## Problem

The product-catalog-quoting spec defines a complete quoting/proposal system that does not exist at all. This change implements the foundation: schema definitions, store registration, basic CRUD views, and navigation.

## Proposed Change

1. Add `quote` and `quoteLineItem` schemas to `pipelinq_register.json`
2. Register `quote` and `quoteLineItem` in `store.js` initialization
3. Add quote list view with status filtering
4. Add quote detail view with line items table, status display, and financial summary
5. Add quote create dialog
6. Add routes for `/quotes` and `/quotes/:id`
7. Add "Quotes" to navigation

### Out of Scope
- PDF generation (requires Docudesk integration)
- Quote lifecycle status transitions beyond basic status field
- Auto-expiry logic
- Quote-to-order conversion
- Quote acceptance updating lead value
- Email/notification workflow for sending quotes

## Impact
- **Files modified**: 3 (pipelinq_register.json, store.js, router/index.js)
- **Files created**: 3 (QuoteList.vue, QuoteDetail.vue, QuoteLineItems.vue)
- **Risk**: Low — additive, new schemas and views

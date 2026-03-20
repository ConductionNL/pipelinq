# Delta Spec: product-catalog-quoting foundation

## Newly Implemented

- **Quote entity schema**: `quote` schema added to register with all properties (quoteNumber, lead, client, contact, status, title, sentDate, expiryDate, subtotal, taxRate, taxAmount, total, paymentTerms, notes, version, assignee). Status enum: concept, verzonden, geaccepteerd, afgewezen, verlopen.
- **QuoteLineItem entity schema**: `quoteLineItem` schema added with properties (quote, product, description, quantity, unitPrice, discount, total, sortOrder, notes).
- **Store registration**: Both schemas registered in store initialization.
- **Quote list view**: QuoteList.vue with CnIndexPage, status filtering, search by quote number/title.
- **Quote detail view**: QuoteDetail.vue with CnDetailPage, status badge, line items table, financial summary (subtotal, BTW amount, total).
- **Quote line items component**: QuoteLineItems.vue for inline CRUD of line items with product search, discount calculation, grand total.
- **Routes**: `/quotes` and `/quotes/:id` added to router.
- **Navigation**: "Quotes" entry added.

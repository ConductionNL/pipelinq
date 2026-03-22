# Design: product-catalog-quoting foundation

## Quote Schema

Added to `pipelinq_register.json` as `quote` with `@type: schema:Offer`. Properties: quoteNumber, lead, client, contact, status (enum: concept/verzonden/geaccepteerd/afgewezen/verlopen), title, sentDate, expiryDate, subtotal, taxRate, taxAmount, total, paymentTerms, notes, version, assignee.

## QuoteLineItem Schema

Added as `quoteLineItem` with `@type: schema:Offer`. Properties: quote, product, description, quantity, unitPrice, discount, total, sortOrder, notes.

## Store Registration

Both `quote` and `quoteLineItem` registered in `initializeStores()` via `objectStore.registerObjectType()`.

## Views

- **QuoteList.vue**: Uses `CnIndexPage` with status filter dropdown, search, and "New Quote" action. Columns: quote number, title, client, subtotal, status, sent date.
- **QuoteDetail.vue**: Uses `CnDetailPage` with header info, status badge, line items table (QuoteLineItems component), financial summary (subtotal, BTW, total), and action buttons.
- **QuoteLineItems.vue**: Table component for managing line items on a quote, similar to LeadProducts.vue. Supports add (from product catalog or custom), inline editing of quantity/price/discount, remove, and drag-to-reorder (via sortOrder).

## Routes

- `/quotes` -> QuoteList
- `/quotes/:id` -> QuoteDetail

## Navigation

"Quotes" added to the navigation component alongside existing entries.

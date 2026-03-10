## Why

Pipelinq manages leads through a sales pipeline but has no way to generate formal quotations (offertes). When a lead reaches the "Proposal" or "Negotiation" stage, sales reps need to produce a structured document listing products, quantities, prices, discounts, and terms — then send it to the client and track acceptance or rejection. Currently this requires switching to external tools (Word, Excel, PDF generators), breaking the CRM workflow. Adding a quotation system closes the lead-to-quote gap, keeps the sales process inside Nextcloud, and enables accurate revenue tracking.

## What Changes

- Introduce a **Quotation** entity that links to a lead, client, and contact, containing line items with products, quantities, pricing, and discounts
- Introduce a **QuotationLineItem** entity for individual product/service rows on a quotation
- Add a quotation **lifecycle** (draft → sent → accepted → rejected → expired) with status tracking
- Add a **quotation list view** for browsing all quotations with search, sort, and filter
- Add a **quotation detail view** showing line items, totals (subtotal, tax, discount, grand total), client info, and validity period
- Add ability to **create a quotation from a lead** (pre-populate client, contact, and products from LeadProduct line items)
- Add **PDF export** of quotations for sending to clients
- Update the **lead detail view** to show linked quotations
- Add quotation **numbering** (auto-incrementing reference numbers)

## Capabilities

### New Capabilities
- `quotation-management`: Core quotation entity, line items, lifecycle management, list and detail views, PDF export, lead-to-quote creation, and quotation numbering

### Modified Capabilities
- `lead-management`: Lead detail view gains a "Quotations" section showing linked quotations with status and totals
- `lead-product-link`: When creating a quotation from a lead, LeadProduct line items are copied as QuotationLineItems

## Impact

- **Data model**: Two new OpenRegister schemas (`quotation`, `quotation-line-item`) in the `pipelinq` register
- **Frontend**: New Vue views/components for quotation list, detail, and PDF preview; updated lead detail view
- **Backend**: New PDF generation service (using Nextcloud's wkhtmltopdf or similar); new schema definitions in `pipelinq_register.json`
- **Pipeline**: Quotations link to leads — moving a lead to "Won" after an accepted quotation becomes a natural workflow
- **Procest bridge**: No direct impact — quotations are pre-case (sales side), not request-to-case flow
- **Admin settings**: Quotation numbering format, default validity period, company details for PDF header

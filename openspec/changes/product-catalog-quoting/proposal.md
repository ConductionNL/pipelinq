# Product Catalog Quoting

## Problem
The product catalog exists (products, categories, line items) but there is no quoting/proposal layer. Users cannot generate formal quotes from leads, track quote lifecycle, or produce PDF proposals.

## Proposed Solution
Add a Quote entity with lifecycle management (concept -> verzonden -> geaccepteerd/afgewezen/verlopen), line items with quantities and discounts, tax calculation, and PDF export via Docudesk. Enterprise feature tier.

## Impact
- New `quote` and `quoteItem` schemas
- Quote management UI on lead detail view
- PDF generation via Docudesk integration
- Quote number auto-generation

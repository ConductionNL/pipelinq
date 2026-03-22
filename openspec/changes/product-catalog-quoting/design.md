# Product Catalog Quoting - Design

## Approach
1. Add quote and quoteItem schemas to pipelinq_register.json
2. Build quote management UI integrated with lead detail view
3. Implement quote number generation (OFF-YYYY-NNNN)
4. Integrate with Docudesk for PDF generation with NL Design tokens
5. Add quote lifecycle management with status transitions

## Files Affected
- `lib/Settings/pipelinq_register.json` - Add quote, quoteItem schemas
- `lib/Service/QuoteService.php` - Quote number generation, lifecycle management
- `src/views/quotes/QuoteDetail.vue` - Quote editor view
- `src/views/quotes/QuoteList.vue` - Quote list view
- `src/components/QuoteLineItems.vue` - Line item management with totals
- `src/views/leads/LeadDetail.vue` - Add "Offerte maken" action

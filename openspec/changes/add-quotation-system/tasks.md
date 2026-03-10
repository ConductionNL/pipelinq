## 1. Schema & Register Setup

- [ ] 1.1 Add `quotation` schema to `lib/Settings/pipelinq_register.json` (OpenAPI 3.0.0 format) with all properties from REQ-QUOT-001 (`schema:Offer` type annotation)
- [ ] 1.2 Add `quotation-line-item` schema to `lib/Settings/pipelinq_register.json` with all properties from REQ-QUOT-002 (`schema:Offer` type annotation with `itemOffered`)
- [ ] 1.3 Update the repair step to import the new schemas via `ConfigurationService::importFromApp()`
- [ ] 1.4 Verify schema creation in OpenRegister after repair step runs

## 2. Admin Settings

- [ ] 2.1 Add quotation settings fields to the admin settings page: company name, address, logo, KVK, BTW, IBAN (REQ-QUOT-009)
- [ ] 2.2 Add quotation defaults settings: reference number prefix, default validity period, default terms text, default tax rate (REQ-QUOT-009)
- [ ] 2.3 Store settings via `IAppConfig` under `pipelinq` app namespace
- [ ] 2.4 Create a `QuotationConfigService` (or extend existing ConfigurationService) to read settings with defaults

## 3. Pinia Store

- [ ] 3.1 Create `src/store/modules/quotation.js` Pinia store with CRUD actions querying OpenRegister API for quotations
- [ ] 3.2 Add quotation line item actions to the store (create, update, delete, list by quotation)
- [ ] 3.3 Add computed totals calculation (subtotal, tax, discount, grandTotal) per REQ-QUOT-010
- [ ] 3.4 Add reference number generation logic (fetch max sequence for current year, increment) per REQ-QUOT-008
- [ ] 3.5 Add status transition validation per REQ-QUOT-003 lifecycle rules

## 4. Quotation List View

- [ ] 4.1 Create `src/views/quotations/QuotationList.vue` with table columns: Reference Number, Title, Client, Status, Grand Total, Valid Until, Assigned To (REQ-QUOT-004)
- [ ] 4.2 Add search by title and client name (case-insensitive)
- [ ] 4.3 Add filters: status (multi-select), date range on validUntil
- [ ] 4.4 Add sorting on all columns
- [ ] 4.5 Add pagination (default 25 per page)
- [ ] 4.6 Register route in `src/router/index.js`

## 5. Quotation Detail View

- [ ] 5.1 Create `src/views/quotations/QuotationDetail.vue` with header (reference number, title, status badge), client/contact section, and linked lead (REQ-QUOT-005)
- [ ] 5.2 Create `src/components/quotations/QuotationLineItemsTable.vue` for line items with columns: Description, Quantity, Unit Price, Discount, Tax Rate, Total, and add/edit/delete/reorder actions (REQ-QUOT-002)
- [ ] 5.3 Create `src/components/quotations/QuotationSummary.vue` displaying Subtotal, Overall Discount, Tax Amount (grouped by rate), Grand Total (REQ-QUOT-010)
- [ ] 5.4 Add "Add Product" action with product search dropdown and "Add Custom Item" option (REQ-QUOT-002)
- [ ] 5.5 Add validity section (validFrom, validUntil) with expired warning banner (REQ-QUOT-005)
- [ ] 5.6 Add terms and conditions field and internal notes field (labeled "Internal - not shown on quotation")
- [ ] 5.7 Add status transition actions (Send, Accept, Reject, Revise) with validation and confirmation dialogs (REQ-QUOT-003)
- [ ] 5.8 Make detail view read-only for accepted/rejected/expired quotations (REQ-QUOT-005)
- [ ] 5.9 Register route in `src/router/index.js`

## 6. Create Quotation from Lead

- [ ] 6.1 Add "Create Quotation" button to lead detail view (REQ-QUOT-006)
- [ ] 6.2 Implement lead-to-quotation creation: copy lead title, client, contact, currency; copy LeadProduct items to QuotationLineItems (REQ-QUOT-006, lead-product-link delta)
- [ ] 6.3 Navigate to new quotation detail in draft status after creation

## 7. Lead Detail — Quotations Section

- [ ] 7.1 Add "Quotations" section to lead detail view showing linked quotations with reference number, title, status badge, grand total, valid until (REQ-LEAD-004 modified)
- [ ] 7.2 Add "Create Quotation" action button and empty state ("No quotations yet")
- [ ] 7.3 Add click-through navigation from quotation rows to quotation detail

## 8. PDF Export

- [ ] 8.1 Create `lib/Service/QuotationPdfService.php` using dompdf to generate PDF from quotation data (REQ-QUOT-007)
- [ ] 8.2 Create HTML template for quotation PDF: company header (logo, name, address, KVK, BTW, IBAN), client info, line items table, summary, validity, terms
- [ ] 8.3 Create API endpoint `GET /api/quotations/{id}/pdf` that returns the generated PDF
- [ ] 8.4 Add "Export PDF" button to quotation detail view calling the PDF endpoint
- [ ] 8.5 Add dompdf dependency to `composer.json`

## 9. Navigation & Integration

- [ ] 9.1 Add "Quotations" navigation item to the Pipelinq sidebar/navigation
- [ ] 9.2 Add quotation count to the dashboard (total, by status)
- [ ] 9.3 Wire "Accept quotation" → prompt to move lead to Won stage (REQ-QUOT-003)
- [ ] 9.4 Add auto-expiration check: on quotation detail load, update status to `expired` if validUntil < today and status is `sent`

## 10. Error Handling & Polish

- [ ] 10.1 Add error handling for OpenRegister unavailable (preserve form data) per REQ-QUOT-011
- [ ] 10.2 Add reference number collision retry logic per REQ-QUOT-011
- [ ] 10.3 Add i18n strings for Dutch and English (quotation labels, status names, error messages)
- [ ] 10.4 Verify WCAG AA compliance on all new views (focus management, color contrast, keyboard navigation)

# product-catalog-quoting Specification

## Purpose
Extend the existing product catalog with quote generation capabilities: line items with quantities and discounts, quote lifecycle management, and PDF proposal generation. For government context, maps to "producten en diensten" offerings with formal pricing.

## Context
The existing product-catalog spec covers product entities and categories. This spec adds the quoting layer: assembling products into formal quotes/proposals linked to leads, with discount handling, tax calculation, and PDF export. For municipalities, this enables pricing proposals for services, leges calculations for permits, and formal offers for consulting/IT services.

**Relation to existing specs:** Builds on the `product-catalog` spec (Product and ProductCategory entities). Does NOT duplicate product CRUD -- focuses exclusively on the quoting/proposal workflow.

## ADDED Requirements

### Requirement: Quote entity
The system MUST provide a Quote entity linked to leads and containing product line items.

#### Scenario: Create a quote from a lead
- GIVEN a lead "Gemeente ABC digital transformation" with value EUR 25,000
- WHEN the case worker clicks "Offerte maken" on the lead detail view
- THEN a new Quote MUST be created linked to the lead
- AND the quote MUST have status "Concept" and a generated quote number (e.g., "OFF-2026-0042")
- AND the quote MUST inherit the lead's client and contact references

#### Scenario: Quote with line items
- GIVEN a new quote
- WHEN the user adds products from the product catalog:
  - "Implementatie OpenRegister" x 1 at EUR 15,000
  - "Training beheerders" x 2 at EUR 2,500/each
  - "Support pakket" x 12 (months) at EUR 500/month
- THEN each line item MUST store: product reference, description, quantity, unit price, discount (%), line total
- AND the quote subtotal MUST be calculated as EUR 26,000
- AND BTW (21%) MUST be calculated as EUR 5,460
- AND the quote total MUST be EUR 31,460

#### Scenario: Line item discount
- GIVEN a quote line item "Implementatie OpenRegister" at EUR 15,000
- WHEN the user applies a 10% discount
- THEN the line total MUST be EUR 13,500
- AND the discount MUST be shown on the line item

### Requirement: Quote lifecycle
The system MUST support a quote lifecycle with status transitions.

#### Scenario: Quote status transitions
- GIVEN a quote in status "Concept"
- THEN the following status transitions MUST be supported:
  - Concept -> Verzonden (sent to client)
  - Verzonden -> Geaccepteerd (client accepted)
  - Verzonden -> Afgewezen (client declined)
  - Verzonden -> Verlopen (past expiry date)
  - Any status -> Concept (revert to draft for editing)

#### Scenario: Send quote to client
- GIVEN a quote in status "Concept"
- WHEN the user clicks "Verzenden"
- THEN the quote MUST transition to "Verzonden"
- AND the sent date MUST be recorded
- AND the expiry date MUST be set (default: 30 days from sent date)

#### Scenario: Accept quote updates lead
- GIVEN a quote linked to lead "Gemeente ABC deal"
- WHEN the quote is accepted
- THEN the lead's value MUST be updated to match the quote total (excl. BTW)
- AND the lead SHOULD be moved to the next pipeline stage (configurable)

### Requirement: PDF proposal generation
The system MUST generate professional PDF proposals from quotes.

#### Scenario: Generate PDF proposal
- GIVEN a complete quote with line items, client details, and terms
- WHEN the user clicks "PDF genereren"
- THEN a PDF MUST be generated containing:
  - Organization header (name, logo, contact details from admin settings)
  - Client/contact details
  - Quote number, date, expiry date
  - Line items table (description, quantity, unit price, discount, line total)
  - Subtotal, BTW, and total
  - Payment terms and conditions (configurable per organization)

#### Scenario: Customizable PDF template
- GIVEN the admin settings
- WHEN the admin uploads a PDF template or configures layout options
- THEN generated PDFs MUST use the configured template
- AND NL Design System tokens SHOULD be applied for consistent styling

### Requirement: Quote list and detail views
The system MUST provide list and detail views for managing quotes.

#### Scenario: Quote list view
- WHEN the user navigates to the Quotes section
- THEN the system MUST display a table with: quote number, client, total, status, sent date, expiry
- AND the list MUST support filtering by status and sorting by date/amount

#### Scenario: Multiple quotes per lead
- GIVEN a lead with 3 quotes (v1 rejected, v2 rejected, v3 accepted)
- WHEN the user views the lead detail
- THEN all quotes MUST be listed in the "Offertes" section
- AND the active/accepted quote MUST be visually distinguished

## Dependencies
- Product catalog spec (Product and ProductCategory entities)
- Lead management spec (lead entity and detail view)
- Docudesk for PDF generation
- Admin settings for organization details and payment terms

---

### Current Implementation Status

**Implemented:**
- Nothing from this spec is implemented. The product catalog exists (products, categories, line items), but the quoting/proposal layer does not.

**Not yet implemented:**
- **Quote entity:** No `quote` schema in `pipelinq_register.json`. No Quote CRUD.
- **Quote line items:** While `leadProduct` line items exist, there is no separate quote-scoped line item concept with discount percentages, BTW calculation, and quote-level totals.
- **Quote lifecycle:** No status transitions (Concept -> Verzonden -> Geaccepteerd/Afgewezen/Verlopen).
- **Quote numbering:** No auto-generated quote numbers (e.g., "OFF-2026-0042").
- **PDF proposal generation:** No PDF generation capability. No Docudesk integration.
- **Customizable PDF template:** No template management in admin settings.
- **Quote list and detail views:** No routes, components, or views for quotes.
- **Multiple quotes per lead:** No "Offertes" section in lead detail view.
- **BTW calculation:** The `taxRate` field exists on products but is not used in any totaling logic.
- **Quote acceptance updating lead value:** No integration between quote acceptance and lead value.
- **Send quote to client:** No email/notification workflow for sending quotes.
- **Expiry date management:** No auto-expiry logic.

**Partial implementations:**
- The `LeadProducts.vue` component provides basic line item functionality (quantity, price, discount, total) that could serve as a foundation for quote line items. However, the discount calculation currently uses subtraction instead of percentage.

### Standards & References
- **Dutch tax law:** BTW at 21% standard rate. Quotes must show subtotal, BTW, and total separately.
- **Schema.org:** `Offer` type could be extended for quotes. `Invoice` type for accepted quotes.
- **Docudesk:** Nextcloud ExApp for PDF generation. Listed as a dependency.
- **NL Design System:** PDF styling should use design tokens for consistent government branding.

### Specificity Assessment
- The spec is well-structured with clear lifecycle states and calculation rules.
- **Implementable** but requires significant new infrastructure: new schema, new views, PDF generation integration.
- **Open questions:**
  - Should quotes have their own route/navigation or be accessed only from lead detail views?
  - How should the quote number sequence be managed? Per-organization? Per-year? Via IAppConfig counter?
  - Should quotes support multiple currencies or is EUR hardcoded?
  - How does the "Any status -> Concept" transition work -- does reverting to draft unlock editing?
  - What payment terms and conditions are configurable? Free text or structured fields?
- **Dependencies:** Requires Docudesk for PDF generation, product catalog for products, lead management for lead linking, admin settings for organization details.

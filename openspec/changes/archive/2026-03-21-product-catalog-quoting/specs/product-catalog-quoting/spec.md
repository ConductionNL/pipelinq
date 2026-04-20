---
status: draft
---

# product-catalog-quoting Specification

## Purpose
Extend the existing product catalog with quote generation capabilities: line items with quantities and discounts, quote lifecycle management, and PDF proposal generation. For government context, maps to "producten en diensten" offerings with formal pricing.

## Context
The existing product-catalog spec covers product entities and categories. This spec adds the quoting layer: assembling products into formal quotes/proposals linked to leads, with discount handling, tax calculation, and PDF export. For municipalities, this enables pricing proposals for services, leges calculations for permits, and formal offers for consulting/IT services.

**Relation to existing specs:** Builds on the `product-catalog` spec (Product and ProductCategory entities). Does NOT duplicate product CRUD -- focuses exclusively on the quoting/proposal workflow.

**Feature tier:** Enterprise

**Competitor context:** Krayin CRM provides a full Quote module with QuoteItems, billing/shipping addresses, and person/user linkage. EspoCRM's Advanced Pack adds quote generation with PDF templates. Twenty CRM does not have native quoting. This spec positions Pipelinq as competitive with Krayin while leveraging Nextcloud's Docudesk for PDF generation and NL Design tokens for government-branded output.

---

## ADDED Requirements

### Requirement: Quote entity [Enterprise]
The system MUST provide a Quote entity stored as an OpenRegister object in the `pipelinq` register, using the `schema:Offer` type annotation. Each Quote is linked to a lead and contains metadata for the proposal lifecycle.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `quoteNumber` | string | computed | Auto-generated sequential number (e.g., "OFF-2026-0042") |
| `lead` | string (uuid) | YES | UUID reference to the parent Lead |
| `client` | string (uuid) | YES | UUID reference to the Client (inherited from Lead) |
| `contact` | string (uuid) | no | UUID reference to the Contact person |
| `status` | string | YES | Lifecycle status: concept, verzonden, geaccepteerd, afgewezen, verlopen |
| `title` | string | YES | Quote title/subject line |
| `sentDate` | date | no | Date the quote was sent to the client |
| `expiryDate` | date | no | Date the quote expires (default: 30 days from sentDate) |
| `subtotal` | number | computed | Sum of all line item totals (excl. BTW) |
| `taxRate` | number | no | Tax rate as percentage. Default: 21 (Dutch BTW standard rate) |
| `taxAmount` | number | computed | subtotal * (taxRate / 100) |
| `total` | number | computed | subtotal + taxAmount |
| `paymentTerms` | string | no | Free-text payment terms and conditions |
| `notes` | string | no | Internal notes (not shown on PDF) |
| `version` | number | no | Version number for revised quotes. Default: 1 |
| `assignee` | string | no | Nextcloud user ID of the quote owner |

#### Scenario: Create a quote from a lead
- GIVEN a lead "Gemeente ABC digital transformation" with value EUR 25,000
- WHEN the case worker clicks "Offerte maken" on the lead detail view
- THEN a new Quote MUST be created linked to the lead
- AND the quote MUST have status "concept" and a generated quote number (e.g., "OFF-2026-0042")
- AND the quote MUST inherit the lead's client and contact references
- AND the quote MUST inherit the lead's title as a default quote title

#### Scenario: Quote number generation
- GIVEN the most recent quote has number "OFF-2026-0041"
- WHEN a new quote is created
- THEN the system MUST generate the next sequential number "OFF-2026-0042"
- AND the prefix "OFF" MUST be configurable via admin settings
- AND the year component MUST reflect the current calendar year
- AND the sequence MUST reset to 0001 at the start of each calendar year

#### Scenario: Create quote without a lead
- GIVEN a user navigates to the Quotes section
- WHEN the user clicks "Nieuwe offerte"
- THEN a blank quote MUST be created with status "concept"
- AND the user MUST be able to manually select a client and contact
- AND the lead field MUST be optional

#### Scenario: Quote inherits lead products
- GIVEN a lead with 3 existing LeadProduct line items
- WHEN the user creates a quote from this lead
- THEN the system SHOULD offer to copy the lead's products as quote line items
- AND the user MUST be able to accept or skip the copy

#### Scenario: Duplicate quote as new version
- GIVEN a quote "OFF-2026-0042" in status "afgewezen"
- WHEN the user clicks "Nieuwe versie"
- THEN a new Quote MUST be created with the same lead, client, contact, and line items
- AND the new quote MUST have version = original version + 1
- AND the new quote MUST have status "concept"
- AND a new quote number MUST be generated

---

### Requirement: Quote line items [Enterprise]
The system MUST provide QuoteLineItem entities that compose the financial content of a quote, with support for quantities, unit pricing, percentage-based discounts, and tax calculation.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `quote` | string (uuid) | YES | UUID reference to the parent Quote |
| `product` | string (uuid) | no | UUID reference to a Product (optional for custom items) |
| `description` | string | YES | Line item description (pre-populated from Product.name if linked) |
| `quantity` | number | YES | Number of units. Default: 1. Minimum: 0.01 |
| `unitPrice` | number | YES | Price per unit in EUR |
| `discount` | number | no | Discount as percentage (0-100). Default: 0 |
| `total` | number | computed | `quantity * unitPrice * (1 - discount/100)` |
| `sortOrder` | number | no | Display order within the quote |
| `notes` | string | no | Line item notes (shown on PDF) |

#### Scenario: Add product-based line item
- GIVEN an open quote in "concept" status
- WHEN the user clicks "Regel toevoegen" and selects a product from the catalog
- THEN a QuoteLineItem MUST be created with:
  - `product`: the selected product's UUID
  - `description`: pre-populated from the product's name
  - `unitPrice`: pre-populated from the product's unitPrice
  - `quantity`: defaulted to 1
  - `discount`: defaulted to 0
- AND the line item MUST appear in the quote's line items table

#### Scenario: Add custom line item without product
- GIVEN an open quote in "concept" status
- WHEN the user clicks "Vrije regel" and enters a description and price
- THEN a QuoteLineItem MUST be created without a product reference
- AND the description and unitPrice MUST be entered manually

#### Scenario: Line item discount calculation
- GIVEN a quote line item "Implementatie OpenRegister" at EUR 15,000 with quantity 1
- WHEN the user applies a 10% discount
- THEN the line total MUST be calculated as: 1 * 15,000 * (1 - 10/100) = EUR 13,500
- AND the discount percentage MUST be shown on the line item
- AND the original unit price MUST remain visible

#### Scenario: Quote totals recalculation
- GIVEN a quote with line items:
  - "Implementatie OpenRegister" x 1 at EUR 15,000 (10% discount) = EUR 13,500
  - "Training beheerders" x 2 at EUR 2,500 = EUR 5,000
  - "Support pakket" x 12 at EUR 500 = EUR 6,000
- THEN the quote subtotal MUST be EUR 24,500
- AND BTW (21%) MUST be calculated as EUR 5,145
- AND the quote total MUST be EUR 29,645

#### Scenario: Reorder line items
- GIVEN a quote with 4 line items
- WHEN the user drags line item 3 to position 1
- THEN the sortOrder of all affected items MUST update
- AND the PDF MUST render items in the new order

#### Scenario: Editing locked for non-concept quotes
- GIVEN a quote in status "verzonden"
- WHEN the user views the quote
- THEN line items MUST be displayed in read-only mode
- AND the "Regel toevoegen" button MUST be hidden
- AND an info banner MUST explain "This quote is sent and cannot be edited. Create a new version to make changes."

---

### Requirement: Quote lifecycle management [Enterprise]
The system MUST support a quote lifecycle with clearly defined status transitions, validation rules, and side-effects on related entities.

#### Scenario: Valid status transitions
- GIVEN the quote lifecycle
- THEN the following status transitions MUST be supported:
  - concept -> verzonden (send to client)
  - verzonden -> geaccepteerd (client accepted)
  - verzonden -> afgewezen (client declined)
  - verzonden -> verlopen (past expiry date)
  - verzonden -> concept (recall to edit)
  - afgewezen -> concept (revise)
  - verlopen -> concept (revise)
- AND the following transitions MUST be rejected:
  - geaccepteerd -> any other status (accepted is final)
  - concept -> geaccepteerd (cannot accept without sending first)

#### Scenario: Send quote to client
- GIVEN a quote in status "concept" with at least one line item
- WHEN the user clicks "Verzenden"
- THEN the quote MUST transition to "verzonden"
- AND the sentDate MUST be set to the current date
- AND the expiryDate MUST be set to sentDate + 30 days (configurable default)
- AND a notification MUST be sent to the lead's assignee (if different from sender)
- AND an activity event MUST be published: "Quote {quoteNumber} sent for {leadTitle}"

#### Scenario: Cannot send empty quote
- GIVEN a quote in status "concept" with zero line items
- WHEN the user clicks "Verzenden"
- THEN the system MUST show a validation error: "Cannot send a quote without line items"
- AND the status MUST remain "concept"

#### Scenario: Accept quote updates lead
- GIVEN a quote linked to lead "Gemeente ABC deal" with total EUR 29,645 (excl. BTW: EUR 24,500)
- WHEN the quote is accepted (status -> "geaccepteerd")
- THEN the lead's value MUST be updated to the quote subtotal (EUR 24,500, excl. BTW)
- AND the lead SHOULD be moved to the next pipeline stage (configurable via admin setting)
- AND an activity event MUST be published: "Quote {quoteNumber} accepted for {leadTitle}"
- AND a notification MUST be sent to the lead's assignee

#### Scenario: Decline quote
- GIVEN a quote in status "verzonden"
- WHEN the client declines and the user clicks "Afwijzen"
- THEN the status MUST change to "afgewezen"
- AND the user SHOULD be prompted with a reason field (optional free text)
- AND an activity event MUST be published

#### Scenario: Auto-expire overdue quotes
- GIVEN a quote in status "verzonden" with expiryDate in the past
- WHEN the system runs its periodic maintenance (or the quote list is loaded)
- THEN the quote status MUST be updated to "verlopen"
- AND a notification MUST be sent to the quote's assignee: "Quote {quoteNumber} has expired"

#### Scenario: Recall quote for editing
- GIVEN a quote in status "verzonden"
- WHEN the user clicks "Terugtrekken"
- THEN the status MUST revert to "concept"
- AND the sentDate and expiryDate MUST be cleared
- AND line items MUST become editable again

---

### Requirement: PDF proposal generation [Enterprise]
The system MUST generate professional PDF proposals from quotes using Docudesk integration, with NL Design System tokens for government-branded output.

#### Scenario: Generate PDF proposal
- GIVEN a quote with line items, client details, and payment terms
- WHEN the user clicks "PDF genereren"
- THEN a PDF MUST be generated via Docudesk containing:
  - Organization header (name, logo, address from admin settings)
  - Client name and address
  - Contact person name
  - Quote number, date, and expiry date
  - Line items table: description, quantity, unit price, discount %, line total
  - Subtotal, BTW rate and amount, and grand total
  - Payment terms and conditions
  - Quote version number (if > 1)
- AND the PDF MUST be stored in Nextcloud Files under a configurable path (default: `/Pipelinq/Offertes/{year}/`)
- AND the PDF filename MUST follow the pattern: `{quoteNumber}-{clientName}.pdf`

#### Scenario: PDF uses NL Design tokens
- GIVEN the organization has NL Design System tokens configured in nldesign
- WHEN a PDF is generated
- THEN the PDF MUST apply the configured NL Design tokens for:
  - Primary color (headers, borders)
  - Font family
  - Logo placement
- AND the PDF MUST comply with WCAG AA contrast requirements for readability

#### Scenario: Customizable PDF template
- GIVEN the admin settings
- WHEN the admin configures PDF template options:
  - Organization name, address, contact details
  - Logo file (uploaded to Nextcloud Files)
  - Default payment terms text
  - Footer text
- THEN generated PDFs MUST use the configured values
- AND missing optional fields MUST be gracefully omitted (no blank placeholders)

#### Scenario: Regenerate PDF after changes
- GIVEN a quote that was recalled to "concept" and line items were edited
- WHEN the user generates a new PDF
- THEN the new PDF MUST replace the previous version in Nextcloud Files
- AND a file version MUST be preserved by Nextcloud's versioning system

#### Scenario: Download and share PDF
- GIVEN a generated PDF stored in Nextcloud Files
- WHEN the user clicks "Downloaden" or "Delen"
- THEN the standard Nextcloud share dialog MUST open for the PDF file
- AND the user MUST be able to create a public share link for the client

---

### Requirement: Quote list and overview views [Enterprise]
The system MUST provide dedicated list and detail views for managing quotes, accessible from both the main navigation and from lead detail views.

#### Scenario: Quote list view
- WHEN the user navigates to the Quotes section via main navigation
- THEN the system MUST display a table with columns: quote number, title, client, subtotal, total, status, sent date, expiry date
- AND the list MUST support filtering by status (concept, verzonden, geaccepteerd, afgewezen, verlopen)
- AND the list MUST support sorting by any column
- AND the list MUST support search by quote number and title

#### Scenario: Quote detail view
- GIVEN a quote with line items
- WHEN the user opens the quote detail
- THEN the system MUST display:
  - Quote header: number, title, status badge, client name, contact name
  - Line items table with inline editing (if status is "concept")
  - Financial summary: subtotal, BTW, total
  - Timeline of status changes
  - Action buttons appropriate for the current status
  - Link to the associated lead (if any)

#### Scenario: Multiple quotes per lead
- GIVEN a lead with 3 quotes (v1 afgewezen, v2 afgewezen, v3 geaccepteerd)
- WHEN the user views the lead detail
- THEN an "Offertes" section MUST be displayed listing all quotes
- AND each quote MUST show: quote number, version, status, total
- AND the accepted quote MUST be visually distinguished (green badge or highlight)
- AND a "Nieuwe offerte" button MUST be available to create another quote

#### Scenario: Quote navigation from lead
- GIVEN a lead detail view showing the "Offertes" section
- WHEN the user clicks on a quote entry
- THEN the user MUST be navigated to the quote detail view

#### Scenario: Dashboard integration
- GIVEN the Pipelinq dashboard
- THEN a "Quotes Awaiting Response" count widget SHOULD be available
- AND it MUST show the count of quotes in "verzonden" status that are within 7 days of expiry

---

### Requirement: Quote-to-order conversion [Enterprise]
The system MUST support converting accepted quotes into actionable records for order fulfillment or case creation.

#### Scenario: Convert accepted quote to case
- GIVEN a quote in status "geaccepteerd"
- WHEN the user clicks "Omzetten naar zaak"
- THEN the system MUST create a new request (verzoek) in Pipelinq
- AND the request MUST contain: client reference, contact reference, quote reference, and description composed from the quote title and line items
- AND the request MUST be linked to the original lead
- AND the quote detail MUST show a link to the created request

#### Scenario: Convert to Procest case
- GIVEN Procest is installed alongside Pipelinq
- WHEN the user converts an accepted quote
- THEN the system SHOULD offer the option to create a Procest case instead of a Pipelinq request
- AND the case MUST carry the quote reference and financial details

#### Scenario: Prevent duplicate conversion
- GIVEN a quote that has already been converted to a request or case
- WHEN the user attempts to convert it again
- THEN the system MUST show a warning: "This quote has already been converted"
- AND the system MUST display a link to the existing request/case
- AND the user MUST confirm before creating a duplicate

---

### Requirement: Quote permissions and audit [Enterprise]
The system MUST enforce appropriate access controls and maintain an audit trail for quotes.

#### Scenario: Quote visibility
- GIVEN a user who is not the quote's assignee
- WHEN the user views the quote list
- THEN the user MUST see all quotes (no ownership-based filtering by default)
- AND admin-configurable visibility rules SHOULD be available (all, team, own)

#### Scenario: Quote edit permissions
- GIVEN a quote in status "concept"
- WHEN any authenticated Pipelinq user attempts to edit the quote
- THEN the edit MUST be allowed (no role-based edit restrictions in V1)
- AND the system MUST record who made each change in the activity stream

#### Scenario: Audit trail for status changes
- GIVEN a quote that transitions through multiple statuses
- THEN the activity stream MUST record each transition with:
  - Timestamp
  - User who triggered the change
  - Previous and new status
  - Optional reason (for rejections)

#### Scenario: Quote deletion
- GIVEN a quote in any status
- WHEN the user clicks "Verwijderen"
- THEN a confirmation dialog MUST appear
- AND quotes in status "geaccepteerd" MUST show an additional warning: "This quote has been accepted. Deleting it will not affect the linked lead value."
- AND the delete MUST cascade to all associated QuoteLineItem objects

---

### Requirement: Quote notifications and activity [Enterprise]
The system MUST publish CRM notifications and activity events for all significant quote lifecycle events, following the same patterns as lead and request notifications.

#### Scenario: Quote sent notification
- GIVEN a quote is sent (status -> "verzonden")
- WHEN the sending user is different from the lead's assignee
- THEN the lead's assignee MUST receive a notification: "Quote {quoteNumber} sent for {leadTitle}"
- AND an activity event MUST be published visible to all CRM users

#### Scenario: Quote accepted notification
- GIVEN a quote is accepted (status -> "geaccepteerd")
- THEN the quote's assignee (and lead's assignee if different) MUST receive a notification
- AND an activity event MUST be published

#### Scenario: Quote expiry warning
- GIVEN a quote in status "verzonden" with expiryDate within 3 days
- WHEN the system checks for upcoming expirations (daily cron or on-load)
- THEN the quote's assignee MUST receive a warning notification: "Quote {quoteNumber} expires in {days} days"

#### Scenario: Activity setting for quotes
- GIVEN the user's Activity settings
- THEN a "Quotes" toggle MUST be available alongside existing Pipelinq notification categories
- AND it MUST support independent stream and email toggles
- AND it MUST be enabled for stream by default

---

### Requirement: Admin settings for quoting [Enterprise]
The system MUST provide admin-configurable settings for the quoting module.

#### Scenario: Quote default settings
- GIVEN the Pipelinq admin settings page
- THEN the following quote settings MUST be configurable:
  - Quote number prefix (default: "OFF")
  - Default expiry days (default: 30)
  - Default tax rate (default: 21%)
  - Default payment terms text
  - Organization name, address, and contact details for PDF header
  - Logo file path in Nextcloud Files
  - PDF output directory (default: `/Pipelinq/Offertes/{year}/`)
  - Whether quote acceptance auto-advances lead stage (default: false)
  - Target pipeline stage on acceptance (dropdown of available stages)

#### Scenario: Tax rate flexibility
- GIVEN different Dutch BTW rates apply to different services
- THEN the admin MUST be able to configure the default tax rate
- AND individual quote line items SHOULD support per-item tax rate overrides for mixed-rate quotes (e.g., 21% for services, 9% for goods)

#### Scenario: Multi-currency support consideration
- GIVEN the current implementation uses EUR exclusively
- THEN the spec MUST NOT hardcode EUR into the data model
- AND a `currency` field (default: "EUR") SHOULD be present on the Quote entity for future extensibility
- AND the system MAY display the currency symbol based on the quote's currency field

---

### Requirement: Quote search and integration [Enterprise]
The system MUST support searching and cross-referencing quotes across the CRM.

#### Scenario: Search by quote number
- GIVEN the global search or quote list search
- WHEN the user searches for "OFF-2026-004"
- THEN all quotes matching the partial number MUST be returned

#### Scenario: Client quote history
- GIVEN a client detail view
- THEN a "Offertes" section SHOULD display all quotes linked to this client
- AND the section MUST show: quote number, lead title, status, total

#### Scenario: Quote-product analytics
- GIVEN the ProductRevenue dashboard component
- THEN quote line items SHOULD be included in product revenue calculations alongside lead product line items
- AND the component SHOULD distinguish between "quoted" and "accepted" revenue

---

## Dependencies
- Product catalog spec (Product and ProductCategory entities)
- Lead management spec (lead entity and detail view)
- Docudesk for PDF generation
- Admin settings for organization details and payment terms
- NL Design System for PDF styling tokens
- Nextcloud Files for PDF storage
- Activity and Notification services (existing in `lib/Service/`)
- Procest (optional, for case conversion)

---

### Current Implementation Status

**Implemented:**
- Nothing from this spec is implemented. The product catalog exists (products, categories, line items), but the quoting/proposal layer does not.

**Not yet implemented:**
- **Quote entity:** No `quote` schema in `pipelinq_register.json`. No Quote CRUD.
- **Quote line items:** While `leadProduct` line items exist (with working percentage discount calculation in `LeadProducts.vue`), there is no separate quote-scoped line item concept with BTW calculation and quote-level totals.
- **Quote lifecycle:** No status transitions (concept -> verzonden -> geaccepteerd/afgewezen/verlopen).
- **Quote numbering:** No auto-generated quote numbers.
- **PDF proposal generation:** No PDF generation capability. No Docudesk integration.
- **Customizable PDF template:** No template management in admin settings.
- **Quote list and detail views:** No routes, components, or views for quotes.
- **Multiple quotes per lead:** No "Offertes" section in `LeadDetail.vue`.
- **BTW calculation:** The `taxRate` field exists on products but is not used in any totaling logic.
- **Quote acceptance updating lead value:** No integration between quote acceptance and lead value.
- **Send quote to client:** No email/notification workflow for sending quotes.
- **Expiry date management:** No auto-expiry logic.
- **Quote-to-order conversion:** No conversion workflow.

**Partial implementations:**
- The `LeadProducts.vue` component (at `src/components/LeadProducts.vue`) provides working line item functionality with percentage-based discount calculation (`calculateTotal` uses `(qty * price) * (1 - discount / 100)`) that can serve as a foundation for QuoteLineItem UI.
- The `NotificationService.php` and `ActivityService.php` already handle 5 notification types and 6 activity subjects -- quote events should follow the same pattern.
- The `ProductRevenue.vue` component aggregates product value data that could incorporate quote revenue.

### Standards & References
- **Dutch tax law:** BTW at 21% standard rate, 9% reduced rate. Quotes must show subtotal, BTW, and total separately.
- **Schema.org:** `Offer` type for quotes, `Invoice` type for accepted quotes.
- **Docudesk:** Nextcloud ExApp for PDF generation. Listed as a dependency.
- **NL Design System:** PDF styling should use design tokens for consistent government branding.
- **Krayin CRM Quote module:** Reference implementation for quote line items, billing addresses, and PDF generation.

### Specificity Assessment
- The spec is comprehensive with 10 requirements, clear lifecycle states, calculation rules, and integration points.
- **Implementable** but requires significant new infrastructure: new schema, new views, Docudesk integration, PDF generation.
- **Resolved questions from previous version:**
  - Quotes have their own route/navigation AND are accessible from lead detail views.
  - Quote number uses configurable prefix + year + sequential counter via IAppConfig.
  - EUR is the default currency with a field for future extensibility.
  - Reverting to "concept" clears sentDate/expiryDate and unlocks editing.
  - Payment terms are free text with admin-configurable defaults.

# Quotation Management Specification

## Purpose

Quotation management enables sales reps to create formal quotations (offertes) from leads, track their lifecycle, and export them as PDF documents. A quotation is a structured document listing products/services with quantities, pricing, discounts, and terms, linked to a client and optionally to a lead.

**Standards**: Schema.org (`Offer`), Industry CRM consensus (HubSpot Quotes, Salesforce Quotes)
**Primary feature tier**: V1

---

## ADDED Requirements

### Requirement: REQ-QUOT-001 Quotation Entity [V1]

The system MUST provide a Quotation entity stored as an OpenRegister object in the `pipelinq` register, using the `schema:Offer` type annotation.

| Property | Type | Required | Default | Validation |
|----------|------|----------|---------|------------|
| `referenceNumber` | string | Yes | Auto-generated | Unique, format: `{prefix}-{year}-{sequence}` |
| `title` | string | Yes | -- | Non-empty, max 255 chars |
| `description` | string | No | -- | Max 5000 chars |
| `lead` | reference (uuid) | No | -- | MUST reference a valid lead object if set |
| `client` | reference (uuid) | No | -- | MUST reference a valid client object if set |
| `contact` | reference (uuid) | No | -- | MUST reference a valid contact object if set |
| `status` | enum | Yes | draft | One of: draft, sent, accepted, rejected, expired |
| `subtotal` | number | Computed | 0 | Sum of all line item totals (before tax) |
| `taxAmount` | number | Computed | 0 | Sum of tax across all line items |
| `discount` | number | No | 0 | Overall discount percentage (0-100) |
| `grandTotal` | number | Computed | 0 | `subtotal - (subtotal * discount/100) + taxAmount` |
| `currency` | string (ISO 4217) | No | EUR | Valid ISO 4217 code |
| `validFrom` | date | No | Today | schema:validFrom |
| `validUntil` | date | No | Today + 30 days | schema:validThrough. MUST be >= validFrom |
| `terms` | string | No | -- | Free-text terms and conditions |
| `notes` | string | No | -- | Internal notes (not shown on PDF) |
| `assignedTo` | string (user UID) | No | -- | MUST reference a valid Nextcloud user UID |

#### Scenario: Create a minimal quotation

- **WHEN** a user creates a new quotation with title "Website Redesign Quote"
- **THEN** the system MUST create an OpenRegister object with `@type` set to `schema:Offer`
- AND the status MUST default to `draft`
- AND a unique referenceNumber MUST be auto-generated (e.g., `Q-2026-00001`)
- AND validFrom MUST default to today
- AND validUntil MUST default to today + the configured default validity period (default: 30 days)
- AND the audit trail MUST record the creation event

#### Scenario: Create a quotation linked to a lead

- **WHEN** a user creates a quotation from the lead detail view for lead "Gemeente ABC deal" (client: "Gemeente ABC", contact: "Petra Jansen")
- **THEN** the quotation MUST store references to the lead, client, and contact
- AND the title MUST be pre-populated with the lead title
- AND the currency MUST be copied from the lead's currency

#### Scenario: Update a quotation in draft status

- **WHEN** a user modifies any field on a draft quotation and saves
- **THEN** the system MUST update the OpenRegister object
- AND the audit trail MUST record each changed field
- AND computed fields (subtotal, taxAmount, grandTotal) MUST recalculate

#### Scenario: Delete a quotation

- **WHEN** a user deletes a quotation
- **THEN** the system MUST delete the quotation object and all associated QuotationLineItem objects
- AND the quotation MUST disappear from all views
- AND the referenceNumber MUST NOT be reused

---

### Requirement: REQ-QUOT-002 Quotation Line Item Entity [V1]

The system MUST provide a QuotationLineItem entity stored as an OpenRegister object in the `pipelinq` register, using the `schema:Offer` type annotation with `schema:itemOffered` relationship.

| Property | Type | Required | Default | Validation |
|----------|------|----------|---------|------------|
| `quotation` | reference (uuid) | Yes | -- | MUST reference a valid quotation |
| `product` | reference (uuid) | No | -- | Reference to a Product object |
| `description` | string | Yes | -- | Line item description (pre-populated from product name if linked) |
| `quantity` | number | Yes | 1 | Minimum: 0.01 |
| `unitPrice` | number | Yes | -- | Price per unit. Non-negative |
| `discount` | number | No | 0 | Discount percentage (0-100) |
| `taxRate` | number | No | 21 | Tax percentage. Pre-populated from product if linked |
| `total` | number | Computed | -- | `quantity * unitPrice * (1 - discount/100)` |
| `order` | integer | No | 0 | Display order within the quotation |

#### Scenario: Add a line item from product catalog

- **WHEN** a user clicks "Add Product" on a quotation detail and selects product "Consulting (EUR 150/hour)"
- **THEN** the system MUST create a QuotationLineItem with:
  - `description`: "Consulting"
  - `unitPrice`: 150
  - `taxRate`: from the product's taxRate
  - `quantity`: 1
  - `total`: 150
- AND the quotation's subtotal and grandTotal MUST recalculate

#### Scenario: Add a custom line item (no product link)

- **WHEN** a user clicks "Add Custom Item" and enters description "Travel expenses", quantity 1, unitPrice 250
- **THEN** the system MUST create a QuotationLineItem with `product` set to null
- AND the line item MUST function identically to product-linked items

#### Scenario: Reorder line items

- **WHEN** a user drags a line item to a new position in the list
- **THEN** the `order` property of affected items MUST update
- AND the PDF export MUST respect the display order

#### Scenario: Delete a line item

- **WHEN** a user removes a line item from a quotation
- **THEN** the QuotationLineItem object MUST be deleted
- AND the quotation's subtotal and grandTotal MUST recalculate

---

### Requirement: REQ-QUOT-003 Quotation Status Lifecycle [V1]

The system MUST enforce a quotation status lifecycle with valid transitions.

Valid transitions:
- `draft` → `sent`
- `sent` → `accepted` | `rejected`
- `sent` → `draft` (revise)
- `draft` | `sent` → `expired` (automatic when validUntil passes)
- `rejected` → `draft` (re-quote)
- `expired` → `draft` (re-quote)

#### Scenario: Send a quotation

- **WHEN** a user changes a draft quotation's status to `sent`
- **THEN** the status MUST update to `sent`
- AND the quotation MUST have at least one line item (otherwise reject with "Cannot send empty quotation")
- AND the audit trail MUST record the status change with timestamp

#### Scenario: Accept a quotation

- **WHEN** a user marks a sent quotation as `accepted`
- **THEN** the status MUST update to `accepted`
- AND if the quotation is linked to a lead, the system MUST prompt: "Move lead to Won stage?"
- AND if confirmed, the lead's stage MUST be updated to the Won stage

#### Scenario: Reject a quotation

- **WHEN** a user marks a sent quotation as `rejected`
- **THEN** the status MUST update to `rejected`
- AND the quotation MUST remain visible in the quotation list with `rejected` status
- AND the user MUST be able to create a new quotation from the same lead

#### Scenario: Auto-expire a quotation

- **WHEN** a quotation has status `sent` and `validUntil` is before the current date
- **THEN** the system MUST display the quotation as `expired`
- AND the system SHOULD update the stored status to `expired` on next access or via periodic check

#### Scenario: Revise a sent quotation

- **WHEN** a user moves a `sent` quotation back to `draft`
- **THEN** the status MUST update to `draft`
- AND the quotation MUST become fully editable again
- AND the audit trail MUST record the revision

#### Scenario: Invalid status transition

- **WHEN** a user attempts to change an `accepted` quotation to `sent`
- **THEN** the system MUST reject the transition with error "Cannot change status of an accepted quotation"

---

### Requirement: REQ-QUOT-004 Quotation List View [V1]

The system MUST provide a list view for browsing quotations with search, sort, and filter capabilities.

#### Scenario: Display quotation list

- **WHEN** the user navigates to the Quotations section
- **THEN** the system MUST display a table with columns: Reference Number, Title, Client, Status, Grand Total, Valid Until, Assigned To
- AND each row MUST be clickable to navigate to the quotation detail view
- AND the list MUST support pagination (default page size: 25)

#### Scenario: Search quotations

- **WHEN** the user types "gemeente" in the search box
- **THEN** the results MUST filter quotations by title and client name containing the search term
- AND the search MUST be case-insensitive

#### Scenario: Filter by status

- **WHEN** the user filters by status "sent"
- **THEN** only quotations with status `sent` MUST be shown
- AND the user MUST be able to select multiple statuses

#### Scenario: Sort by grand total

- **WHEN** the user sorts by grand total descending
- **THEN** quotations MUST appear ordered by grandTotal from highest to lowest

#### Scenario: Filter by date range

- **WHEN** the user sets a date filter for validUntil between 2026-03-01 and 2026-03-31
- **THEN** only quotations valid within that range MUST be shown

---

### Requirement: REQ-QUOT-005 Quotation Detail View [V1]

The system MUST provide a detail view for viewing and editing a single quotation.

#### Scenario: Display quotation detail

- **WHEN** the user opens a quotation detail
- **THEN** the system MUST display:
  - Header: reference number, title, status badge
  - Client section: client name (linked), contact name, contact email
  - Line items table: Description, Quantity, Unit Price, Discount, Tax Rate, Total per row
  - Summary: Subtotal, Overall Discount, Tax Amount, Grand Total
  - Validity: validFrom, validUntil (with warning if expired)
  - Terms and conditions
  - Internal notes (labeled "Internal - not shown on quotation")
  - Assigned to (with reassign action)
  - Linked lead (clickable link to lead detail)

#### Scenario: Edit quotation in draft

- **WHEN** the user edits fields on a `draft` quotation
- **THEN** all fields MUST be editable
- AND changes MUST save on blur or explicit save action

#### Scenario: View accepted quotation (read-only)

- **WHEN** the user opens an `accepted` quotation
- **THEN** all fields MUST be read-only
- AND a notice MUST display: "This quotation has been accepted and cannot be edited"
- AND the only available action MUST be "Export PDF"

#### Scenario: Expired quotation warning

- **WHEN** the user opens a quotation where validUntil is in the past
- **THEN** a warning banner MUST display: "This quotation has expired"
- AND the status badge MUST show "Expired"

---

### Requirement: REQ-QUOT-006 Create Quotation from Lead [V1]

The system MUST support creating a quotation directly from a lead, pre-populating data from the lead and its line items.

#### Scenario: Create quotation from lead with products

- **WHEN** the user clicks "Create Quotation" on a lead detail view that has 3 LeadProduct line items
- **THEN** the system MUST create a new quotation with:
  - `lead`: reference to the current lead
  - `client`: copied from lead's client reference
  - `contact`: copied from lead's contact reference
  - `title`: lead title
  - `currency`: lead's currency
- AND the system MUST create 3 QuotationLineItems copied from the LeadProduct items (description from product name, quantity, unitPrice, discount, taxRate from product)
- AND the quotation MUST open in detail view in `draft` status

#### Scenario: Create quotation from lead without products

- **WHEN** the user clicks "Create Quotation" on a lead with no LeadProduct line items
- **THEN** the system MUST create a quotation with lead, client, contact pre-populated
- AND the line items list MUST be empty
- AND the user MUST be able to add line items manually

#### Scenario: Multiple quotations per lead

- **WHEN** a lead already has 2 existing quotations
- **THEN** the user MUST still be able to create additional quotations from the same lead
- AND each quotation MUST get a unique referenceNumber

---

### Requirement: REQ-QUOT-007 Quotation PDF Export [V1]

The system MUST support exporting quotations as PDF documents.

#### Scenario: Export quotation as PDF

- **WHEN** the user clicks "Export PDF" on a quotation detail
- **THEN** the system MUST generate a PDF containing:
  - Company details (from admin settings: name, address, logo, KVK, BTW numbers)
  - Quotation reference number and date
  - Client name and address
  - Contact person name and email
  - Line items table: Description, Quantity, Unit Price, Discount, Tax, Total
  - Summary: Subtotal, Overall Discount, Tax Total, Grand Total
  - Validity period (validFrom — validUntil)
  - Terms and conditions
- AND the PDF MUST be downloaded to the user's browser
- AND the PDF MUST NOT include internal notes

#### Scenario: PDF with company logo

- **WHEN** the admin has configured a company logo in Pipelinq settings
- **THEN** the PDF header MUST display the logo
- AND if no logo is configured, the header MUST show the company name in text

#### Scenario: PDF export of empty quotation

- **WHEN** the user tries to export a quotation with no line items
- **THEN** the system MUST display a warning: "This quotation has no line items"
- AND the user MUST be able to proceed with export (empty line items table is valid)

---

### Requirement: REQ-QUOT-008 Quotation Numbering [V1]

The system MUST auto-generate unique, sequential reference numbers for quotations.

#### Scenario: Auto-generate reference number on creation

- **WHEN** a new quotation is created and the current year is 2026 with 41 existing quotations
- **THEN** the reference number MUST be `Q-2026-00042` (using default prefix "Q")
- AND the number MUST be unique across all quotations

#### Scenario: Year rollover

- **WHEN** the first quotation of 2027 is created
- **THEN** the reference number MUST be `Q-2027-00001`
- AND the sequence MUST reset to 1 for the new year

#### Scenario: Custom prefix

- **WHEN** the admin configures the prefix to "OFF" (for "offerte")
- **THEN** new quotations MUST use the format `OFF-2026-00043`
- AND existing quotations MUST retain their original reference numbers

---

### Requirement: REQ-QUOT-009 Quotation Admin Settings [V1]

The system MUST provide admin settings for configuring quotation defaults.

#### Scenario: Configure company details

- **WHEN** an admin navigates to Pipelinq settings under "Quotation Settings"
- **THEN** the admin MUST be able to configure:
  - Company name
  - Company address
  - Company logo (file upload)
  - KVK number (Chamber of Commerce)
  - BTW number (VAT)
  - Bank account (IBAN)

#### Scenario: Configure quotation defaults

- **WHEN** an admin configures quotation defaults
- **THEN** the following MUST be configurable:
  - Reference number prefix (default: "Q")
  - Default validity period in days (default: 30)
  - Default terms and conditions text
  - Default tax rate (default: 21%)

#### Scenario: Settings persisted via IAppConfig

- **WHEN** an admin saves quotation settings
- **THEN** the settings MUST be stored via Nextcloud's IAppConfig under the `pipelinq` app namespace
- AND the settings MUST be immediately effective for new quotations

---

### Requirement: REQ-QUOT-010 Quotation Totals Calculation [V1]

The system MUST compute quotation totals from line items.

#### Scenario: Calculate subtotal

- **WHEN** a quotation has line items with totals: 1500, 2000, 750
- **THEN** the subtotal MUST be 4250

#### Scenario: Calculate with overall discount

- **WHEN** a quotation has subtotal 4250 and overall discount 10%
- **THEN** the discounted subtotal MUST be 3825 (4250 * 0.90)

#### Scenario: Calculate tax amount

- **WHEN** a quotation has line items with different tax rates:
  - Item 1: total 1500, taxRate 21% → tax 315
  - Item 2: total 2000, taxRate 21% → tax 420
  - Item 3: total 750, taxRate 9% → tax 67.50
- **THEN** the overall discount MUST apply before tax calculation
- AND the tax MUST be calculated per line item on the discounted amount
- AND the total tax amount MUST be displayed grouped by tax rate on the PDF

#### Scenario: Grand total calculation

- **WHEN** subtotal is 4250, discount is 10%, and total tax is 802.50
- **THEN** the grandTotal MUST be 3825 + 802.50 = 4627.50

---

### Requirement: REQ-QUOT-011 Quotation Error Handling [V1]

The system MUST handle error conditions gracefully.

#### Scenario: Create quotation when OpenRegister is unavailable

- **WHEN** the OpenRegister API is unreachable
- **THEN** the system MUST display: "Could not save quotation. Please try again later."
- AND form data MUST be preserved

#### Scenario: Export PDF for deleted quotation

- **WHEN** a quotation is deleted while another user has the detail view open
- **THEN** the system MUST display: "This quotation no longer exists"
- AND the PDF export button MUST be disabled

#### Scenario: Reference number collision

- **WHEN** two quotations are created simultaneously and would receive the same number
- **THEN** the system MUST handle the conflict (retry with next number)
- AND both quotations MUST receive unique reference numbers

## MODIFIED Requirements

_(none)_

## REMOVED Requirements

_(none)_

# Lead-Product Link Specification

## Status: implemented

## Purpose

The lead-product link enables sales reps to attach specific products (with quantities and pricing) to leads, replacing or supplementing manual value entry. This creates an accurate, auditable breakdown of what a lead is worth based on actual product line items. It follows the standard CRM pattern where Products are the master catalog and Line Items are deal-specific instances.

**Feature tier**: V1

**Competitor context:** Krayin CRM has a `lead_products` pivot table with quantity, price, and amount. EspoCRM links products to Opportunities via `OpportunityItem` entities with quantity, unit price, discount, and tax. Twenty CRM does not have native product-deal linking. This spec matches the industry standard while adding product-based scoring and recommendation features unique to Pipelinq's government CRM positioning.

---

## Requirements

### Requirement: LeadProduct Entity [V1]

The system MUST provide a LeadProduct entity (line item) stored as an OpenRegister object in the `pipelinq` register, using the `schema:Offer` type annotation. Each LeadProduct links one Product to one Lead with deal-specific pricing.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `lead` | string (uuid) | YES | UUID reference to the parent Lead |
| `product` | string (uuid) | YES | UUID reference to the Product |
| `quantity` | number | YES | Number of units. Default: 1. Minimum: 0.01 |
| `unitPrice` | number | YES | Price per unit (pre-populated from Product.unitPrice, can be overridden) |
| `discount` | number | no | Discount percentage (0-100). Default: 0 |
| `total` | number | computed | `quantity * unitPrice * (1 - discount/100)` -- computed on save |
| `notes` | string | no | Line item notes (e.g., "annual license", "setup fee") |

#### Scenario: Add a product to a lead
- GIVEN a lead detail view and at least one active product exists
- WHEN the user clicks "Add Product" and selects a product
- THEN the system MUST create a LeadProduct object with:
  - `lead`: the current lead's UUID
  - `product`: the selected product's UUID
  - `unitPrice`: pre-populated from the product's unitPrice
  - `quantity`: defaulted to 1
  - `discount`: defaulted to 0
  - `total`: calculated as quantity * unitPrice
- AND the line item MUST appear in the lead's product list

#### Scenario: Override unit price on line item
- GIVEN a LeadProduct line item
- WHEN the user changes the unitPrice field
- THEN the new price MUST be saved on the line item
- AND the total MUST be recalculated using the percentage formula
- AND the original product's unitPrice MUST NOT be affected

#### Scenario: Apply percentage-based discount to line item
- GIVEN a LeadProduct line item with quantity 5, unitPrice 100
- WHEN the user sets discount to 10 (percent)
- THEN the total MUST be calculated as: 5 * 100 * (1 - 10/100) = 450
- AND the total MUST be displayed on the line item
- AND the discount field MUST show "10%" to indicate it is a percentage

#### Scenario: Remove a product from a lead
- GIVEN a lead with one or more LeadProduct line items
- WHEN the user clicks the remove button on a line item
- THEN a confirmation dialog MUST appear: "Remove this product from the lead?"
- AND upon confirmation the LeadProduct object MUST be deleted from the register
- AND the lead's calculated value MUST update to reflect the removal

#### Scenario: Edit line item quantity
- GIVEN a LeadProduct with quantity 2 and unitPrice 500
- WHEN the user changes quantity to 5
- THEN the total MUST recalculate to 2,500
- AND the lead's total value MUST update accordingly

---

### Requirement: Lead Value Auto-Calculation [V1]

The system MUST auto-calculate a lead's value from its line items, while preserving the ability to manually override the value.

#### Scenario: Lead value from line items
- GIVEN a lead with LeadProduct line items totaling EUR 5,000
- WHEN the user views the lead detail
- THEN the lead's displayed value MUST show EUR 5,000
- AND the value MUST reflect the sum of all line item totals

#### Scenario: Lead value with no line items
- GIVEN a lead with no LeadProduct line items
- WHEN the user views the lead detail
- THEN the lead's value MUST remain as the manually entered value
- AND the user MUST be able to edit the value directly

#### Scenario: Lead value manual override
- GIVEN a lead with line items totaling EUR 5,000
- WHEN the user manually sets the lead value to EUR 6,000
- THEN the system MUST store the override value
- AND the system MUST display a hint showing the calculated vs manual value
- AND the user MUST be able to click "Use calculated value" to reset to the line item total

#### Scenario: Lead value update on line item change
- GIVEN a lead with line items and no manual override
- WHEN a line item is added, modified, or removed
- THEN the lead's value MUST automatically recalculate as the sum of all line item totals
- AND the updated value MUST be reflected in the lead list and pipeline board

#### Scenario: Auto-update only for zero or unset lead values
- GIVEN a lead with value EUR 0 or null
- WHEN the first line item is added with total EUR 3,000
- THEN the lead's value MUST automatically update to EUR 3,000
- AND for subsequent line item changes, the value MUST continue auto-updating

---

### Requirement: Lead Product List Display [V1]

The system MUST display a product line items section in the lead detail view with inline editing capabilities.

#### Scenario: Line items table in lead detail
- GIVEN a lead with LeadProduct line items
- WHEN the user views the lead detail
- THEN a "Products" section MUST be displayed showing a table with columns: Product Name, Qty, Unit Price, Discount, Total
- AND below the table, a summary row MUST show the grand total of all line items
- AND each row MUST have inline editing for quantity, unit price, and discount
- AND each row MUST have a remove action button

#### Scenario: Add product search
- WHEN the user clicks "Add Product" in the lead detail
- THEN a dialog MUST appear with a searchable product dropdown
- AND the user MUST be able to search by product name
- AND selecting a product MUST pre-populate the unit price
- AND the dialog MUST include fields for quantity, unit price, discount, and notes

#### Scenario: Empty line items
- GIVEN a lead with no line items
- WHEN the user views the lead detail
- THEN the "Products" section MUST show an empty state: "No products added to this lead yet."
- AND an "Add Product" button MUST be visible

#### Scenario: Notes field display
- GIVEN a line item with a notes value (e.g., "annual license")
- WHEN the user views the line items table
- THEN the notes MUST be displayed inline or accessible via a tooltip/expand
- AND the notes MUST be editable inline or via the add/edit dialog

#### Scenario: Currency formatting
- GIVEN line items with numeric values
- WHEN the values are displayed
- THEN all monetary values MUST be formatted as "EUR X.XXX,XX" using nl-NL locale
- AND the grand total MUST use the same formatting

---

### Requirement: Pipeline Board Product Value [V1]

The system MUST display product-based lead values on the pipeline kanban board and include them in stage totals.

#### Scenario: Lead card value display
- GIVEN a lead on the pipeline board with line items totaling EUR 3,500
- WHEN the user views the kanban card
- THEN the card MUST display the lead value (EUR 3,500)
- AND the value source (manual or calculated from products) does not need a visual indicator

#### Scenario: Stage column totals with product values
- GIVEN multiple leads in a pipeline stage, some with line items and some with manual values
- WHEN the pipeline board renders stage column headers
- THEN the column total MUST be the sum of all lead values in that stage (regardless of source)
- AND the total MUST be formatted consistently with the pipeline's totalsLabel setting

#### Scenario: List view value column
- GIVEN the pipeline is in list view mode
- WHEN leads with product-based values are displayed
- THEN the Value column MUST show each lead's value
- AND leads without a value MUST show an em-dash

---

### Requirement: Product Interest Tracking [V1]

The system MUST track which products appear most frequently across leads to support sales intelligence.

#### Scenario: Product popularity on dashboard
- GIVEN the ProductRevenue dashboard component exists
- WHEN the dashboard is loaded
- THEN the "Top Products by Pipeline Value" widget MUST aggregate all LeadProduct line items
- AND it MUST show the top products ranked by total value across all leads
- AND each entry MUST show: product name, number of leads, and total value

#### Scenario: Product frequency in leads
- GIVEN 10 leads exist, 7 of which have "OpenRegister Implementatie" as a line item
- WHEN the user views the product detail for "OpenRegister Implementatie"
- THEN a "Linked Leads" section SHOULD display the count and list of leads using this product

#### Scenario: Product-based lead identification
- GIVEN a new product "AI Integratie" is added to the catalog
- WHEN the product is linked to its first lead
- THEN the system MUST make it available for quick-add on other leads via the product search
- AND the product search SHOULD sort recently-used products higher in the dropdown

---

### Requirement: Product-Based Lead Scoring [Enterprise]

The system MUST support lead scoring adjustments based on product interest patterns.

#### Scenario: High-value product interest
- GIVEN a lead with product line items totaling more than EUR 10,000
- WHEN the lead is displayed on the pipeline board or list
- THEN the lead SHOULD be visually distinguished (e.g., with a value tier indicator)
- AND the system SHOULD surface high-value leads in the "My Work" and dashboard views

#### Scenario: Product category scoring
- GIVEN product categories exist (e.g., "Implementatie", "Training", "Support")
- WHEN a lead has products from the "Implementatie" category
- THEN the lead MAY receive a higher priority score
- AND the scoring rules SHOULD be configurable via admin settings

#### Scenario: Cross-sell recommendation
- GIVEN a lead has "OpenRegister Implementatie" as a line item
- WHEN the user views the lead's "Add Product" dialog
- THEN the system SHOULD suggest complementary products (e.g., "Training beheerders", "Support pakket")
- AND the recommendations SHOULD be based on products frequently purchased together across other leads

---

### Requirement: Bulk Product Operations [V1]

The system MUST support efficient product management across multiple leads.

#### Scenario: Copy products from another lead
- GIVEN a lead with no line items
- WHEN the user clicks "Copy from lead" in the Products section
- THEN a dialog MUST appear allowing selection of an existing lead
- AND all line items from the selected lead MUST be copied (with quantities and prices)
- AND the copied items MUST be new objects (not references to originals)

#### Scenario: Add product to multiple leads
- GIVEN a product "Support pakket" at EUR 500/month
- WHEN the user views the product detail and clicks "Add to leads"
- THEN the user SHOULD be able to select multiple leads
- AND a LeadProduct MUST be created for each selected lead with default quantity and price

#### Scenario: Bulk price update
- GIVEN a product's catalog price changes from EUR 500 to EUR 600
- WHEN the admin updates the product price
- THEN existing LeadProduct line items MUST NOT be affected (they store their own unitPrice)
- AND a notification SHOULD inform the admin: "X active leads reference this product at the old price"

---

### Requirement: Product Line Item Validation [V1]

The system MUST validate line item data to prevent invalid entries.

#### Scenario: Negative quantity prevention
- GIVEN the user edits a line item quantity field
- WHEN the user enters a value less than 0.01
- THEN the system MUST reject the value and show a validation error
- AND the minimum allowed value MUST be 0.01

#### Scenario: Discount range validation
- GIVEN the user edits the discount percentage field
- WHEN the user enters a value greater than 100 or less than 0
- THEN the system MUST reject the value
- AND the valid range MUST be 0 to 100

#### Scenario: Missing product validation
- GIVEN the Add Product dialog
- WHEN the user attempts to add a line item without selecting a product
- THEN the "Add" button MUST remain disabled
- AND the product field MUST be marked as required

#### Scenario: Duplicate product warning
- GIVEN a lead already has a line item for "OpenRegister Implementatie"
- WHEN the user attempts to add the same product again
- THEN the system SHOULD show a warning: "This product is already on this lead"
- AND the user MUST be able to proceed (duplicates are allowed but discouraged)

---

### Requirement: Product Data Integrity [V1]

The system MUST handle edge cases around product deletion and data consistency.

#### Scenario: Deleted product display
- GIVEN a LeadProduct references a product UUID that has been deleted
- WHEN the user views the lead's line items
- THEN the product name column MUST show the product UUID or a fallback label "[Deleted product]"
- AND the line item MUST still be editable and removable

#### Scenario: Product price drift tracking
- GIVEN a LeadProduct was created with unitPrice EUR 500 from the catalog
- WHEN the catalog product's price later changes to EUR 600
- THEN the line item MUST retain the original EUR 500 price
- AND the system SHOULD NOT retroactively change existing line items

#### Scenario: Lead deletion cascading
- GIVEN a lead with 5 LeadProduct line items
- WHEN the lead is deleted
- THEN all associated LeadProduct objects MUST also be deleted from OpenRegister
- AND orphaned line items MUST NOT remain in the register

---

## MODIFIED Requirements

_(none)_

## REMOVED Requirements

_(none)_

---

### Current Implementation Status

**Implemented:**
- LeadProduct entity is defined in `lib/Settings/pipelinq_register.json` as a `leadProduct` schema within the `pipelinq` register.
- Frontend store registration for `leadProduct` is implemented in `src/store/store.js`.
- Full `LeadProducts.vue` component exists at `src/components/LeadProducts.vue` with:
  - Product line items table with columns: Product, Qty, Unit Price, Discount, Total.
  - Add Product dialog with product search dropdown, quantity, unit price, discount, notes fields.
  - Inline editing of quantity, unitPrice, and discount with live recalculation.
  - Remove line item functionality with confirmation dialog.
  - Grand total footer row.
  - Manual override detection with "Use calculated value" button to sync lead value from products.
  - Currency formatting (EUR, nl-NL locale).
  - **Percentage-based discount calculation**: `calculateTotal` correctly uses `(qty * price) * (1 - discount / 100)`.
- CRUD operations use the shared `useObjectStore` from `src/store/modules/object.js`.
- Product pre-population: `onProductSelect()` copies `unitPrice` from the selected product to the line item form.
- `LeadDetail.vue` integrates `LeadProducts` with `@value-changed` and `@sync-value` event handlers.
- `ProductRevenue.vue` dashboard component aggregates LeadProduct data showing top products by pipeline value.

**Not yet implemented:**
- Lead value auto-recalculation on the pipeline board and lead list views -- the `LeadProducts` component emits events but auto-update only fires when lead value is 0 or null (partial).
- SKU search in the Add Product dialog -- only product name matching via NcSelect label.
- The `notes` field on LeadProduct line items is saved but not displayed in the table or editable inline.
- Product interest tracking (linked leads section on product detail).
- Product-based lead scoring.
- Cross-sell recommendations.
- Copy products from another lead.
- Bulk product operations.
- Duplicate product warning.
- Lead deletion cascade for line items.
- Deleted product fallback display (shows UUID, not a friendly label).

**Partial implementations:**
- The manual override hint wording matches the implementation: "Lead value is manually set to {manual}. Calculated total: {calculated}."
- Discount calculation is correctly implemented as percentage (previously noted as a bug, now fixed).

### Standards & References
- Schema.org `Offer` type annotation for LeadProduct entity.
- Schema.org `Product` type for products (referenced entity).
- OpenRegister object storage pattern -- no custom database tables.
- Krayin CRM `lead_products` table: quantity, price, amount -- comparable pattern.
- EspoCRM `OpportunityItem`: product, quantity, unitPrice, discount, listPrice -- more complex model.

### Specificity Assessment
- The spec is comprehensive with 10 requirements covering the complete lifecycle of line items, value calculation, pipeline integration, scoring, and data integrity.
- **Well-implemented core:** LeadProduct entity, CRUD, and inline editing are fully functional.
- **Key gaps:** Notes display, auto-value recalculation, bulk operations, and product interest tracking.
- **Resolved ambiguity:** Discount is confirmed as percentage (0-100), matching the current implementation.
- **Open question:** Should currency be configurable per lead or is EUR sufficient? Current spec assumes EUR.

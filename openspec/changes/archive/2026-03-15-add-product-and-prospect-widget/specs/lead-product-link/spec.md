# Lead-Product Link Specification

## Purpose

The lead-product link enables sales reps to attach specific products (with quantities and pricing) to leads, replacing or supplementing manual value entry. This creates an accurate, auditable breakdown of what a lead is worth based on actual product line items. It follows the standard CRM pattern where Products are the master catalog and Line Items are deal-specific instances.

**Feature tier**: V1

---

## ADDED Requirements

### Requirement: LeadProduct Entity

The system MUST provide a LeadProduct entity (line item) stored as an OpenRegister object in the `pipelinq` register, using the `schema:Offer` type annotation. Each LeadProduct links one Product to one Lead with deal-specific pricing.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `lead` | string (uuid) | YES | UUID reference to the parent Lead |
| `product` | string (uuid) | YES | UUID reference to the Product |
| `quantity` | number | YES | Number of units. Default: 1. Minimum: 0.01 |
| `unitPrice` | number | YES | Price per unit (pre-populated from Product.unitPrice, can be overridden) |
| `discount` | number | no | Discount percentage (0-100). Default: 0 |
| `total` | number | computed | `quantity * unitPrice * (1 - discount/100)` — computed on save |
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
- AND the total MUST be recalculated
- AND the original product's unitPrice MUST NOT be affected

#### Scenario: Apply discount to line item
- GIVEN a LeadProduct line item with quantity 5, unitPrice 100
- WHEN the user sets discount to 10 (percent)
- THEN the total MUST be calculated as: 5 * 100 * (1 - 10/100) = 450
- AND the total MUST be displayed on the line item

#### Scenario: Remove a product from a lead
- GIVEN a lead with one or more LeadProduct line items
- WHEN the user removes a line item
- THEN the LeadProduct object MUST be deleted from the register
- AND the lead's calculated value MUST update to reflect the removal

#### Scenario: Edit line item quantity
- GIVEN a LeadProduct with quantity 2 and unitPrice 500
- WHEN the user changes quantity to 5
- THEN the total MUST recalculate to 2500
- AND the lead's total value MUST update accordingly

---

### Requirement: Lead Value Auto-Calculation

The system MUST auto-calculate a lead's value from its line items, while preserving the ability to manually override the value.

#### Scenario: Lead value from line items
- GIVEN a lead with LeadProduct line items totaling EUR 5,000
- WHEN the user views the lead detail
- THEN the lead's displayed value MUST show EUR 5,000
- AND the value MUST be labeled as "Calculated from products"

#### Scenario: Lead value with no line items
- GIVEN a lead with no LeadProduct line items
- WHEN the user views the lead detail
- THEN the lead's value MUST remain as the manually entered value
- AND the user MUST be able to edit the value directly

#### Scenario: Lead value manual override
- GIVEN a lead with line items totaling EUR 5,000
- WHEN the user manually sets the lead value to EUR 6,000
- THEN the system MUST store the override value
- AND the system MUST display "Manual override (products total: EUR 5,000)" as a hint
- AND the user MUST be able to click "Recalculate from products" to reset to the line item total

#### Scenario: Lead value update on line item change
- GIVEN a lead with line items and no manual override
- WHEN a line item is added, modified, or removed
- THEN the lead's value MUST automatically recalculate as the sum of all line item totals
- AND the updated value MUST be reflected in the lead list and pipeline board

---

### Requirement: Lead Product List Display

The system MUST display a product line items section in the lead detail view.

#### Scenario: Line items table in lead detail
- GIVEN a lead with LeadProduct line items
- WHEN the user views the lead detail
- THEN a "Products" section MUST be displayed showing a table with columns: Product Name, Quantity, Unit Price, Discount, Total
- AND below the table, a summary row MUST show the total sum of all line items
- AND each row MUST have edit (inline) and delete actions

#### Scenario: Add product search
- WHEN the user clicks "Add Product" in the lead detail
- THEN a search/dropdown MUST appear listing active products
- AND the user MUST be able to search by product name or SKU
- AND selecting a product MUST add a new line item with default values

#### Scenario: Empty line items
- GIVEN a lead with no line items
- WHEN the user views the lead detail
- THEN the "Products" section MUST show an empty state: "No products added yet"
- AND an "Add Product" button MUST be visible

---

### Requirement: Pipeline Board Product Value

The system MUST display product-based lead values on the pipeline kanban board.

#### Scenario: Lead card value display
- GIVEN a lead on the pipeline board with line items totaling EUR 3,500
- WHEN the user views the kanban card
- THEN the card MUST display the lead value (EUR 3,500)
- AND if the value is auto-calculated from products, no special indicator is needed (value is value)

#### Scenario: Stage column totals with product values
- GIVEN multiple leads in a pipeline stage, some with line items and some with manual values
- WHEN the pipeline board renders stage column headers
- THEN the column total MUST be the sum of all lead values in that stage (regardless of whether they come from line items or manual entry)

---

## MODIFIED Requirements

_(none)_

## REMOVED Requirements

_(none)_

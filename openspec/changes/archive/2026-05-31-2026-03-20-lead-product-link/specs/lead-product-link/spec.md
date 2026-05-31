# Lead-Product Link — Delta Spec

## Purpose

Fix three functional gaps in the `LeadProducts` component: SKU-based product search, inline notes display and editing on line items, and correct auto-recalculation of lead value whenever line items change.

**Main spec ref**: [archive/2026-03-15-add-product-and-prospect-widget/specs/lead-product-link/spec.md](../../../archive/2026-03-15-add-product-and-prospect-widget/specs/lead-product-link/spec.md)
**Feature tier**: V1 (bug fix / gap completion)

---

## Requirements

### REQ-LPL-001: SKU-based product search in Add Product dialog

The system MUST allow sales reps to search for products by SKU in addition to product name when adding a product to a lead.

#### Scenario: Search by SKU finds the correct product

- GIVEN the "Add Product" dialog is open on a lead detail view
- AND a product exists with name "Licentie CRM basis" and SKU "LIC-CRM-B"
- WHEN the user types "LIC-CRM-B" in the product search field
- THEN the product "Licentie CRM basis (LIC-CRM-B)" MUST appear in the dropdown results
- AND selecting it MUST add a LeadProduct line item for that product

#### Scenario: Search by name still works after the change

- GIVEN the "Add Product" dialog is open
- AND a product exists with name "Advies per uur" and SKU "ADV-001"
- WHEN the user types "advies" in the product search field
- THEN "Advies per uur (ADV-001)" MUST appear in the dropdown results

#### Scenario: Product without SKU displays name only

- GIVEN a product exists with name "Consultancy" and no SKU value
- WHEN the "Add Product" dropdown is rendered
- THEN the option label MUST display as "Consultancy" without parentheses or empty SKU

---

### REQ-LPL-002: Notes column visible and inline-editable in line items table

The system MUST display the `notes` field of each `leadProduct` line item in the line items table, and allow the user to edit it inline without leaving the lead detail view.

#### Scenario: Existing notes are visible in the table

- GIVEN a lead with a LeadProduct line item that has `notes` = "Initiële analyse en roadmap"
- WHEN the user views the lead detail view
- THEN the "Notes" column MUST be visible in the line items table
- AND the cell for that line item MUST display "Initiële analyse en roadmap"

#### Scenario: Notes can be edited inline and saved on change

- GIVEN a lead with a LeadProduct line item where notes is empty
- WHEN the user clicks the Notes cell and types "Eerste jaar introductiekorting"
- AND moves focus away from the field (blur)
- THEN the updated notes MUST be saved to the `leadProduct` object via `objectStore.saveObject('leadProduct', item)`
- AND the Notes cell MUST display "Eerste jaar introductiekorting" after saving

#### Scenario: Empty notes field shows a placeholder

- GIVEN a LeadProduct line item with no notes value
- WHEN the user views the line items table
- THEN the Notes cell MUST show a placeholder text (e.g. "Notities...")
- AND the cell MUST be editable

#### Scenario: Notes are preserved when other line item fields are changed

- GIVEN a LeadProduct line item with notes "Jaarcontract support"
- WHEN the user changes the quantity of that line item
- THEN the notes value MUST remain "Jaarcontract support" after the save

---

### REQ-LPL-003: Lead value auto-recalculates on every line item change unless manually overridden

The system MUST automatically update the lead's `value` field to equal the sum of all line item totals whenever line items change, unless the user has manually set a different value.

#### Scenario: Lead value updates when a line item is added

- GIVEN a lead with no line items and value EUR 0
- AND no manual override is active
- WHEN the user adds a LeadProduct with quantity 1 and unitPrice 499
- THEN the lead's value MUST be updated to 499.00
- AND the updated value MUST be saved to the lead object

#### Scenario: Lead value updates when a line item quantity is changed

- GIVEN a lead with one line item (quantity 1, unitPrice 145, total 145) and value EUR 145
- AND no manual override is active
- WHEN the user changes the line item quantity to 40
- THEN the lead's value MUST update to 5800.00

#### Scenario: Lead value updates when a line item is removed

- GIVEN a lead with two line items totaling EUR 6849.10 and no manual override
- WHEN the user removes one line item with total EUR 449.10
- THEN the lead's value MUST update to EUR 6400.00

#### Scenario: Manual override prevents auto-recalculation

- GIVEN a lead whose current `value` differs from the computed product total (indicating a manual override)
- WHEN the user adds, edits, or removes a line item
- THEN the lead's `value` MUST NOT be automatically changed
- AND the lead MUST retain the manually entered value

#### Scenario: Override is detected correctly on mount

- GIVEN a lead with `value` = 10000 and line items that sum to 8992.10
- WHEN the lead detail view is opened
- THEN the system MUST detect that a manual override is active (values differ)
- AND subsequent line item changes MUST NOT overwrite the 10000 value

#### Scenario: No override when lead value matches product total

- GIVEN a lead with `value` = 8992.10 and line items that sum to 8992.10 (within EUR 0.01 tolerance)
- WHEN the lead detail view is opened
- THEN the system MUST NOT treat this as a manual override
- AND subsequent line item changes MUST trigger auto-recalculation

#### Scenario: Lead value with value null or 0 and no line items is not treated as an override

- GIVEN a lead with `value` = 0 and no line items
- WHEN the user adds a LeadProduct with total EUR 2500
- THEN the lead's value MUST update to EUR 2500
- AND this MUST NOT be blocked by override detection

---

## MODIFIED Requirements

### REQ-LPL-003 modifies: Lead Value Auto-Calculation (archived spec)

The original spec required auto-calculation "when value is 0 or null". This requirement expands that to "always recalculate unless a manual override is active", with override detection based on value divergence at mount time.

## REMOVED Requirements

_(none)_

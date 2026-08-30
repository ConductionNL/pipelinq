<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: pipeline (Pipeline)
     This spec extends the existing `pipeline` capability. Do NOT define new entities or build new CRUD — reuse what `pipeline` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Delta Spec: lead-product-link

## Changes to specs/lead-product-link/spec.md

This delta addresses the V1 gaps identified in the current implementation status of `specs/lead-product-link/spec.md`. The base spec requirements remain in force; this document adds requirements covering the unimplemented items and the new Pipeline Breakdowns by Sales Stage feature (demand: 22).

---

## NEW Requirements

### REQ-LPL-010: SKU Search in Add Product Dialog

The system MUST support searching for products by SKU in addition to product name when adding a line item to a lead.

#### Scenario: SKU visible in product dropdown
- GIVEN a sales representative opens the "Add Product" dialog on a lead detail view
- WHEN the product dropdown options are rendered
- THEN each option MUST display the product name with the SKU in parentheses: `"Product Name (SKU-001)"`
- AND options for products without a SKU MUST display the product name only

#### Scenario: Search by SKU matches product
- GIVEN a product exists with name "Support Pakket Basis" and SKU "SUP-003"
- WHEN the user types "SUP" in the product search field
- THEN the product MUST appear in the dropdown results
- AND the result label MUST show "Support Pakket Basis (SUP-003)"

#### Scenario: Search by name still works
- GIVEN a product exists with name "Training Beheerders" and SKU "TRN-002"
- WHEN the user types "Training" in the product search field
- THEN the product MUST appear in the dropdown results (name matching is preserved)

---

### REQ-LPL-011: Notes Column Display and Inline Editing

The system MUST display the `notes` field of each LeadProduct line item in the product table and allow inline editing without opening a separate dialog.

#### Scenario: Notes visible in line items table
- GIVEN a LeadProduct line item with notes value "Inclusief datamigrate vanuit legacy systeem"
- WHEN the user views the lead's product line items table
- THEN a "Notes" column MUST be present in the table
- AND the notes value MUST be displayed inline in that column

#### Scenario: Notes editable inline
- GIVEN a LeadProduct line item is displayed in the table
- WHEN the user clicks on the notes cell and enters text
- THEN the input MUST be editable in-place
- AND when the user leaves the field (blur), the updated notes MUST be saved to the LeadProduct object
- AND save failure MUST display a user-facing error message

#### Scenario: Empty notes shows placeholder
- GIVEN a LeadProduct line item has no notes value
- WHEN the user views the table
- THEN the notes column MUST show an empty or placeholder state (em-dash or empty input)
- AND the user MUST be able to click the field and enter a notes value

---

### REQ-LPL-012: Full Lead Value Auto-Recalculation

The system MUST auto-update the lead's value field whenever line items change, unless the user has established a manual override.

#### Scenario: Value updates on line item add (non-zero existing value)
- GIVEN a lead has a value of EUR 5,000 set from previous line items and no manual override is active
- WHEN the user adds a new line item with a total of EUR 2,000
- THEN the lead's value MUST automatically update to EUR 7,000
- AND the update MUST happen without requiring any additional user action

#### Scenario: Value updates on line item edit
- GIVEN a lead has line items totaling EUR 5,000 and no manual override
- WHEN the user changes a line item quantity such that the new total is EUR 4,500
- THEN the lead's value MUST automatically update to EUR 4,500

#### Scenario: Value updates on line item remove
- GIVEN a lead has two line items totaling EUR 8,000 and no manual override
- WHEN the user removes one line item worth EUR 3,000
- THEN the lead's value MUST automatically update to EUR 5,000

#### Scenario: Manual override prevents auto-update
- GIVEN a lead has line items totaling EUR 5,000
- WHEN the user manually sets the lead value to EUR 6,000 (different from product total)
- THEN a `valueIsOverridden` flag MUST be set in the component state
- AND subsequent line item changes MUST NOT overwrite the manually set EUR 6,000 value
- AND the system MUST display a hint: "Lead value is manually set. Calculated total: EUR X"

#### Scenario: Reset override restores auto-recalculation
- GIVEN a lead with a manual override active (value differs from product total)
- WHEN the user clicks "Use calculated value"
- THEN the lead value MUST be updated to the current product total
- AND the `valueIsOverridden` flag MUST be cleared
- AND subsequent line item changes MUST resume auto-updating the lead value

---

### REQ-LPL-013: Pipeline Stage Product-Value Breakdown

The system MUST provide a product-value breakdown per pipeline stage on the kanban board, showing which products drive the value in each stage.

#### Scenario: Stage column total is visible
- GIVEN one or more leads with LeadProduct line items exist in a pipeline stage
- WHEN the user views the pipeline kanban board
- THEN each stage column header MUST display the aggregate value of all leads in that stage
- AND the aggregate MUST reflect lead values (whether from line items or manual entry)

#### Scenario: Stage breakdown shows top products
- GIVEN stage "Propositie" contains 3 leads, each with "OpenRegister Implementatie (ORI-001)" at EUR 12,500
- WHEN the user clicks or hovers on the "Propositie" stage column total
- THEN a breakdown panel MUST appear listing the top products by aggregate value in that stage
- AND the entry for "OpenRegister Implementatie" MUST show: product name, occurrence count (3×), and aggregate total (EUR 37,500)
- AND the breakdown MUST be sorted descending by aggregate value

#### Scenario: Stage breakdown limited to top 5 products
- GIVEN a pipeline stage contains leads with more than 5 distinct products
- WHEN the user opens the stage breakdown panel
- THEN at most 5 product entries MUST be shown
- AND a label MUST indicate if additional products are not shown: "and X more"

#### Scenario: Stage with no line items shows only total
- GIVEN a pipeline stage contains leads with manually set values but no LeadProduct line items
- WHEN the user opens the stage breakdown
- THEN the breakdown panel MUST indicate that no product breakdown is available for this stage
- AND the stage column total MUST still display the sum of manual lead values

---

### REQ-LPL-014: Product Interest Tracking (Linked Leads)

The system MUST display a "Linked Leads" section on the product detail view showing which leads reference that product, to support sales intelligence and product popularity tracking.

#### Scenario: Linked leads section on product detail
- GIVEN a product exists that is referenced by one or more LeadProduct line items
- WHEN the user views the product detail page
- THEN a "Linked Leads" section MUST be displayed using a `CnDetailCard`
- AND the section header MUST show the count of linked leads in parentheses: "Linked Leads (4)"
- AND each linked lead MUST be shown with: lead title, current pipeline stage, line item quantity, and line item total for this product

#### Scenario: Linked leads sorted by date
- GIVEN a product has 4 linked leads created on different dates
- WHEN the user views the "Linked Leads" section
- THEN the leads MUST be sorted by creation date descending (most recent first)

#### Scenario: Product with no linked leads
- GIVEN a product exists with no LeadProduct line items referencing it
- WHEN the user views the product detail
- THEN the "Linked Leads" section MUST show an empty state: "No leads are using this product yet."
- AND the section header MUST show "Linked Leads (0)"

#### Scenario: Linked leads navigable
- GIVEN the "Linked Leads" section lists a lead
- WHEN the user clicks on a lead title in the list
- THEN the application MUST navigate to that lead's detail view

---

## MODIFIED Requirements

### REQ-LPL-003 (modified): Lead Value Auto-Calculation — remove 0/null guard

The original requirement states that auto-update fires "when lead value is 0 or null." This restriction is **removed**. The updated behaviour is defined in REQ-LPL-012 above: auto-update fires on every line item change unless a manual override is active.

---

## REMOVED Requirements

_(none)_

---

## Stakeholders

Inferred from features and demand signals:

| Stakeholder | Role | Goal |
|---|---|---|
| Sales Representative | Creates and manages leads | Add products to leads, see accurate deal value, search by SKU |
| Sales Manager | Reviews pipeline health | See stage breakdowns by product, forecast by product mix |
| CRM Administrator | Manages product catalog | See which products are used in which leads |

## User Stories

Inferred from features (no linked stories in context-brief):

1. As a **sales representative**, I want to search for products by SKU when adding them to a lead, so I can find the right product quickly when I know the catalog code.
2. As a **sales representative**, I want to see and edit the notes on each product line item inline, so I can annotate deal-specific terms without leaving the lead view.
3. As a **sales representative**, I want the lead value to automatically recalculate every time I add or change a product line item, so the pipeline always reflects the true deal value.
4. As a **sales manager**, I want to see a breakdown of which products are in each pipeline stage, so I can understand my product mix per stage and forecast more accurately.
5. As a **CRM administrator**, I want to see which leads are using a given product, so I can assess product demand and identify cross-sell opportunities.

## Customer Journeys

### Journey: Sales Rep Builds a Quote on a Lead

**Trigger**: Sales rep opens a lead after a prospect meeting where specific products were discussed.

**Steps**:
1. Rep opens lead detail, navigates to the "Products" section.
2. Clicks "Add Product" and searches by SKU "ORI-001" — finds "OpenRegister Implementatie" immediately.
3. Sets quantity 1, accepts pre-populated price, adds a note "Inclusief datamigrate".
4. Clicks "Add Product" again, searches "TRN" — sees "Training Beheerders (TRN-002)".
5. Sets quantity 2, applies 10% discount.
6. Lead value auto-updates to EUR 15,830 (12,500 + 3,330) without any manual action.
7. Rep confirms lead value reflects the proposal total.

**Pain point addressed**: Previously, the rep had to manually update the lead value after adding products. Recalculation only fired for zero-value leads, creating silent discrepancies.

### Journey: Sales Manager Reviews Stage Performance

**Trigger**: Weekly pipeline review — manager checks which product categories are advancing through the funnel.

**Steps**:
1. Manager opens the pipeline board for "Sales Pipeline".
2. Clicks on the "Propositie" stage column total (EUR 45,830).
3. Breakdown panel appears: "OpenRegister Implementatie — 3× — EUR 37,500", "Training Beheerders — 2× — EUR 3,330", ...
4. Manager sees that Implementatie deals dominate the Propositie stage and can plan resourcing accordingly.

**Pain point addressed**: Without stage product breakdowns, managers had to manually sum product values across leads. The pipeline board showed totals but no composition.

# Lead-Product Link Specification

## Problem
The lead-product link enables sales reps to attach specific products (with quantities and pricing) to leads, replacing or supplementing manual value entry. This creates an accurate, auditable breakdown of what a lead is worth based on actual product line items. It follows the standard CRM pattern where Products are the master catalog and Line Items are deal-specific instances.
**Feature tier**: V1
**Competitor context:** Krayin CRM has a `lead_products` pivot table with quantity, price, and amount. EspoCRM links products to Opportunities via `OpportunityItem` entities with quantity, unit price, discount, and tax. Twenty CRM does not have native product-deal linking. This spec matches the industry standard while adding product-based scoring and recommendation features unique to Pipelinq's government CRM positioning.
---

## Proposed Solution
Implement Lead-Product Link Specification following the detailed specification. Key requirements include:
- Requirement: LeadProduct Entity [V1]
- Requirement: Lead Value Auto-Calculation [V1]
- Requirement: Lead Product List Display [V1]
- Requirement: Pipeline Board Product Value [V1]
- Requirement: Product Interest Tracking [V1]

## Scope
This change covers all requirements defined in the lead-product-link specification.

## Success Criteria
- Add a product to a lead
- Override unit price on line item
- Apply percentage-based discount to line item
- Remove a product from a lead
- Edit line item quantity

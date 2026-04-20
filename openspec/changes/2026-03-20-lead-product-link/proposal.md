# Proposal: lead-product-link

## Problem

The LeadProducts component is partially implemented but has gaps:
1. SKU search is not available in the Add Product dialog (only name matching)
2. Notes field is saved but not displayed or editable inline in the product table
3. Lead value auto-recalculation only fires when value is 0/null, not automatically when line items change (per spec)

## Solution

Fix the remaining gaps:
1. Add SKU to product option labels and filter matching
2. Add inline notes display and edit in the line items table
3. Implement proper auto-recalculation: auto-update lead value when line items change unless a manual override exists

## Scope

- SKU search in product dropdown
- Notes column in line items table with inline edit
- Proper auto-recalculation logic per spec

## Out of scope

- Currency configuration (assumes EUR)
- Multi-currency support

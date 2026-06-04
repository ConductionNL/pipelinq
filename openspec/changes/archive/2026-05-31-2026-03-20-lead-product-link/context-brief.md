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



## Design

# Design: lead-product-link

## Changes

### LeadProducts.vue

1. **SKU search**: Update `productOptions` computed to include SKU in label: `"Product Name (SKU-001)"`. The NcSelect filter will then match on both name and SKU.

2. **Notes column**: Add a "Notes" column to the line items table. Display `item.notes` inline, with an editable input that saves on change.

3. **Auto-recalculation**: Add a `_valueOverride` flag. When the user explicitly sets a lead value different from the product total, track it. When line items change and no override exists, auto-sync the lead value.

### LeadDetail.vue

Update `onProductValueChanged` to always auto-update unless a manual override flag is set. The override is detected by comparing current lead value to previous product total.

## Files Changed

- `src/components/LeadProducts.vue` (modified)
- `src/views/leads/LeadDetail.vue` (modified)



## Tasks

# Tasks: lead-product-link

## 1. SKU Search

- [ ] 1.1 Update `productOptions` in LeadProducts.vue to include SKU in option label for searchability.

## 2. Notes Column

- [ ] 2.1 Add "Notes" column to line items table in LeadProducts.vue.
- [ ] 2.2 Make notes editable inline with save-on-change.

## 3. Auto-Recalculation

- [ ] 3.1 Update `onProductValueChanged` in LeadDetail.vue to auto-sync lead value when line items change (no manual override).
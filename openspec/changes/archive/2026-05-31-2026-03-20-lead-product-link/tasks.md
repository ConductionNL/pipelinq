# Tasks: lead-product-link

## 1. SKU Search in Add Product Dialog

- [x] 1.1 Update `productOptions` computed in `LeadProducts.vue` to include SKU in option label
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-001`
  - **files**: `pipelinq/src/components/LeadProducts.vue`
  - **acceptance_criteria**:
    - GIVEN the Add Product dialog is open
    - WHEN the user types a SKU code (e.g. "LIC-CRM-B")
    - THEN the matching product MUST appear in the results
    - AND products without a SKU MUST display as "Product Name" without parentheses
    - AND searching by name MUST still work as before

## 2. Notes Column in Line Items Table

- [x] 2.1 Add "Notes" column header and cell to the line items table in `LeadProducts.vue`
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-002`
  - **files**: `pipelinq/src/components/LeadProducts.vue`
  - **acceptance_criteria**:
    - GIVEN a lead with LeadProduct line items
    - THEN a "Notes" column MUST be visible in the table
    - AND each row MUST display the `item.notes` value (or placeholder if empty)

- [x] 2.2 Make notes field inline-editable with save-on-change
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-002`
  - **files**: `pipelinq/src/components/LeadProducts.vue`
  - **acceptance_criteria**:
    - GIVEN a Notes cell in the line items table
    - WHEN the user edits the notes text and moves focus away
    - THEN the updated `notes` value MUST be saved via `objectStore.saveObject('leadProduct', item)`
    - AND the saved value MUST remain visible in the cell after save
    - AND editing notes MUST NOT affect other line item fields (quantity, unitPrice, discount)

## 3. Auto-Recalculation of Lead Value

- [x] 3.1 Add `_valueOverride` ref and compute override state on mount in `LeadDetail.vue`
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-003`
  - **files**: `pipelinq/src/views/leads/LeadDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a lead is loaded where `lead.value` differs from the sum of line item totals by more than EUR 0.01
    - THEN `_valueOverride` MUST be `true`
    - AND given a lead where `lead.value` matches the computed total (within EUR 0.01)
    - THEN `_valueOverride` MUST be `false`
    - AND given a lead with `value` null or 0 and no line items
    - THEN `_valueOverride` MUST be `false`

- [x] 3.2 Replace null/zero guard in `onProductValueChanged` with `_valueOverride` check
  - **spec_ref**: `specs/lead-product-link/spec.md#REQ-LPL-003`
  - **files**: `pipelinq/src/views/leads/LeadDetail.vue`
  - **acceptance_criteria**:
    - GIVEN `_valueOverride` is `false`
    - WHEN a line item is added, edited, or removed
    - THEN `lead.value` MUST be updated to the new computed product total
    - AND the updated value MUST be saved to the lead object
    - AND given `_valueOverride` is `true`
    - WHEN a line item changes
    - THEN `lead.value` MUST NOT be modified

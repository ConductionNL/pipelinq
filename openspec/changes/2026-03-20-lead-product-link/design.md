# Design: lead-product-link

status: pr-created

## Architecture Overview

All three gaps are purely frontend issues. The `lead` and `leadProduct` schemas in OpenRegister already define all required fields (`lead.value`, `leadProduct.notes`, `product.sku`). No new PHP controllers, register schemas, or API endpoints are required. All changes are confined to two Vue components.

## Architecture Decisions

### 1. SKU in option label uses NcSelect's built-in filter

**Decision**: Modify the `productOptions` computed property to produce labels in the format `"Product Name (SKU-001)"`. For products without a SKU, fall back to `"Product Name"` only.

**Rationale**: NcSelect filters its options by comparing the user's search text against the option `label` string. Including the SKU in the label means no custom `filter` function is needed — the existing filter already handles it. This is the minimal-change approach with no risk of breaking existing name search.

### 2. Notes column uses the existing inline edit pattern

**Decision**: Add a `notes` column to the line items `<table>` in `LeadProducts.vue`. Render notes as an `<NcTextField>` (or plain `<input>`) in-cell. Save on `@change` (blur + value changed) by calling the existing `updateLeadProduct(item)` method.

**Rationale**: Quantity, unit price, and discount are already edited inline using the same pattern. Reusing this pattern keeps the component internally consistent and avoids introducing a modal/popover for a single text field.

### 3. `_valueOverride` flag lives in component state, not in the register

**Decision**: Store the override flag as a reactive local variable (`_valueOverride: false`) in `LeadDetail.vue`. It is computed on mount by comparing `lead.value` to the sum of existing line item totals. It is set to `true` when the user manually edits the lead value field. It is never persisted to OpenRegister.

**Rationale**: The override is a UI-session concept. Persisting it would require a schema change and would create ambiguity across sessions. Computing it from the value comparison on mount correctly handles leads that were manually overridden in a previous session.

### 4. `onProductValueChanged` guard replaced

**Decision**: In `LeadDetail.vue`, replace the guard `if (lead.value === 0 || lead.value === null)` with `if (!_valueOverride)`. This makes auto-recalculation unconditional unless an override is active.

**Rationale**: The original guard was an approximation: a lead with no line items and a manually entered value of `0` would be incorrectly overwritten. The `_valueOverride` flag is the correct discriminator.

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `src/components/LeadProducts.vue` | MODIFY | SKU in `productOptions` label; add Notes column to table; inline notes edit with `updateLeadProduct`; emit `_valueOverride` signal when user edits lead value |
| `src/views/leads/LeadDetail.vue` | MODIFY | Add `_valueOverride` ref; compute override on mount; replace null/zero guard in `onProductValueChanged` with `!_valueOverride`; set `_valueOverride = true` on manual lead value edit |

## Component Design

### LeadProducts.vue — productOptions computed

```js
// Before
productOptions = products.map(p => ({ label: p.name, value: p.id }))

// After
productOptions = products.map(p => ({
  label: p.sku ? `${p.name} (${p.sku})` : p.name,
  value: p.id,
}))
```

### LeadProducts.vue — Notes column

Add a `<th>Notes</th>` column header and a `<td>` cell per row:

```html
<td>
  <NcTextField
    :value="item.notes"
    placeholder="Notities..."
    @change="item.notes = $event; updateLeadProduct(item)"
  />
</td>
```

### LeadDetail.vue — override detection

```js
// On mount, after fetching lead and computing initial product total:
const computedTotal = leadProducts.reduce((sum, lp) => sum + lp.total, 0)
_valueOverride = lead.value !== null
  && lead.value !== 0
  && Math.abs(lead.value - computedTotal) > 0.001

// In onProductValueChanged:
if (!_valueOverride) {
  lead.value = newComputedTotal
  saveLead()
}

// When user edits lead value manually:
_valueOverride = true
```

## Seed Data

### product examples

| name | sku | unitPrice | type | unit | taxRate |
|------|-----|-----------|------|------|---------|
| Advies per uur | ADV-001 | 145.00 | service | hour | 21 |
| Licentie CRM basis | LIC-CRM-B | 499.00 | product | license | 21 |
| Implementatiepakket standaard | IMP-STD | 2500.00 | service | each | 21 |
| Support abonnement maandelijks | SUP-M-12 | 89.00 | service | month | 21 |
| Hardware beveiligingstoken | HW-TOK-01 | 35.00 | product | each | 21 |

### leadProduct line item examples (for lead "Dienstverlening Gemeente Almere")

| product (sku) | quantity | unitPrice | discount | total | notes |
|---------------|----------|-----------|----------|-------|-------|
| ADV-001 | 40 | 145.00 | 0 | 5800.00 | Initiële analyse en roadmap |
| LIC-CRM-B | 1 | 499.00 | 10 | 449.10 | Eerste jaar introductiekorting |
| IMP-STD | 1 | 2500.00 | 0 | 2500.00 | Inclusief migratie bestaande data |
| SUP-M-12 | 12 | 89.00 | 0 | 1068.00 | Jaarcontract support |
| HW-TOK-01 | 5 | 35.00 | 0 | 175.00 | Tokens voor sleutelbeheerders |

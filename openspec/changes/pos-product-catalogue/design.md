# Design: pos-product-catalogue

## Architecture

### Data Layer — Product Schema Extension

All additions are new optional properties on the existing `product` schema in `lib/Settings/pipelinq_register.json`. No new top-level schemas are introduced; sub-objects for variants, modifiers, and tiers are embedded arrays within `product`.

#### New properties on `product`

| Property | JSON type | Required | Facetable | Description |
|---|---|---|---|---|
| `barcode` | string | No | No | EAN-13 / UPC-A barcode (schema:gtin). Used for scanner lookup. |
| `btwClass` | string enum | No | Yes | Dutch BTW class: `hoog` (21%), `laag` (9%), `nul` (0%), `vrijgesteld` (exempt). Governs receipt printing and invoice BTW labels. |
| `duration` | integer | No | No | Service duration in minutes. Visible only when `type=service`. |
| `variants` | array | No | No | Variant matrix. Each entry is an object with `sku`, `name`, `attributes` (key → value map), `unitPrice`, `barcode`, and `status`. |
| `modifierGroups` | array | No | No | Modifier groups for POS checkout. Each group has `name`, `required` (bool), `multiSelect` (bool), `min`, `max`, and `options` (array of `{name, priceAdjustment}`). |
| `priceTiers` | array | No | No | Quantity-break pricing. Each tier has `minQuantity` (int), `unitPrice` (number), and `label` (string). Tiers MUST be sorted ascending by `minQuantity`. |

##### Variant sub-object shape

```json
{
  "sku": "TSH-S-WIT",
  "name": "Small Wit",
  "attributes": { "maat": "S", "kleur": "Wit" },
  "unitPrice": 19.95,
  "barcode": "8712345678901",
  "status": "active"
}
```

##### Modifier group sub-object shape

```json
{
  "name": "Melksoort",
  "required": false,
  "multiSelect": false,
  "min": 0,
  "max": 1,
  "options": [
    { "name": "Standaard melk", "priceAdjustment": 0 },
    { "name": "Havermelk", "priceAdjustment": 0.60 },
    { "name": "Amandel", "priceAdjustment": 0.60 }
  ]
}
```

##### Price tier sub-object shape

```json
{ "minQuantity": 1, "unitPrice": 4.99, "label": "Losse verpakking" }
```

#### BTW class → taxRate auto-mapping

When `btwClass` is set, `taxRate` is synced automatically by the frontend:

| btwClass | taxRate |
|---|---|
| `hoog` | 21 |
| `laag` | 9 |
| `nul` | 0 |
| `vrijgesteld` | 0 |

### Backend

No new PHP controllers or services are needed. The product schema changes are registered via `pipelinq_register.json` (imported through the existing `importFromApp()` pipeline in the repair step). OpenRegister's `ObjectService` handles all CRUD via the existing REST API.

No migration is needed for existing `product` objects — all new properties are optional.

### Frontend

#### New Components

**`ProductVariantPanel.vue`** (`src/components/products/ProductVariantPanel.vue`)

Inline panel rendered within `ProductDetail.vue` when the product has at least one variant or when the user clicks "Varianten beheren". Displays a table: columns are attribute values (e.g. S/M/L across top), rows are the other attribute (e.g. Wit/Zwart/Rood). Each cell shows the variant's SKU and price. Cells link to an inline edit popover. The "Nieuw attribuut" button appends a column or row to the matrix.

**`ModifierGroupPanel.vue`** (`src/components/products/ModifierGroupPanel.vue`)

Inline panel for managing modifier groups. Renders a card per group listing its options with price adjustments. "Groep toevoegen" appends a new group. Options are editable inline (name + priceAdjustment). `required` and `multiSelect` are toggled via checkboxes. Min/max fields control selection cardinality.

**`PriceTierTable.vue`** (`src/components/products/PriceTierTable.vue`)

Inline editable table: rows are tiers ordered by `minQuantity`. Columns: Vanaf (minQuantity), Prijs per eenheid (unitPrice), Label. Rows are added with the "Staffel toevoegen" button and removed per row. Table auto-sorts by `minQuantity` on save.

#### Modified Views

**`ProductDetail.vue`** (`src/views/products/ProductDetail.vue`)

Adds three new `CnDetailCard` sections below the core product fields:
- **Varianten** — renders `ProductVariantPanel` when `product.variants` is non-empty or when the panel is expanded.
- **Modificatiegroepen** — renders `ModifierGroupPanel`.
- **Prijsstaffels** — renders `PriceTierTable`.

**`ProductForm.vue`** / **`ProductDetail.vue` edit mode**

New fields added to the product form:
- `barcode` — text input with label "Barcode (EAN/UPC)".
- `btwClass` — `NcSelect` dropdown with options Hoog (21%), Laag (9%), Nul (0%), Vrijgesteld. Selecting a class auto-fills `taxRate`.
- `duration` — integer input "Duur (minuten)", visible only when `type === 'service'`.

**`ProductList.vue`** / search

A `BarcodeInput.vue` sub-component (camera button that opens a barcode scanner or accepts keyboard HID input) is added to the search bar. Scanning triggers a search by `barcode` field. Result navigates directly to the matching product detail.

#### Navigation

No changes to `MainMenu.vue` — products are already accessible via the existing Producten navigation item.

#### Store

No new Pinia store. The existing `createObjectStore('product')` handles CRUD for the extended schema. `fieldsFromSchema()` automatically picks up the new properties in forms.

## Reuse Analysis

| OpenRegister / CnVue capability | Used for |
|---|---|
| `ObjectService.saveObject()` | Saving product with all new fields |
| `CnFormDialog` / `fieldsFromSchema()` | Auto-generates barcode + btwClass + duration fields in create/edit form |
| `CnDetailPage` + `CnDetailCard` | Houses the three new inline panels in product detail |
| `createObjectStore('product')` | Pinia state for product CRUD with relations plugin |
| `CnIndexPage` + `useListView` | Product list with barcode search filter |
| `CnObjectSidebar` | Audit trail, files, notes (unchanged) |

No custom controllers, mappers, or backend endpoints are needed. The platform handles persistence and API automatically.

## Files Changed

### Modified Files

| File | Change |
|---|---|
| `lib/Settings/pipelinq_register.json` | Add `barcode`, `btwClass`, `duration`, `variants`, `modifierGroups`, `priceTiers` properties to `product` schema; add 5 seed objects |
| `src/views/products/ProductDetail.vue` | Add Varianten, Modificatiegroepen, Prijsstaffels `CnDetailCard` sections |
| `src/views/products/ProductForm.vue` | Add barcode, btwClass, duration fields; auto-sync taxRate from btwClass |
| `src/views/products/ProductList.vue` | Add barcode scan input to search bar |

### New Files

| File | Purpose |
|---|---|
| `src/components/products/ProductVariantPanel.vue` | Inline variant matrix management |
| `src/components/products/ModifierGroupPanel.vue` | Inline modifier group management |
| `src/components/products/PriceTierTable.vue` | Inline price tier table |
| `src/components/products/BarcodeInput.vue` | Barcode scanner input (camera / HID) |
| `specs/pos-product-catalogue/spec.md` | Formal requirements and BDD scenarios |

## Seed Data

Five realistic Dutch `product` objects demonstrating all new fields. Added to `components.objects[]` in `pipelinq_register.json` with the `@self` envelope.

### 1. Cappuccino (drank met modificatiegroep)

```json
{
  "@self": { "register": "pipelinq", "schema": "product", "slug": "product-cappuccino" },
  "name": "Cappuccino",
  "description": "Verse espresso met opgeklopte melk",
  "sku": "DRK-001",
  "barcode": "8714100838623",
  "unitPrice": 3.50,
  "type": "product",
  "status": "active",
  "unit": "stuk",
  "taxRate": 9,
  "btwClass": "laag",
  "modifierGroups": [
    {
      "name": "Melksoort",
      "required": false,
      "multiSelect": false,
      "min": 0,
      "max": 1,
      "options": [
        { "name": "Volle melk", "priceAdjustment": 0 },
        { "name": "Halfvolle melk", "priceAdjustment": 0 },
        { "name": "Havermelk", "priceAdjustment": 0.60 },
        { "name": "Amandelmelk", "priceAdjustment": 0.60 }
      ]
    },
    {
      "name": "Grootte",
      "required": true,
      "multiSelect": false,
      "min": 1,
      "max": 1,
      "options": [
        { "name": "Klein (150ml)", "priceAdjustment": 0 },
        { "name": "Groot (250ml)", "priceAdjustment": 0.80 }
      ]
    }
  ]
}
```

### 2. T-shirt Conduction (kledingproduct met variantenmatrix)

```json
{
  "@self": { "register": "pipelinq", "schema": "product", "slug": "product-tshirt-conduction" },
  "name": "T-shirt Conduction",
  "description": "Unisex katoenen T-shirt met Conduction logo",
  "sku": "KLD-TSH-001",
  "barcode": "8712345600001",
  "unitPrice": 19.95,
  "type": "product",
  "status": "active",
  "unit": "stuk",
  "taxRate": 21,
  "btwClass": "hoog",
  "variants": [
    { "sku": "TSH-S-WIT", "name": "S — Wit", "attributes": { "maat": "S", "kleur": "Wit" }, "unitPrice": 19.95, "barcode": "8712345600002", "status": "active" },
    { "sku": "TSH-M-WIT", "name": "M — Wit", "attributes": { "maat": "M", "kleur": "Wit" }, "unitPrice": 19.95, "barcode": "8712345600003", "status": "active" },
    { "sku": "TSH-L-WIT", "name": "L — Wit", "attributes": { "maat": "L", "kleur": "Wit" }, "unitPrice": 19.95, "barcode": "8712345600004", "status": "active" },
    { "sku": "TSH-S-ZWA", "name": "S — Zwart", "attributes": { "maat": "S", "kleur": "Zwart" }, "unitPrice": 19.95, "barcode": "8712345600005", "status": "active" },
    { "sku": "TSH-M-ZWA", "name": "M — Zwart", "attributes": { "maat": "M", "kleur": "Zwart" }, "unitPrice": 19.95, "barcode": "8712345600006", "status": "active" },
    { "sku": "TSH-L-ZWA", "name": "L — Zwart", "attributes": { "maat": "L", "kleur": "Zwart" }, "unitPrice": 21.95, "barcode": "8712345600007", "status": "active" }
  ]
}
```

### 3. A4 Papier 500 vel (kantoorproduct met prijsstaffels)

```json
{
  "@self": { "register": "pipelinq", "schema": "product", "slug": "product-a4-papier-500vel" },
  "name": "A4 Papier 500 vel",
  "description": "Wit kopieerpapier 80 g/m², pak van 500 vel",
  "sku": "KAN-PAP-001",
  "barcode": "8711764155064",
  "unitPrice": 5.49,
  "type": "product",
  "status": "active",
  "unit": "pak",
  "taxRate": 21,
  "btwClass": "hoog",
  "priceTiers": [
    { "minQuantity": 1, "unitPrice": 5.49, "label": "Losse verpakking" },
    { "minQuantity": 5, "unitPrice": 4.75, "label": "Doos (5 pakken)" },
    { "minQuantity": 10, "unitPrice": 4.25, "label": "Pallet (10+ pakken)" }
  ]
}
```

### 4. Knippen en Föhnen (behandeling met duur)

```json
{
  "@self": { "register": "pipelinq", "schema": "product", "slug": "product-knippen-fohnen" },
  "name": "Knippen en Föhnen",
  "description": "Wassen, knippen en föhnen bij de kapper",
  "sku": "SRV-KAP-001",
  "unitPrice": 42.50,
  "type": "service",
  "status": "active",
  "unit": "behandeling",
  "taxRate": 21,
  "btwClass": "hoog",
  "duration": 60,
  "modifierGroups": [
    {
      "name": "Extra behandeling",
      "required": false,
      "multiSelect": true,
      "min": 0,
      "max": 3,
      "options": [
        { "name": "Kleur bijwerken", "priceAdjustment": 25.00 },
        { "name": "Voedingsmasker", "priceAdjustment": 12.50 },
        { "name": "Hoofdmassage", "priceAdjustment": 8.00 }
      ]
    }
  ]
}
```

### 5. Adviesgesprek (dienst vrijgesteld van BTW)

```json
{
  "@self": { "register": "pipelinq", "schema": "product", "slug": "product-adviesgesprek" },
  "name": "Adviesgesprek",
  "description": "Persoonlijk adviesgesprek met een gecertificeerd consultant",
  "sku": "SRV-ADV-001",
  "unitPrice": 125.00,
  "type": "service",
  "status": "active",
  "unit": "uur",
  "taxRate": 0,
  "btwClass": "vrijgesteld",
  "duration": 60,
  "priceTiers": [
    { "minQuantity": 1, "unitPrice": 125.00, "label": "Enkel gesprek" },
    { "minQuantity": 4, "unitPrice": 110.00, "label": "Pakket 4 gesprekken" },
    { "minQuantity": 10, "unitPrice": 95.00, "label": "Jaarprogramma (10+)" }
  ]
}
```

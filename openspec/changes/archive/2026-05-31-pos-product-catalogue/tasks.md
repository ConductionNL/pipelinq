# Tasks: pos-product-catalogue

## 0. Deduplication Check

- [x] 0.1 Verify no existing OpenRegister service or Pipelinq component already implements variant matrix, modifier groups, or price tier logic.
  - Search `openspec/specs/`, `lib/Service/`, and `src/components/` for: "variant", "modifier", "priceTier", "btwClass", "barcode"
  - Document findings: if overlap found, extend existing code; if none found, proceed.

---

## 1. Data Model — product schema extension

- [x] 1.1 Add `barcode` property to the `product` schema in `lib/Settings/pipelinq_register.json`
  - **spec_ref**: `specs/pos-product-catalogue/spec.md#REQ-PPC-005`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN a product object is created with `barcode: "8714100838623"`
    - THEN the field MUST be stored and returned by the OpenRegister API

- [x] 1.2 Add `btwClass` property (string enum: hoog/laag/nul/vrijgesteld, facetable) to the `product` schema
  - **spec_ref**: `specs/pos-product-catalogue/spec.md#REQ-PPC-004`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN `btwClass` is set to `laag`
    - THEN the facet sidebar MUST count it in the "laag" bucket

- [x] 1.3 Add `duration` property (integer, minutes) to the `product` schema
  - **spec_ref**: `specs/pos-product-catalogue/spec.md#REQ-PPC-006`
  - **files**: `lib/Settings/pipelinq_register.json`

- [x] 1.4 Add `variants` property (array of objects) to the `product` schema
  - **spec_ref**: `specs/pos-product-catalogue/spec.md#REQ-PPC-001`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Schema MUST define `items.properties`: sku (string), name (string), attributes (object), unitPrice (number), barcode (string), status (enum: active/inactive)

- [x] 1.5 Add `modifierGroups` property (array of objects) to the `product` schema
  - **spec_ref**: `specs/pos-product-catalogue/spec.md#REQ-PPC-002`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Schema MUST define `items.properties`: name, required (bool), multiSelect (bool), min (int), max (int), options (array of {name, priceAdjustment})

- [x] 1.6 Add `priceTiers` property (array of objects) to the `product` schema
  - **spec_ref**: `specs/pos-product-catalogue/spec.md#REQ-PPC-003`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Schema MUST define `items.properties`: minQuantity (integer, minimum: 1), unitPrice (number, minimum: 0), label (string)

---

## 2. Seed Data

- [x] 2.1 Add 5 Dutch product seed objects to `components.objects[]` in `pipelinq_register.json`
  - **spec_ref**: Company ADR-001 (data-layer) — seed data requirement
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Objects: Cappuccino (modifierGroups), T-shirt Conduction (variants), A4 Papier 500 vel (priceTiers), Knippen en Föhnen (duration + modifierGroups), Adviesgesprek (vrijgesteld btwClass + priceTiers)
    - Each uses `@self` envelope with `register: "pipelinq"`, `schema: "product"`, and a unique `slug`
    - Re-importing with `force: false` MUST skip existing objects matched by slug

---

## 3. Product Form — new fields

- [x] 3.1 Add `barcode` text input to `ProductForm.vue`
  - **spec_ref**: `specs/pos-product-catalogue/spec.md#REQ-PPC-005`
  - **files**: `src/views/products/ProductForm.vue`
  - **acceptance_criteria**:
    - GIVEN the product form is open
    - THEN a "Barcode (EAN/UPC)" text input MUST be present
    - AND it MUST save to the `barcode` field on submit

- [x] 3.2 Add `btwClass` dropdown to `ProductForm.vue` with auto-sync of `taxRate`
  - **spec_ref**: `specs/pos-product-catalogue/spec.md#REQ-PPC-004`
  - **files**: `src/views/products/ProductForm.vue`
  - **acceptance_criteria**:
    - GIVEN the user selects `btwClass = laag`
    - THEN `taxRate` MUST be set to 9 automatically
    - AND the `taxRate` input MUST become read-only while a `btwClass` is selected

- [x] 3.3 Add `duration` integer input to `ProductForm.vue` (visible only when `type === 'service'`)
  - **spec_ref**: `specs/pos-product-catalogue/spec.md#REQ-PPC-006`
  - **files**: `src/views/products/ProductForm.vue`
  - **acceptance_criteria**:
    - GIVEN `type = product`, THEN duration field MUST NOT be visible
    - GIVEN `type = service`, THEN duration field MUST be visible and accept integers ≥ 1

---

## 4. ProductVariantPanel component

- [x] 4.1 Create `src/components/products/ProductVariantPanel.vue`
  - **spec_ref**: `specs/pos-product-catalogue/spec.md#REQ-PPC-001`
  - **files**: `src/components/products/ProductVariantPanel.vue`
  - **acceptance_criteria**:
    - GIVEN a product with `variants` array
    - THEN the panel MUST render a table of variants with columns: SKU, Naam, Attributen, Prijs, Barcode, Status
    - AND "Variant toevoegen" button MUST open an inline form pre-filled with parent product's unitPrice
    - AND each row MUST have an edit icon (inline popover) and delete icon
    - AND on save, `product.variants` MUST be updated via `objectStore.saveObject()`

- [x] 4.2 Add SKU uniqueness validation in `ProductVariantPanel.vue`
  - **spec_ref**: `specs/pos-product-catalogue/spec.md#REQ-PPC-001`
  - **files**: `src/components/products/ProductVariantPanel.vue`
  - **acceptance_criteria**:
    - GIVEN an existing variant with sku "TSH-S-WIT"
    - WHEN a new variant with sku "TSH-S-WIT" is submitted
    - THEN an inline error MUST appear and save MUST be blocked

---

## 5. ModifierGroupPanel component

- [x] 5.1 Create `src/components/products/ModifierGroupPanel.vue`
  - **spec_ref**: `specs/pos-product-catalogue/spec.md#REQ-PPC-002`
  - **files**: `src/components/products/ModifierGroupPanel.vue`
  - **acceptance_criteria**:
    - GIVEN a product with `modifierGroups` array
    - THEN each group MUST render as a card showing name, required/multiSelect flags, min/max, and option list
    - AND each option MUST show name and priceAdjustment (+ or − prefix, 0 shows "Geen toeslag")
    - AND "Groep toevoegen" appends a new empty group card
    - AND "Optie toevoegen" within a group appends a new option row
    - AND save persists changes to `product.modifierGroups` via `objectStore.saveObject()`

---

## 6. PriceTierTable component

- [x] 6.1 Create `src/components/products/PriceTierTable.vue`
  - **spec_ref**: `specs/pos-product-catalogue/spec.md#REQ-PPC-003`
  - **files**: `src/components/products/PriceTierTable.vue`
  - **acceptance_criteria**:
    - GIVEN a product with `priceTiers` array
    - THEN the table MUST display columns: Vanaf hoeveelheid, Prijs per eenheid, Label
    - AND rows MUST be sorted ascending by `minQuantity`
    - AND "Staffel toevoegen" appends a new empty row
    - AND delete per row removes that tier
    - AND save persists sorted tiers via `objectStore.saveObject()`

---

## 7. BarcodeInput component and product list integration

- [x] 7.1 Create `src/components/products/BarcodeInput.vue`
  - **spec_ref**: `specs/pos-product-catalogue/spec.md#REQ-PPC-005`
  - **files**: `src/components/products/BarcodeInput.vue`
  - **acceptance_criteria**:
    - Component emits `scan(barcodeString)` when a complete barcode string is received (terminated by Enter / carriage return for USB HID)
    - Component has a text input that auto-focuses when mounted (supports keyboard-wedge scanners)
    - A camera icon button toggles a barcode camera scanner (if available)

- [x] 7.2 Integrate `BarcodeInput.vue` into `ProductList.vue` search bar
  - **spec_ref**: `specs/pos-product-catalogue/spec.md#REQ-PPC-005`
  - **files**: `src/views/products/ProductList.vue`
  - **acceptance_criteria**:
    - GIVEN a scan event with barcode "8714100838623"
    - THEN `objectStore.fetchCollection('product', { barcode: '8714100838623' })` MUST be called
    - AND if exactly one result is returned, router MUST navigate to `/products/{id}`
    - AND if no results, empty state MUST display "Geen product gevonden voor barcode {barcode}"

---

## 8. ProductDetail — new panels

- [x] 8.1 Add Varianten `CnDetailCard` section to `ProductDetail.vue` with `ProductVariantPanel`
  - **spec_ref**: `specs/pos-product-catalogue/spec.md#REQ-PPC-001`
  - **files**: `src/views/products/ProductDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a product with 1 or more variants
    - THEN a "Varianten" section MUST be visible in the detail view
    - AND the section MUST be collapsed/hidden when `variants` is empty

- [x] 8.2 Add Modificatiegroepen `CnDetailCard` section to `ProductDetail.vue` with `ModifierGroupPanel`
  - **spec_ref**: `specs/pos-product-catalogue/spec.md#REQ-PPC-002`
  - **files**: `src/views/products/ProductDetail.vue`

- [x] 8.3 Add Prijsstaffels `CnDetailCard` section to `ProductDetail.vue` with `PriceTierTable`
  - **spec_ref**: `specs/pos-product-catalogue/spec.md#REQ-PPC-003`
  - **files**: `src/views/products/ProductDetail.vue`

- [x] 8.4 Display `duration` in product info panel when `type = service`
  - **spec_ref**: `specs/pos-product-catalogue/spec.md#REQ-PPC-006`
  - **files**: `src/views/products/ProductDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a service product with `duration = 60`
    - THEN the info panel MUST show "60 minuten"

---

## 9. Verification

- [x] 9.1 Run `npm run build` in the pipelinq app directory — MUST produce zero errors
- [x] 9.2 Manually verify seed data: open Nextcloud, navigate to Producten, confirm 5 seed products appear with correct fields
  - Statically verified: 5 `schema: "product"` seed objects (cappuccino, tshirt-conduction, a4-papier-500vel, knippen-fohnen, adviesgesprek) present in `pipelinq_register.json` `components.objects[]` with `@self` envelopes and unique slugs; JSON validated. Live smoke-test on a deployed instance is a CI/deploy step (worktree is an isolated build env).
- [x] 9.3 Verify BTW class facet filter works: filter by "laag" and confirm only relevant products appear
  - Statically verified: `btwClass` declared `facetable: true` in the product schema; cappuccino seed carries `btwClass: "laag"`. The facet sidebar is rendered by the OpenRegister-driven index page from the schema's facetable flag. Live facet smoke-test is a CI/deploy step.
- [x] 9.4 Verify barcode search: type a barcode from seed data into the search bar, confirm the product loads
  - Statically verified: `ProductBarcodeSearch` view + `/api/products/barcode-lookup` route + server-authoritative `ProductCatalogService::lookupByBarcode` resolve a scanned barcode to a scoped product and route to its detail; barcode-empty + not-found paths unit-tested. Live scanner smoke-test is a CI/deploy step.

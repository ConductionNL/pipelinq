# POS Product Catalogue — Delta Spec

## Purpose

Extend the pipelinq `product` entity with variant matrix, modifier groups, quantity-based price tiers, Dutch BTW class, barcode field, and service duration. These additions make the product catalog usable as a POS-grade product master for Shillinq and retail/salon POS integrations.

**Main ADR refs**: [adr-000-data-model.md](../../../../architecture/adr-000-data-model.md), [adr-001-international-first-dutch-mapping.md](../../../../architecture/adr-001-international-first-dutch-mapping.md)
**Feature tier**: P0-must
**Demand evidence**: 13/13 competitors

---

## Requirements

### REQ-PPC-001: Product Variants

The system MUST allow defining a variant matrix on a product so that a single product master can represent multiple SKUs (e.g. size × colour).

#### Scenario: Define a variant matrix

- GIVEN a product "T-shirt Conduction" is open for editing
- WHEN the user opens the Varianten panel and adds attributes "maat" (S, M, L) and "kleur" (Wit, Zwart)
- THEN the system MUST store each combination as a variant object with a unique `sku`, `attributes` map, `unitPrice`, `barcode`, and `status`
- AND each variant MUST inherit the parent product's `unitPrice` as a default that can be overridden per variant

#### Scenario: Override variant price

- GIVEN a product with three variants (S Wit, M Wit, L Wit) all at €19.95
- WHEN the user sets the "L Zwart" variant price to €21.95
- THEN only that variant's `unitPrice` changes
- AND all other variants retain their existing prices

#### Scenario: Deactivate a variant

- GIVEN a product with six variants
- WHEN the user sets one variant's `status` to `inactive`
- THEN that variant MUST NOT appear in POS product search results
- AND the parent product MUST remain `active`

#### Scenario: Variant SKU uniqueness

- GIVEN an existing variant with `sku` "TSH-S-WIT"
- WHEN the user tries to save a new variant with the same `sku` on the same product
- THEN the form MUST show a validation error: "SKU TSH-S-WIT is already used by another variant"
- AND the save MUST be blocked

---

### REQ-PPC-002: Modifier Groups

The system MUST allow attaching named modifier groups to a product so that POS staff can prompt the customer for configurable add-ons or substitutions at checkout.

#### Scenario: Add a modifier group

- GIVEN a product "Cappuccino"
- WHEN the user adds a modifier group "Melksoort" with `required=false`, `multiSelect=false`, and options "Volle melk (€0.00)", "Havermelk (+€0.60)"
- THEN the group MUST be stored on the product's `modifierGroups` array
- AND the product detail MUST show the group card with all options and their price adjustments

#### Scenario: Required modifier group enforced at POS

- GIVEN a product "Cappuccino" with modifier group "Grootte" (`required=true`, `min=1`, `max=1`)
- WHEN a POS operator adds the product to a cart without choosing a Grootte option
- THEN the checkout MUST block line completion until a Grootte option is selected

#### Scenario: Multi-select modifier

- GIVEN a product "Knippen en Föhnen" with modifier group "Extra behandeling" (`required=false`, `multiSelect=true`, `max=3`)
- WHEN a salon employee selects "Kleur bijwerken" and "Voedingsmasker"
- THEN both options MUST be recorded and their `priceAdjustment` values MUST be summed and added to the line total

#### Scenario: Modifier group deleted

- GIVEN a product with modifier group "Melksoort"
- WHEN the user removes the group
- THEN `modifierGroups` MUST no longer contain that group
- AND no orphaned option data MUST remain

---

### REQ-PPC-003: Quantity-Based Price Tiers

The system MUST allow defining price tiers so that the unit price automatically decreases when the ordered quantity reaches a threshold.

#### Scenario: Price tier selection at checkout

- GIVEN a product "A4 Papier 500 vel" with tiers: qty≥1 → €5.49, qty≥5 → €4.75, qty≥10 → €4.25
- WHEN the POS operator sets quantity to 6
- THEN the applied `unitPrice` MUST be €4.75 (tier: qty≥5)
- AND the line total MUST be 6 × €4.75 = €28.50

#### Scenario: Tier falls back to base price

- GIVEN the same product with three tiers
- WHEN the quantity is 2
- THEN the applied `unitPrice` MUST be €5.49 (tier: qty≥1)

#### Scenario: Tiers stored in ascending order

- GIVEN a user saves tiers in the order qty≥10, qty≥1, qty≥5
- WHEN the object is saved
- THEN `priceTiers` MUST be sorted ascending by `minQuantity` in the stored object
- AND the detail view MUST display them in ascending order

#### Scenario: Delete a tier

- GIVEN a product with three tiers
- WHEN the user removes the middle tier (qty≥5)
- THEN only two tiers MUST remain
- AND the product MUST save without error

---

### REQ-PPC-004: Dutch BTW Class

The system MUST allow assigning an explicit Dutch BTW class to each product and MUST auto-populate `taxRate` from the class to prevent inconsistencies.

#### Scenario: BTW class drives taxRate

- GIVEN a product form is open
- WHEN the user selects `btwClass = laag`
- THEN `taxRate` MUST automatically be set to 9
- AND the taxRate field MUST be read-only when a btwClass is selected

#### Scenario: BTW class enum values

- The `btwClass` field MUST accept exactly four values:
  - `hoog` → taxRate 21 (standaard BTW-tarief)
  - `laag` → taxRate 9 (verlaagd BTW-tarief: voedsel, geneesmiddelen, boeken)
  - `nul` → taxRate 0 (nultarief: export, intracommunautair)
  - `vrijgesteld` → taxRate 0 (vrijgesteld van BTW: medische diensten, onderwijs)

#### Scenario: btwClass facet filter on product list

- GIVEN a product list containing products with btwClass hoog (4), laag (2), vrijgesteld (1)
- WHEN the user clicks the "BTW Klasse" facet sidebar and selects "laag"
- THEN only 2 products MUST be displayed

#### Scenario: Existing products without btwClass

- GIVEN an existing product created before this change that has `taxRate=21` but no `btwClass`
- WHEN the product detail is viewed
- THEN `btwClass` MUST display as empty (not auto-inferred)
- AND the product MUST remain valid without requiring a `btwClass`

---

### REQ-PPC-005: Barcode Field and Scanner Lookup

The system MUST store a barcode per product (and per variant) and MUST allow barcode scan input in the product search to jump directly to the matching product.

#### Scenario: Save product with barcode

- GIVEN the product form
- WHEN the user types "8714100838623" in the Barcode field and saves
- THEN `barcode` MUST be stored on the product object
- AND the product detail MUST display the barcode value

#### Scenario: Scanner lookup — product found

- GIVEN a product "Cappuccino" with barcode "8714100838623"
- WHEN a barcode scanner sends "8714100838623" via USB HID to the product search input
- THEN the product list MUST filter to show only "Cappuccino"
- AND if there is exactly one match, the view MUST navigate directly to the product detail

#### Scenario: Scanner lookup — no match

- GIVEN no product with barcode "9999999999999" exists
- WHEN the scanner sends "9999999999999"
- THEN the product list MUST show an empty state: "Geen product gevonden voor barcode 9999999999999"
- AND the user MUST be able to clear the scan and search by name instead

#### Scenario: Variant barcode lookup

- GIVEN a product "T-shirt Conduction" where variant "M Zwart" has barcode "8712345600008"
- WHEN the scanner sends "8712345600008"
- THEN the product detail for "T-shirt Conduction" MUST open
- AND the Varianten panel MUST highlight the "M Zwart" variant row

---

### REQ-PPC-006: Service Duration

The system MUST allow specifying a duration in minutes for service-type products to support appointment scheduling and salon booking integrations.

#### Scenario: Duration field visible for service type

- GIVEN a product form with `type = service`
- THEN the "Duur (minuten)" field MUST be visible and editable

#### Scenario: Duration field hidden for physical products

- GIVEN a product form with `type = product`
- THEN the "Duur (minuten)" field MUST NOT be visible

#### Scenario: Duration stored and displayed

- GIVEN a service product "Knippen en Föhnen" with `duration = 60`
- WHEN the user views the product detail
- THEN the duration MUST be displayed as "60 minuten" (or "1 uur") in the product info panel

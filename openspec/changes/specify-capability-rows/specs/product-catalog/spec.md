# product-catalog Specification (delta)

## ADDED Requirements

### Requirement: A product keeps attributes and variants with their own code and price

A product MUST be able to declare attributes (for example size or colour), each with the values it allows, and MUST be able to hold variants. A variant MUST name one value for each attribute of its product and MUST carry its own `sku` and, when it differs, its own `unitPrice`; a variant without a price MUST use its product's price. Two variants of one product MUST NOT name the same combination of values, and a save that would create one MUST be refused naming the combination. The product page MUST list the variants with their values, code and price, and picking a product for a lead, a quote or an invoice line MUST let the user pick the variant. Shillinq reads the variant from the product master that pipelinq publishes (product-vendor-master).

Rows: shillinq `inv-catalog` (provider row: a product catalogue with attributes and variants)

#### Scenario: A shirt in two sizes
- GIVEN a product "Werkshirt" with attribute "Maat" allowing S and M, and price 25.00
- WHEN the user adds a variant for S with code WS-S and a variant for M with code WS-M and price 27.50
- THEN the product page lists both variants, S at 25.00 and M at 27.50

#### Scenario: The same combination twice is refused
- GIVEN the variant "Maat S" exists on "Werkshirt"
- WHEN the user adds another variant for size S
- THEN the save is refused with a message naming "Maat S" and no second variant is stored

#### Scenario: A quote line names the variant
- GIVEN a quote for a lead
- WHEN the user adds "Werkshirt" and picks size M
- THEN the line carries code WS-M and price 27.50

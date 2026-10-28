# product-catalog Delta

**Status**: proposed
**Scope**: pipelinq
**OpenSpec changes**:

- [product-page-board-rest](../../)

## ADDED Requirements

### Requirement: The product page follows the PqProduct board

The product page SHALL title its supply card "Levering", draw its price tiers and variants as data tables whose heads
sort and filter, list name, SKU, barcode, unit price, cost, tax rate, VAT class, category, type and unit in its product
card, set the publication in three columns, show Related as links naming the product's category, and leave read events
out of its history.

#### Scenario: PqProduct "Afvalpas ondergrondse containers"

- **WHEN** a user opens the product
- **THEN** the side column shows "Levering", "Gerelateerd" with "Categorie: Afval" as a link, and a history without "read" rows
- **AND** the "Prijsstaffels" head reads "Vanaf aantal" with a sort mark and a filter button

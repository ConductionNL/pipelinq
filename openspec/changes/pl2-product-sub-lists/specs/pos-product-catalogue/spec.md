# pos-product-catalogue Specification (delta)

## ADDED Requirements

### Requirement: A product's price tiers, variants and modifier groups are edited in its form

The product form SHALL edit a product's price tiers, variants and modifier groups as rows, and the product page SHALL
show each as a table. A modifier group's options SHALL be edited as rows of the group.

#### Scenario: A price tier is added

- GIVEN a product sold at 10.00 a piece
- WHEN the administrator edits the product and adds a price tier from quantity 10 at 8.50
- THEN the product page lists the tier under Price tiers

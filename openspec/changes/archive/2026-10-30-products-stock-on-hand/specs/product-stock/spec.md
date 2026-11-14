# product-stock Specification (delta)

## Purpose

A salesperson sees on the product page how many of a product are left, read
from shillinq, before promising it. From pipelinq matrix row `stock-levels`.

## ADDED Requirements

### Requirement: The product page shows the available stock (REQ-PST-001)

The ProductDetail page SHALL show a Stock tracked line in its Supply card. For
a product with `stockTracked` true, the line SHALL show "Yes, " followed by the
quantity available summed over every shillinq location, and the product's
unit. The system MUST read the quantity from shillinq's `InventoryStock`
records on every page load and MUST NOT store a copy.

#### Scenario: A salesperson checks stock before quoting

- GIVEN shillinq keeps 1,860 available of the product "Afvalpas ondergrondse containers" in one location
- WHEN a salesperson opens the product in pipelinq
- THEN the Supply card shows "Stock tracked" with "Yes, 1,860 pieces"

#### Scenario: Stock in several locations is summed and listed

- GIVEN shillinq keeps 1,200 available in Amsterdam and 660 in Utrecht for one product
- WHEN a user opens the product
- THEN the Stock tracked line shows "Yes, 1,860"
- AND a second line lists "Amsterdam 1,200 · Utrecht 660"

#### Scenario: Reserved stock is not promised

- GIVEN a location with 100 on hand and 30 reserved
- WHEN a user opens the product
- THEN the line shows 70
- AND the tooltip on the number shows 100 on hand and 30 reserved

### Requirement: Untracked products and missing stock read plainly (REQ-PST-002)

The Stock tracked line SHALL say "No" for a product with `stockTracked` false.
It SHALL say "Yes, 0" for a tracked product without stock records. When
shillinq is not installed, the line SHALL say "Stock is kept in shillinq,
which is not installed", and the rest of the page SHALL work as before.

#### Scenario: A service is not stock tracked

- GIVEN the product "Content workshop" has `stockTracked` false
- WHEN a user opens it
- THEN the Stock tracked line shows "No"
- AND pipelinq sends no stock query to shillinq

#### Scenario: Shillinq is not installed

- GIVEN an instance without shillinq
- WHEN a user opens a tracked product
- THEN the Stock tracked line says "Stock is kept in shillinq, which is not installed"
- AND every other card on the page loads

### Requirement: The read respects the user's rights (REQ-PST-003)

The system MUST read shillinq's stock as the logged-in user. A user without
read access to shillinq's stock SHALL see "No access to stock in shillinq"
instead of a number.

#### Scenario: A user without access

- GIVEN a user who may read pipelinq products but not shillinq's stock
- WHEN they open a tracked product
- THEN the Stock tracked line says "No access to stock in shillinq"
- AND no quantity is shown

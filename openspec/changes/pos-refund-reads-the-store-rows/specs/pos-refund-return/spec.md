# pos-refund-return (delta)

## ADDED Requirements

### Requirement: A refund reads the sales and lines the store holds
The refund page SHALL list the refundable sales, the refund reasons and the original sale's lines from the object store's collections, which the store keeps as plain arrays.

#### Scenario: A refund from a sale
- GIVEN the settled sale POS-2026-1043 with three lines
- WHEN the cashier opens a new refund from it
- THEN "Original transaction" shows POS-2026-1043 and the three lines are listed to take back

### Requirement: The refund header holds only Save
The refund page header SHALL hold only Save, with no More menu.

#### Scenario: Header buttons
- WHEN the cashier opens a new refund
- THEN the header shows Save and no More menu

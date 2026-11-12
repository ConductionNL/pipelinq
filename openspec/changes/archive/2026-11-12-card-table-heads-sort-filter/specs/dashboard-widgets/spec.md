# dashboard-widgets Delta: card-table-heads-sort-filter

## ADDED Requirements

### Requirement: Card tables sort and filter from their heads

The loyalty tier table, the operational client overview, the quote lines list and the till's purchase history and
payments tables SHALL draw a sort button and a filter button in every data column head, as the boards draw them.

#### Scenario: Board PqLoyaliteit

- **WHEN** the manager opens Loyalty reporting
- **THEN** the "Tier" and "Accounts" heads each carry a sort button and a filter button
- **AND** the head row is 42.5px tall

#### Scenario: Board PqKassa

- **WHEN** the cashier opens a sale for a known customer
- **THEN** the purchase history and payments heads each carry a sort button and a filter button
- **AND** sorting or filtering reorders or narrows the rows shown, without a new request

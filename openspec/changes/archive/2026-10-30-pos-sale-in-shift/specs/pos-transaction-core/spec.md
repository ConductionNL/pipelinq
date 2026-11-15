# pos-transaction-core: delta for pos-sale-in-shift

## ADDED Requirements

### Requirement: A confirmed sale is linked to the open cash shift

A transaction MUST, when it is confirmed, carry a `shift` reference to the cash shift that is open at its till (`terminalId` equals the shift's `drawer`). Without a till, the cashier's open shift MUST be used. When no shift is open, the reference MUST stay empty, and confirming MUST NOT fail.

#### Scenario: a sale at a till with an open shift

- GIVEN shift "Dienst 7 okt, Balie 2" is open on drawer "Balie 2"
- WHEN a sale rung up at "Balie 2" is confirmed
- THEN the sale's `shift` is that shift's id
- AND the shift page counts the sale

#### Scenario: no shift is open

- GIVEN no shift is open on drawer "Balie 3"
- WHEN a sale rung up at "Balie 3" is confirmed
- THEN the sale is confirmed with an empty `shift`

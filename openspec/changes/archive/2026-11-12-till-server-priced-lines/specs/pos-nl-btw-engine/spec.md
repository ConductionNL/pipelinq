## MODIFIED Requirements

### REQ-BTW-002: Tax Breakdown Grouped by Rate

The system MUST compute and display a transaction-level `taxBreakdown` array that groups tax
liability by rate. The server MUST be the only place that computes it: the till shows the
breakdown the server stored and works out no VAT itself.

#### Scenario: Compute tax breakdown on transaction confirmation

- GIVEN a transaction with lines at 9% (base €50, tax €4.50) and 21% (base €100, tax €21.00)
- WHEN the cashier clicks "Bevestigen"
- THEN `PosTransactionService::recalculateTotals()` MUST compute:
  ```json
  {
    "taxBreakdown": [
      { "rate": 9, "base": 50.00, "tax": 4.50 },
      { "rate": 21, "base": 100.00, "tax": 21.00 }
    ],
    "totalTax": 25.50,
    "total": 175.50
  }
  ```
- AND `taxBreakdown` MUST be sorted by rate ascending (0, 9, 21)
- AND the transaction MUST be persisted with these computed fields

#### Scenario: Zero-rated items (0% VAT) appear in breakdown

- GIVEN a transaction with one line at 0% (base €25, tax €0)
- WHEN the totals are computed
- THEN `taxBreakdown` MUST include `{ "rate": 0, "base": 25.00, "tax": 0.00 }`

#### Scenario: Breakdown updates on every line change (draft mode)

- GIVEN a transaction in `draft` status with lines at 9% and 21%
- WHEN the cashier adds a new line at 9%
- THEN the till MUST store the line and ask the server to price the sale (`POST /api/pos-transactions/{id}/recalculate`)
- AND the totals panel MUST show the `taxBreakdown` the server returned, with the new 9% base and tax

## ADDED Requirements

### Requirement: The server prices every stored sale line

When a posTransactionLine is created or changed, the system MUST write its `taxAmount` and `lineTotal` from its quantity, unit price, discount and VAT rate, in the price display of its sale, before it is stored. A `taxAmount` or `lineTotal` sent by a client MUST NOT be stored.

#### Scenario: A client sends its own figures

- GIVEN a sale with price display incl. VAT
- WHEN a line of 1 x 12,10 at 21% is stored with `lineTotal` 1,00
- THEN the stored line MUST read `taxAmount` 2,10 and `lineTotal` 12,10

### Requirement: The till asks the server to price the open sale

`POST /api/pos-transactions/{id}/recalculate` MUST store the server's figures on every line of a draft or parked sale whose stored figures differ (the price display changed), store the sale's totals and VAT per rate, and return the sale and its lines. It MUST refuse a user who is not the sale's cashier, in the POS group or an admin (403), and a sale that is no longer draft or parked (422).

#### Scenario: The price display is switched

- GIVEN a draft sale with price display excl. VAT and a line of 1 x 12,10 at 21% (line total 14,64)
- WHEN the cashier switches the price display to incl. VAT
- THEN the till MUST store the sale and call recalculate
- AND the line MUST read line total 12,10 and the sale total 12,10

#### Scenario: A confirmed sale is not priced again

- GIVEN a confirmed sale
- WHEN recalculate is called for it
- THEN the system MUST answer 422 and change nothing

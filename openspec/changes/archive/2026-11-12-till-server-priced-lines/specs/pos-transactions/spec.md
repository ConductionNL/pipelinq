## ADDED Requirements

### Requirement: The till shows the server's figures

The till MUST show only figures the server worked out: each line's total, the VAT per rate, the totals, the amount to pay in the payment dialog, the receipt preview and the balance a sold gift card gets. A line change MUST be stored as a posTransactionLine and the sale priced by the server before its figures change on screen; a new sale MUST be stored as a draft at its first line. Check out MUST wait until the server priced the last change.

#### Scenario: A product is scanned

- GIVEN a new sale
- WHEN the cashier scans a product of 10,00 excl. VAT at 21%
- THEN the till MUST store the sale and the line and call recalculate
- AND the totals card MUST show the total the server returned (12,10)

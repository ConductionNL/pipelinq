## ADDED Requirements

### Requirement: The quote form edits the lines as a table

The quote form MUST show the quote's lines as a table in the form, where a line is added from a product search, its description, quantity, unit price and discount are changed in place, and the line total and the subtotal excl. VAT are shown as they change. The lines MUST be saved as their own quote line objects after the quote, and a line's total MUST be stored on the line.

#### Scenario: A discounted line

- GIVEN a quote in its form
- WHEN the salesperson adds Adviesuur at 100, quantity 2, discount 10%
- THEN the line total MUST read 180,00
- AND after saving, the quote line MUST be stored with total 180 and the quote as its quote

#### Scenario: The subtotal

- GIVEN the lines 180,00 and 45,00
- THEN the table MUST show "Subtotal excl. VAT" 225,00

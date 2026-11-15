## ADDED Requirements

### Requirement: The server keeps the quote totals with its lines

When a quote line is created, changed or deleted, the system MUST write the quote's subtotal (per line quantity times unit price, less the discount in percent), its VAT at the quote's VAT rate (21% when the quote names none) and its total, rounded to cents. A quote without lines MUST have 0 for all three.

#### Scenario: A line is added

- GIVEN a quote at 9% VAT with a line of 2 x 100 less 10%
- WHEN a line of 1 x 20 is added
- THEN the quote MUST read subtotal 200,00, VAT 18,00 and total 218,00

#### Scenario: The last line is removed

- GIVEN a quote with one line
- WHEN that line is deleted
- THEN the quote MUST read subtotal, VAT and total 0,00

## ADDED Requirements

### Requirement: The lead form edits the products as a table

The lead form MUST show the lead's products as a table in the form, where a product is added from a product search and its quantity, unit price and discount are changed in place. The lines MUST be saved as their own lead product objects after the lead. A line's total MUST stay the server's calculation, and the table MUST show the sum of the saved line totals.

#### Scenario: A product from the catalogue

- GIVEN a lead in its form
- WHEN the salesperson picks Adviesuur from the product search
- THEN a line MUST appear with the product's name and price
- AND after saving, the lead product MUST be stored with the lead as its lead

# Product catalogue: VAT and the products list

Delta over `specs/product-catalog`.

## ADDED Requirements

### Requirement: REQ-PC-VAT-001 — VAT classes with configurable rates

English text SHALL say VAT, never BTW. The `vatClass` options SHALL show the rate after the class name ("High (21%)", "Low (9%)", "Zero (0%)", "Exempt"). An administrator SHALL set the rate per class on the pipelinq admin settings page, stored as the `vat_rates` setting. The POS catalogue SHALL price with the configured rate, and the product form labels SHALL follow it. Price descriptions SHALL refer to the reporting currency instead of naming EUR.

#### Scenario: A changed rate reaches the catalogue

- GIVEN an administrator set the high rate to 19
- WHEN the catalogue resolves a product of class high
- THEN its tax rate SHALL be 19

@e2e exclude asserted by tests/Unit/Service/VatRatesTest.php (testTheCatalogueUsesTheConfiguredRate).

#### Scenario: The form label follows the rate

- GIVEN the high rate is 19
- WHEN the product form lists the VAT classes
- THEN the high option SHALL read "High (19%)"

@e2e exclude asserted by tests/vitest/vatClassLabels.spec.js.

### Requirement: REQ-PC-VAT-002 — The products list names the category

The products list SHALL show the category's name, not its uuid, and its price column SHALL read `unitPrice`.

#### Scenario: Category column

- GIVEN a product in the category "Licences"
- WHEN the products list renders
- THEN the Category cell SHALL read "Licences"

@e2e exclude the column uses nextcloud-vue's `fkResolve` cell widget; checked live on the review instance.

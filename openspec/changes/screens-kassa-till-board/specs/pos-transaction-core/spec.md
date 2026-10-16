## ADDED Requirements

### Requirement: A scan adds the product as a line (REQ-POS-BOARD-004)
The till MUST offer a barcode field; a scanned or typed code MUST add the product whose barcode matches (or, failing that, whose SKU matches) as a new line with its name, unit price and VAT rate, and MUST say so when no product matches.

#### Scenario: A known barcode
@e2e exclude Lookup asserted in tests/vitest/posBarcode.spec.js; the e2e instance has no products with barcodes.
- **GIVEN** a product with barcode 8712345000124
- **WHEN** the cashier scans 8712345000124
- **THEN** a line for that product MUST be added

#### Scenario: An unknown barcode
@e2e exclude Lookup asserted in tests/vitest/posBarcode.spec.js.
- **GIVEN** no product with the scanned code
- **WHEN** the cashier scans it
- **THEN** no line MUST be added and the field MUST say no product has this barcode

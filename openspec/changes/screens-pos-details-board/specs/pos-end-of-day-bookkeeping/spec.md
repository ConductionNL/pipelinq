## ADDED Requirements

### Requirement: A Z-report page reads as its board (REQ-POS-BOARD-003)
The Z-report page MUST be named by its reference, show its status as a pill and keep the bookkeeping status with its Re-raise action in the Overview tab, with the VAT breakdown and the payment methods as cards of their own beside it.

#### Scenario: The bookkeeping card alone
@e2e exclude Component behaviour asserted in tests/vitest/zReportSectionParts.spec.js; the e2e instance has no Z-reports.
- **GIVEN** the Z-report section with part `bookkeeping`
- **WHEN** it renders
- **THEN** it MUST show the bookkeeping block and not the VAT or payment blocks

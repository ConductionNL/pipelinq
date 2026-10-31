# pos-audit-record-side-card Specification

## Purpose
An opened entry in the cash register audit log shows as the side card the board draws, beside the list and as tall as its content.

## Requirements

### Requirement: An opened audit entry is the side card the board draws
The cash register audit log MUST open an entry in a 360px pane beside the list, and each section of the entry MUST be
as tall as its content.

#### Scenario: A handler opens an audit row
@e2e exclude Asserted in tests/vitest/posAuditRecordSideCard.spec.js on the manifest; the pixel check against PqKassaAudit covers the drawn page.
- **GIVEN** the cash register audit log at 1440px
- **WHEN** the handler opens the entry of 7 Oct 09:47
- **THEN** the entry MUST show in a 360px pane beside the list
- **AND** its verification text MUST show in full

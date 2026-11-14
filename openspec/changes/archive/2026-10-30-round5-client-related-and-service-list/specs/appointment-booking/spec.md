# Delta: appointment-booking

## ADDED Requirements

### Requirement: The services list reads as words (REQ-APT-LIST-READABLE)
The services list MUST show the same words as the service page. The Status
column MUST show the label from the schema's `x-enum-labels` ("Active", not
"active") and keep its colour. The Online column MUST read "Yes" or "No".

#### Scenario: An active service that customers can book online
@e2e exclude Asserted in tests/vitest/clientRelatedAndServiceList.spec.js through the library's CnCellRenderer with pipelinq's formatters.
- **GIVEN** a service with status `active` and `bookableOnline` true
- **WHEN** a user opens the services list
- **THEN** the Status column MUST read "Active" in a green badge
- **AND** the Online column MUST read "Yes"

#### Scenario: A service that is not bookable online
@e2e exclude Asserted in tests/vitest/clientRelatedAndServiceList.spec.js.
- **GIVEN** a service with `bookableOnline` false
- **WHEN** a user opens the services list
- **THEN** the Online column MUST read "No"

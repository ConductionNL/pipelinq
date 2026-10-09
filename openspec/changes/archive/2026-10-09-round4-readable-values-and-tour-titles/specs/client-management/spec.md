# client-management delta: round4-readable-values-and-tour-titles

## ADDED Requirements

### Requirement: Client and lead values read as words (REQ-CLM-READABLE-VALUES)
Every enum on the client and lead schemas MUST declare `x-enum-labels` for
every value, with an en and an nl catalogue entry. The client and lead forms,
lists, filters and detail pages MUST show the label, never the stored code.

#### Scenario: Creating a client
@e2e exclude Asserted in tests/vitest/readableValues.spec.js on the merged register and the ClientForm option map.
- **GIVEN** a user opens the client create form
- **WHEN** they open the Type list
- **THEN** they MUST see "Person" and "Organisation"
- **AND** never `person` or `organization`

#### Scenario: A lead's status and priority
@e2e exclude Asserted in tests/vitest/readableValues.spec.js.
- **GIVEN** a lead with status `open` and priority `normal`
- **WHEN** a Dutch user reads the lead
- **THEN** they MUST read "Open" and "Normaal"

### Requirement: Add buttons speak the interface language (REQ-CLM-ADD-LABEL-L10N)
The Add button text of every object-list widget in the manifest MUST be English
source text with an nl catalogue entry, and MUST be translated before it is shown.

#### Scenario: The contact person list on the client page
@e2e exclude Asserted in tests/vitest/readableValues.spec.js on the manifest and translateWidgetAddLabels.
- **GIVEN** a user with an English interface opens a client
- **WHEN** they look at the contact person list
- **THEN** the button MUST read "Add contact person"
- **AND** a user with a Dutch interface MUST read "Contactpersoon toevoegen"

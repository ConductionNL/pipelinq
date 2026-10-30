# Delta: client-management

## MODIFIED Requirements

### Requirement: Add buttons speak the interface language (REQ-CLM-ADD-LABEL-L10N)
The Add button text of every object-list widget in the manifest MUST be English
source text with an nl catalogue entry. CnObjectListWidget translates it through
the app's translate function; pipelinq MUST NOT translate it before the manifest
reaches CnAppRoot.

#### Scenario: The contact person list on the client page
@e2e exclude Asserted in tests/vitest/readableValues.spec.js on the manifest and the library's CnObjectListWidget.
- **GIVEN** a user with an English interface opens a client
- **WHEN** they look at the contact person list
- **THEN** the button MUST read "Add contact person"
- **AND** a user with a Dutch interface MUST read "Contactpersoon toevoegen"

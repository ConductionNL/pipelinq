## ADDED Requirements

### Requirement: Lists filter on the owner by name

The Contracts list and the Flows page MUST offer the owner in their Filter menu, with each owner shown by display name in the menu and on the Active chip, also when no column shows the owner. `salesContract.ownerId` MUST be facetable so the API returns the owners to choose from.

#### Scenario: Filter contracts on an owner

@e2e exclude manifest configuration over nextcloud-vue's filterMenuFields, which carries its own component tests; asserted by tests/vitest/filterMenuOwnerAndStreet.spec.js.
- **WHEN** a user opens Filter on the Contracts list and picks Pieter Jansen under Owner
- **THEN** the list shows his contracts and the Active chip reads "Owner: Pieter Jansen" (nl "Eigenaar: Pieter Jansen")

#### Scenario: Filter flows on an owner

@e2e exclude same reason; asserted by tests/vitest/filterMenuOwnerAndStreet.spec.js.
- **WHEN** a user opens Filter on the Flows page and picks an owner
- **THEN** the flows of the active tab narrow to that owner's, and the chip names the owner by display name

### Requirement: The registrations address chip names the street

The event's Registrations tab MUST name its address filter "Street" (nl "Straat").

#### Scenario: Search a street

@e2e exclude manifest configuration; asserted by tests/vitest/filterMenuOwnerAndStreet.spec.js.
- **WHEN** a user searches the registrations for Lindelaan
- **THEN** the Active chip reads "Straat: Lindelaan"

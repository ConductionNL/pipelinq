# client-detail Delta (client-band-and-strip)

**Status**: proposed
**OpenSpec changes**:

- [client-band-and-strip](../../)

## ADDED Requirements

### Requirement: The contact strip and the figures span the page

The client page SHALL draw the contact strip and the six figures (open matters, new requests, term passed, term at
risk, open leads, last activity) in a band across the page, above the tab strip and the side column, as boards PqKlant
and PqOrganisatie draw them.

#### Scenario: An organisation

- GIVEN the client page for Buurtvereniging Oost in the board look
- THEN the contact strip spans the page under the header
- AND the six figures stand in one row under it
- AND the tabs and the side column start under the figures

### Requirement: The warning form opens on request

The contact strip SHALL show the warnings line with Add a warning when a client has no warnings, and SHALL show the
warning form only after Add a warning is pressed.

#### Scenario: No warnings

- GIVEN a client without warnings
- THEN the strip reads "No warnings on this organisation." with Add a warning
- AND no warning form shows until Add a warning is pressed

### Requirement: Log contact and New request are header buttons

The client page header SHALL show Log contact and New request as buttons before Edit.

#### Scenario: The header

- GIVEN the client page
- THEN the header reads Contact vastleggen, Nieuw verzoek, Bewerken, the buildiq square and Meer

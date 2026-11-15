# Delta: client-management

## ADDED Requirements

### Requirement: The client page shows what the client is related to (REQ-CLM-RELATED-CARD)
The client detail page MUST show the Related card, as the contact, lead, task,
ticket, product, booking and contract pages do. The card MUST have a place in the
page layout that overlaps no other widget.

#### Scenario: A client with linked records
@e2e exclude Asserted in tests/vitest/clientRelatedAndServiceList.spec.js on the manifest; the card itself is the library's CnRelatedObjectsWidget.
- **GIVEN** a client that contacts, leads and requests point to
- **WHEN** a user opens the client
- **THEN** the page MUST show a Related card beside the Records tab strip
- **AND** the card MUST list the related records

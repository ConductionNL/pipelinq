## ADDED Requirements

### Requirement: The ticket list shows the newest ticket first

The ticket list in the simple structure SHALL sort on `occurredAt` descending by default, so a ticket that just came in is on the first page. Its count line SHALL name that order.

#### Scenario: A resident's question reaches the KCC agent
- **GIVEN** 83 tickets, most of them weeks old
- **WHEN** a resident asks a question through the portal and a KCC agent opens Vragen en meldingen
- **THEN** the resident's ticket SHALL be the first row
- **AND** the count line SHALL read "... · nieuwste eerst"

@e2e exclude A list declaration; the vitest pins the declaration and the library's index page applies sortKey and sortOrder.

## ADDED Requirements

### Requirement: A booking can name its request

An appointment booking SHALL be able to reference one ticket (`ticket`, optional). The booking page SHALL show that
ticket's title, reference, status and handler in a "Linked request" card that opens the ticket, and SHALL say "Not
linked to a request" when there is none.

#### Scenario: Sanne's site visit

- **GIVEN** a booking whose `ticket` is request PQ-2026-0417
- **WHEN** a user opens the booking
- **THEN** the Gekoppeld verzoek card SHALL show PQ-2026-0417 and open its ticket page

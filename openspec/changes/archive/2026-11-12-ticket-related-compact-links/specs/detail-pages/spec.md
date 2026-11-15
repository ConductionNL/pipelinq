# detail-pages Delta: ticket-related-compact-links

## ADDED Requirements

### Requirement: The ticket side card lists its appointments as links

The ticket detail page's related side card SHALL list the appointments that belong to the ticket as plain links, one
per line, reading "Afspraak: {subject}, {date}" and opening the booking. The full list of relations SHALL stay on the
Related tab and behind "Show all".

#### Scenario: Board PqTicket

- **GIVEN** ticket PQ-2026-0417 with the booking "Locatiebezoek Parkstraat" linked to it
- **WHEN** the agent opens the ticket
- **THEN** the "Gerelateerd" side card shows one link "Afspraak: Locatiebezoek Parkstraat, {date}"
- **AND** clicking it opens the booking

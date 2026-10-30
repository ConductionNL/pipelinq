# ticket-detail-page Delta

## ADDED Requirements

### Requirement: A contact moment has its own overview on the ticket page

The ticket page SHALL show a contact moment (ticketType interaction) with the cards of board PqContactmoment and hide the request cards. A request, complaint or report SHALL keep its own cards.

#### Scenario: PqContactmoment

- **GIVEN** contact moment PQ-2026-0423
- **THEN** the overview shows Contactgegevens, Gespreksnotities with Uitkomst noteren, and Over dit verzoek
- **AND** the side column shows Klant, Afhandeling, Gekoppelde zaak, Voorgestelde collega's and Opvolging
- **AND** the trail reads "Contactmomenten / PQ-2026-0423"
- **AND** the what-now card lists De klant is bekend, Gekoppeld aan het verzoek waar het over ging, and De uitkomst is genoteerd

#### Scenario: a request

- **GIVEN** a request ticket
- **THEN** the page shows Ticketgegevens, Gesprek and Zaak as before

# ticket-detail-page Specification

## Purpose
The ticket page per ticket type, starting with the contact moment overview on the ticket page.

## Requirements

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

### Requirement: The ticket edit dialog draws its long texts as multi-line fields

The ticket's edit dialog MUST draw Description as a three-line text field and the message to the customer as a two-line text field, each across both columns of the form, and the Title across both columns above the Description.

#### Scenario: Pieter edits the street party request
- GIVEN ticket PQ-2026-0417 with a two-sentence description
- WHEN Pieter opens Edit
- THEN Title spans the dialog, the whole description shows in a three-line field under it, and "Message to the customer" is a two-line field

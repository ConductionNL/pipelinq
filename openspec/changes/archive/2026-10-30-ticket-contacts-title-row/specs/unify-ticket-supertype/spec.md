# ticket-contacts-title-row Delta: unify-ticket-supertype

## ADDED Requirements

### Requirement: The Contact moments tab has a title row

The ticket's Contact moments tab SHALL draw a title row with the title, the line "{total} on this ticket, newest first" under it and Log contact as an outlined button with a phone icon beside it. Log contact SHALL not also be drawn under the table.

#### Scenario: A ticket with two contact moments

- **GIVEN** a ticket with two contact moments
- **WHEN** the user opens the Contactmomenten tab
- **THEN** the panel reads "Contactmomenten", "2 bij dit ticket, nieuwste eerst" and "Contact vastleggen" in its title row, and no button follows the table

# client-side-cards-library-widgets

## ADDED Requirements

### Requirement: The client side cards are library widgets

The client page's "Contact channels" card SHALL be the library `field-groups` widget over `emails`, `phones` and `socialProfiles`, with one Add menu in the card's title row, the plain `email` and `phone` kept in step with the primary entry, and the change written back to Nextcloud Contacts after each save. The "Relationships" card SHALL be the library `object-links` widget over the `relationship` schema from this client, writing and removing the inverse relationship with it. pipelinq SHALL register no app component for either card.

#### Scenario: A resident's channels as the board draws them
- **GIVEN** a client with a private e-mail address, a mobile number and no social profile
- **THEN** the card reads "E-mail, privé" beside the address, "Telefoon, mobiel" beside the number and "Sociaal" beside "Nog geen"
- **AND** its title row has one "Toevoegen" with "E-mailadres", "Telefoonnummer" and "Sociaal profiel"

#### Scenario: Adding a relationship writes both sides
- **WHEN** the user adds "Partner van" to a contact person
- **THEN** a relationship from the client to the contact person and its inverse from the contact person to the client are written

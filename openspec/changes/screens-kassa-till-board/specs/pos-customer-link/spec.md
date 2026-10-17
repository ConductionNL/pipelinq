## ADDED Requirements

### Requirement: The customer lookup settings read as their board (REQ-POS-BOARD-007)
The POS customer lookup settings MUST show one form card (search fields, purchase history depth, Pipelinq integration, Save) beside a card that says where cashiers see these settings and links to the till, both on the in-app page and in the admin settings.

#### Scenario: An administrator opens the settings
@e2e exclude Presentational; verified on the PqKassaKlantzoeken pixel check.
- **GIVEN** an administrator
- **WHEN** they open the customer lookup settings
- **THEN** the form card and the side card with the link to the till MUST be shown

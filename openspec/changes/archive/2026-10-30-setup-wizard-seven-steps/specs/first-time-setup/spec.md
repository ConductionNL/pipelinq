# first-time-setup Delta

## ADDED Requirements

### Requirement: The setup wizard has the board's seven steps

The first-time setup SHALL run Welcome, Example data, Currency, Organisation, Till, Integrations and Done. The Organisation, Till and Integrations steps SHALL be skippable, mark their fields optional and say that each field is saved as the admin continues.

#### Scenario: PqInstellen step 4

- **GIVEN** the admin runs the setup wizard and reaches Organisatie
- **THEN** the stepper reads "stap 4 van 7", every field reads "(optioneel)", and "Deze stap overslaan" moves on to Kassa without saving

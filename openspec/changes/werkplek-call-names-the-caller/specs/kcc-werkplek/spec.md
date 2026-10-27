## ADDED Requirements

### Requirement: The werkplek in a call names the caller

While a call is on, the werkplek MUST be titled "Gesprek met <client name>". It MUST show a card on the client in the call with their name, type and address, source, preference and language.

#### Scenario: Sanne calls
- GIVEN the werkplek opened for a call with Sanne de Vries
- THEN the title reads "Gesprek met Sanne de Vries"
- AND "Wie is het" names Sanne de Vries with a link to her customer page

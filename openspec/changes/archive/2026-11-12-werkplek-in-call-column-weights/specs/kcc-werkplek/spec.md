## ADDED Requirements

### Requirement: The in-call columns are drawn at the board's widths

In a call the contact centre page SHALL draw its three columns as board PqWerkplekKlant does: 332px, 415px and 332px wide on the 1120px content width. Outside a call the page SHALL keep its twelve equal shares.

#### Scenario: A call is on
- **GIVEN** the simple profile's contact centre page while `callActive` holds
- **THEN** "Wie is het" is 332px wide, "Dit contactmoment" and "Openstaande zaken" 415px, the knowledge base, tasks and earlier contact 332px

#### Scenario: No call
- **GIVEN** the same page without a call
- **THEN** its cards keep the twelve shares

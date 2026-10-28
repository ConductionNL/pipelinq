# leads-list-board-line Delta

## ADDED Requirements

### Requirement: The leads list is the board

The leads list SHALL open filtered on the default pipeline as a removable filter, SHALL name the open pipeline's value
and the weighted forecast on its count line, SHALL show each lead's client and source under its title, and SHALL count
its Verouderd lens.

#### Scenario: PqLeads

- **WHEN** a user opens Leads
- **THEN** the Active line shows "Pijplijn: Verkoop", the count line reads "… open leads · € … in de pijplijn, € …
  gewogen · winkans eerst", and "Woonportaal voor huurders" reads "Woningcorporatie Drechtwonen · via het webformulier"

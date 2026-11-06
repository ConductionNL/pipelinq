# leads-list-board-line Specification

## Purpose
The leads list as the board draws it, with the weighted forecast that follows the rows in the list.

## Requirements

### Requirement: The leads list is the board

The leads list SHALL open filtered on the default pipeline as a removable filter, SHALL name the open pipeline's value
and the weighted forecast on its count line, SHALL show each lead's client and source under its title, and SHALL count
its Verouderd lens.

#### Scenario: PqLeads

- **WHEN** a user opens Leads
- **THEN** the Active line shows "Pijplijn: Verkoop", the count line reads "… open leads · € … in de pijplijn, € …
  gewogen · winkans eerst", and "Woonportaal voor huurders" reads "Woningcorporatie Drechtwonen · via het webformulier"

### Requirement: The weighted forecast follows the list

`GET /api/analytics/commercial` SHALL compute the open pipeline value and the weighted forecast over the leads that match
its `filter[<field>]=<value>` parameters (a list value matches any of its values), and over every lead without one.

#### Scenario: The sales pipeline

- **GIVEN** open leads in the sales pipeline worth 1000 at 50% and 4000 at 25%, and one in another pipeline
- **WHEN** the leads list asks with `filter[pipeline]=sales`
- **THEN** the weighted forecast is 1500 and the open pipeline value 5000

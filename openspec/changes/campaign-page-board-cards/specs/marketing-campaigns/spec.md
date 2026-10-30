# campaign-page-board-cards Delta: marketing-campaigns

## ADDED Requirements

### Requirement: The campaign page cards read as the board

On a campaign's page the details card SHALL write the status as a pill in the campaigns list tones, the start and end
dates with day, short month and year, and the budget in whole euros. The landing page SHALL be a titled card whether or
not the campaign has a landing page. The report card SHALL keep its five tiles in one row where they fit, name the
attribution model beside its picker, write its period in short dates with the year once, and frame the channel table
at 8px.

#### Scenario: A running campaign

- **GIVEN** a running campaign from 28 September to 11 October 2026 with a budget of 2500
- **WHEN** the reader opens the campaign
- **THEN** the details card SHALL show a green "Bezig" pill, "28 sep 2026", "11 okt 2026" and "€ 2.500"

#### Scenario: A campaign without a landing page

- **GIVEN** a campaign without a landing page
- **WHEN** the reader opens the campaign
- **THEN** the landing page block SHALL sit in a card titled "Landingspagina"

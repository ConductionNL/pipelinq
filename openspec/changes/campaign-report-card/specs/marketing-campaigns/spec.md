# campaign-report-card Delta: marketing-campaigns

## ADDED Requirements

### Requirement: The campaign page draws its report as a card

On a campaign's page the campaign report SHALL render as a card of its own, with the tiles and the channel table inside
its padding. A tile SHALL widen to fit its figure rather than clip it.

#### Scenario: A campaign without recorded costs

- **GIVEN** a campaign with no recorded costs
- **WHEN** the reader opens the campaign
- **THEN** the report card SHALL show the Kosten tile reading "Niet vastgelegd" in full

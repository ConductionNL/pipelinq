# dashboard-widgets Delta: sales-overview-subtitles-fit

## ADDED Requirements

### Requirement: The sales overview cards carry the board subtitles and end at their content

The sales overview SHALL show the subtitle "Won deals" under the revenue per month, revenue by source and largest
customers titles, and every card but the revenue chart SHALL end at its content with the cards under it moving up.

#### Scenario: Board PqVerkoopoverzicht

- **WHEN** a sales lead opens the sales overview
- **THEN** "Revenue per month" reads "Won deals" under its title
- **AND** "Closing soon" starts right under the revenue chart, and "Recent won / lost" right under the open pipeline

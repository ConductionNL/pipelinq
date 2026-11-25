## ADDED Requirements

### Requirement: The forecast shows last quarter's accuracy per seller

The forecast page SHALL show, per seller, the commit of the previous quarter's first snapshot, that quarter's
closed-won amount, and closed-won divided by that commit as a percentage. A seller with a single snapshot or no
first-week commit SHALL have no row.

#### Scenario: Q3 closed

- **GIVEN** Pieter's Q3 snapshots with a first commit of € 52.000 and a closed-won of € 49.500
- **WHEN** the forecast opens in Q4
- **THEN** the accuracy table SHALL show Pieter Jansen with € 52.000, € 49.500 and 95%

# dashboard Delta

## ADDED Requirements

### Requirement: The sales overview says what its figures are made of

Every KPI tile of the sales overview SHALL carry a caption from the commercial overview, the closing and closed deal
lists SHALL keep their meta line when a part is empty, and a closed deal SHALL show its amount as a pill toned won or
lost.

#### Scenario: Board PqVerkoopoverzicht

- **WHEN** one deal was won and one lost in the window
- **THEN** the win rate tile reads "1 gewonnen, 1 verloren" under its figure
- **AND** the won deal shows its amount in a green pill and the lost deal in a red one

### Requirement: The sales overview draws cover, won against open and matching prospects

The sales overview SHALL draw the pipeline cover as a multiple, won against open as two figures with the share, and
the two best matching prospects with their fit and a link to the prospects page.

#### Scenario: No pipeline target

- **WHEN** no pipeline target is set
- **THEN** the pipeline cover card reads "Stel een pijplijndoel in"

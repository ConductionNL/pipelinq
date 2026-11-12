# Board card and table heads Delta

**Spec refs**: `board-card-and-table-heads`

## ADDED Requirements

### Requirement: The app's own cards and tables draw the board card title and card table head

A card title the app draws itself SHALL be 17px at 700 on the font's normal line height. A table head the app draws
itself inside a card SHALL be 14px at 600 in the muted text colour on the board's table head ground, with no rule under
the head. A dashboard row list that the board draws with a rule above its first row SHALL set `ruleAbove`.

#### Scenario: PqZoekinzicht card titles

- **GIVEN** the search insight page in the board look
- **THEN** "Zoekopdrachten", "Trefwoorden", "Concurrenten" and "Verbindingscontrole" are 17px/700 on a 20.4px line

#### Scenario: PqZRapport bookkeeping table head

- **GIVEN** a Z-report in the board look
- **THEN** the Boekhouding table heads are 14px/600, muted, on the board's head ground, with no rule under them

#### Scenario: PqVerkoopoverzicht "Sluit binnenkort"

- **GIVEN** the sales dashboard
- **THEN** the list draws a rule above its first row

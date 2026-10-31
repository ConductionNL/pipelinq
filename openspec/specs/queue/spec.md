# queue Specification

## Purpose
The queue in the simple profile is worked from the top: rows without column heads, a tile counting the tickets still within their deadline, and Take it on and View in each row.

## Requirements

### Requirement: The queue list draws rows without column heads

In the simple profile the queue SHALL draw its rows without column heads, and SHALL keep its kind lenses above the list
card with the channel strip under them, as DqTeamwachtrij draws its lenses.

#### Scenario: Board PqWachtrij

- **WHEN** an agent opens the queue
- **THEN** the lenses "Alle · Verzoeken · Klachten · Contactmomenten" sit above the list card, the channel strip under them
- **AND** the card holds rows without column heads

### Requirement: The queue counts the tickets within their deadline

In the simple profile the queue's third tile SHALL read "Within the deadline" with the open unassigned tickets not past
their deadline out of all open unassigned tickets, captioned "take the red ones first".

#### Scenario: Board PqWachtrij

- **WHEN** 12 tickets wait and 3 are past their deadline
- **THEN** the tiles read Niet toegewezen 12, Langst wachtend, Binnen de termijn "9 van 12" with "pak de rode eerst"

### Requirement: The queue list picks rows up in the row

In the simple profile the queue SHALL show Take it on and View in each row, View as an eye, with no row menu, and SHALL
offer no search field, no add button and no column filters.

#### Scenario: Board PqWachtrij

- **WHEN** an agent opens the queue
- **THEN** each row ends in "In behandeling nemen" and an eye named "Bekijken"
- **AND** the list has no search field and no "Ticket toevoegen"

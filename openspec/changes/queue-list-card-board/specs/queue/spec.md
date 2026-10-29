# queue Delta

## ADDED Requirements

### Requirement: The queue list card opens with its lenses

In the simple profile the queue SHALL draw its kind lenses as a segmented control at the top of the list card, with
"Kind, then channel" beside them and the channel strip under them, and SHALL draw its rows without column heads.

#### Scenario: Board PqWachtrij

- **WHEN** an agent opens the queue
- **THEN** the list card opens with "Alle · Verzoeken · Klachten · Contactmomenten" and "Soort, dan kanaal"
- **AND** the channel strip under them, then rows without column heads

### Requirement: The queue counts the tickets within their deadline

In the simple profile the queue's third tile SHALL read "Within the deadline" with the open unassigned tickets not past
their deadline out of all open unassigned tickets, captioned "take the red ones first".

#### Scenario: Board PqWachtrij

- **WHEN** 12 tickets wait and 3 are past their deadline
- **THEN** the tiles read Niet toegewezen 12, Langst wachtend, Binnen de termijn "9 van 12" with "pak de rode eerst"

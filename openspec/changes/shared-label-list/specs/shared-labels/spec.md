## ADDED Requirements

### Requirement: Labels are one shared list for articles, leads and customers

Pipelinq MUST keep its labels as `itemLabel` objects in its register, each with a unique `name` (case-insensitive). The article, lead and client forms MUST offer that list when a user labels an item, and MUST NOT accept a value that is not on it. An item MUST store the label's name.

#### Scenario: A colleague labels a lead
- **GIVEN** the labels afval, duurzaam, parkeren, ondernemers and evenementen
- **WHEN** Pieter labels a lead
- **THEN** he picks from those five, and cannot type "ondernemer"

### Requirement: An administrator manages the labels on Beheer, Marketing

The Labels card MUST list the labels, add one, rename one on double-click, and delete one. Only an administrator MUST be able to change the list; OpenRegister MUST refuse the write for anyone else.

#### Scenario: A deleted label stays on its items
- **GIVEN** three articles labelled "parkeren"
- **WHEN** the administrator deletes "parkeren"
- **THEN** the list no longer offers it
- **AND** the three articles still show "parkeren"

#### Scenario: A renamed label follows its items
- **GIVEN** five leads labelled "ondernemers"
- **WHEN** the administrator renames it to "ondernemers centrum"
- **THEN** the five leads carry "ondernemers centrum"

# pos-history-in-words Specification

## Purpose
The side history card on a POS receipt and a cash shift, which names each event in words instead of the raw audit action.

## Requirements

### Requirement: The POS side history says what happened in words

The side History card of a POS receipt and of a cash shift SHALL name each event in words, not by the raw audit
action. A status change SHALL read as the state it reached. The cards SHALL be drawn stacked and compact.

#### Scenario: Board PqKassabon

- **WHEN** a user opens a settled receipt
- **THEN** its side History reads "Afgerekend", "Bevestigd" and "Aangemaakt door Pieter Jansen"

#### Scenario: Board PqKassadienst

- **WHEN** a user opens a counted shift
- **THEN** its side History reads "Gesloten en geteld" and "Geopend door Joris Bakker"

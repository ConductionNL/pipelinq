# Party fields and indicators (delta)

## ADDED Requirements

### Requirement: A party's indicators come loudest first

The party leaf SHALL list indicators critical first, then warning, then information, and by label within a severity.

#### Scenario: a mixed list
- GIVEN a party with an information note, two warnings and a critical flag
- THEN the critical flag is listed first and the note last

### Requirement: A party page shows its warnings and quick actions in one band

ClientDetail and ContactDetail SHALL show, before the body, the party's warnings (severity, period, source, acknowledgement), a way to add a warning, and Call, Call from my extension, Mail and Directions. When the warnings cannot be read the band SHALL say so instead of saying there are none.

#### Scenario: the warnings cannot be read
- GIVEN the party leaf fails
- THEN the band reads "The list of warnings could not be loaded."

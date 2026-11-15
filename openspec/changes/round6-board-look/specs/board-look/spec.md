## ADDED Requirements

### Requirement: A list reads as its board (REQ-BL-001)
Every index page the app has a board for MUST show the board header and the board's column order, with Dutch labels for every text the reader sees, including the quick filter labels, the count line, the footer note, the header button labels and enum values such as a status.

#### Scenario: A Dutch reader opens a list
@e2e exclude Asserted in tests/vitest/structureProfile.spec.js on the built manifest; the e2e instance does not run in Dutch.
- **GIVEN** the simple structure and a reader whose language is Dutch
- **WHEN** the reader opens a list that has a board
- **THEN** the page MUST print its quick filter labels, count line and header buttons in Dutch
- **AND** an enum value in a column MUST print as its Dutch word, not as the stored key

#### Scenario: An enum word without a translation
@e2e exclude Asserted in tests/vitest/leadScoreBadge.spec.js: the formatter returns the capitalised key when the catalogue has no entry.
- **GIVEN** an enum value the catalogue does not hold
- **WHEN** the column is formatted
- **THEN** the value MUST print capitalised, never empty

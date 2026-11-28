# board-look Specification

## Purpose

The drawn boards on identity.conduction.nl/screens are the UI canon. Round 6 brings every built list of Pipelinq to its board: the board look switch, the board header on every list, Dutch status words, board column order and the leads list with a win-chance bar.

## Requirements

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

### Requirement: An Advanced page links to everything that sets the app up
The app MUST have a page at /advanced that shows link cards grouped as Reports and automation, Set up the app, About the app and Administrator, as board PqGeavanceerdHub draws them. A card whose page does not exist, or that the reader may not open, MUST NOT show.

#### Scenario: A reader opens Advanced
@e2e exclude Asserted in tests/vitest/advancedAndConnectionAudit.spec.js on the manifest; the pixel check against PqGeavanceerdHub covers the drawn page.
- **GIVEN** a signed-in reader
- **WHEN** they open /advanced
- **THEN** they MUST see the cards Reports, Flows, Pipelines, Services and resources, BI export, Modules, Store, Features and roadmap and Documentation under their captions

### Requirement: The connection audit reads as its board
The connection audit MUST be an index over the stored `socialConnection` rows with four counts (checked, we do not follow, they do not follow, cannot be checked), the matching quick filters, and the columns client, handle, network, we follow them, they follow us and reason. A follow answer that is not yes or no MUST print as Cannot be checked, never as No. Check again MUST re-run the audit.

#### Scenario: A network that will not say
@e2e exclude Asserted in tests/vitest/advancedAndConnectionAudit.spec.js on the formatter.
- **GIVEN** a row whose weFollowThem is unknown
- **WHEN** the list shows it
- **THEN** the cell MUST read Cannot be checked and the reason column MUST show why

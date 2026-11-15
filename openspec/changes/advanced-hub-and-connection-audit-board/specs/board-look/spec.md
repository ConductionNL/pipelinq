## ADDED Requirements

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

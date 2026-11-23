# features-roadmap Delta

## MODIFIED Requirements

### Requirement: The page MUST link to the comparison on the public site

The features page SHALL link to the comparison on the public site in the reader's language, opening in a new tab,
through the library page's compare link, so it takes the library's board look.

#### Scenario: The comparison is one link away

- **GIVEN** a user opens `/features-roadmap`
- **WHEN** the page loads
- **THEN** the page MUST NOT render the comparison tables or its caveats
- **AND** a link reading "How pipelinq compares to other help desks" MUST point at `/compare` on the docs site
- **AND** the link MUST open in a new tab

#### Scenario: PqRoadmap

- **THEN** one "Hoe pipelinq zich verhoudt tot andere helpdesks" link stands above the page title, opening
  pipelinq.conduction.nl/nl/compare in a new tab

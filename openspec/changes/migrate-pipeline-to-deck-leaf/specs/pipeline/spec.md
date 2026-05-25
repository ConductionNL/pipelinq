# Spec delta — migrate-pipeline-to-deck-leaf

## ADDED Requirements

### Requirement: The kanban board is provided by the Deck leaf

Pipelinq SHALL NOT ship a bespoke kanban board; board mechanics (columns, cards,
drag-and-drop, board CRUD) SHALL be provided by the OpenRegister deck leaf
(`integration-deck`) wrapping the NC Deck app (hydra ADR-022).

#### Scenario: Bespoke board is removed

- **GIVEN** the migrate-pipeline-to-deck-leaf change is applied
- **THEN** `src/views/pipeline/PipelineBoard.vue` and its bespoke board
  mechanics SHALL be removed
- **AND** board structure SHALL be realised as Deck boards/stacks/cards via the
  deck leaf.

#### Scenario: Pipeline maps to Deck constructs

- **GIVEN** a Pipelinq pipeline with ordered stages and lead/request cards
- **WHEN** the migration is applied
- **THEN** the pipeline SHALL map to a Deck board, each stage to a Deck stack,
  and each lead/request to a Deck card created via the leaf's inline-create flow.

### Requirement: lead and request expose the deck leaf

The `lead` and `request` schemas SHALL declare `deck` in `linkedTypes` so the
leaf's tab and mini-kanban widget appear on those objects.

#### Scenario: Deck tab and widget appear on leads and requests

- **GIVEN** the NC `deck` app is installed and the deck leaf is registered
- **WHEN** a user opens a `lead` or `request` detail page
- **THEN** the leaf's `CnDeckTab` SHALL be available in the sidebar (create
  inline / link existing / unlink)
- **AND** the `CnDeckCard` mini-kanban widget SHALL show the card's stack
  position.

### Requirement: Deck leaf is placed via the app manifest

The deck leaf's tab and widget SHALL be surfaced through `src/manifest.json`
(ADR-024), and `deck` SHALL be declared as a dependency.

#### Scenario: Manifest places tab/widget and declares dependency

- **GIVEN** Pipelinq's `src/manifest.json`
- **THEN** the lead/request detail pages' `sidebar` config SHALL include the
  deck leaf tab
- **AND** detail pages (and optionally the dashboard) MAY include the
  `CnDeckCard` mini-kanban widget
- **AND** `dependencies[]` SHALL include `deck`.

### Requirement: CRM stage rules are kept as leaf-adjacent declarative logic

CRM-specific stage semantics SHALL be preserved as a thin pipeline-config object
plus declarative business logic, NOT a parallel board engine (ADR-031).

#### Scenario: Win/closed/probability/default rules survive on top of Deck

- **GIVEN** a thin pipeline-config object holding `probability`, `isWon`,
  `isClosed`, and `isDefault`-per-entity
- **WHEN** a card is moved into a stack mapped to a "won" stage
- **THEN** a declarative rule SHALL update the linked lead/request lifecycle
  status (e.g. `status = won`)
- **AND** no bespoke board-engine code SHALL re-implement Deck's column/card
  mechanics.

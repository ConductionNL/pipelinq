# Tasks: migrate-pipeline-to-deck-leaf

## 0. Leaf check

- [ ] 0.1 Confirm the OpenRegister `integration-deck` leaf is shipped (DeckProvider + CnDeckTab inline-create + CnDeckCard mini-kanban + `openregister_deck_links`).
  - **acceptance_criteria**:
    - GIVEN `openregister/openspec/changes/integration-deck/`
    - THEN document the leaf key `deck` and required NC app `deck`.

## 1. Remove bespoke board

- [ ] 1.1 Remove `src/views/pipeline/PipelineBoard.vue` and bespoke board store/CRUD.
  - **spec_ref**: `specs/pipeline/spec.md#Requirement: The kanban board is provided by the Deck leaf`
  - **files**: `pipelinq/src/views/pipeline/PipelineBoard.vue`, related store/router entries
  - **acceptance_criteria**:
    - GIVEN the applied change
    - THEN no bespoke board mechanics (columns/cards/drag-drop/CRUD UI) remain.

## 2. Schema glue

- [ ] 2.1 Add `deck` to `linkedTypes` on `lead` and `request`; reduce `pipeline`/stage schema to CRM stage-rule config.
  - **spec_ref**: `specs/pipeline/spec.md#Requirement: lead and request expose the deck leaf`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register file
    - THEN `lead` and `request` list `deck` in `linkedTypes`
    - AND the pipeline-config object retains only `probability`/`isWon`/`isClosed`/`isDefault` rules.

## 3. Manifest placement (ADR-024)

- [ ] 3.1 Place `CnDeckTab` in lead/request detail sidebars and `CnDeckCard` mini-kanban widget; declare `deck` dependency.
  - **spec_ref**: `specs/pipeline/spec.md#Requirement: Deck leaf is placed via the app manifest`
  - **files**: `pipelinq/src/manifest.json`
  - **acceptance_criteria**:
    - GIVEN the manifest
    - THEN lead/request detail pages include the deck tab; detail pages (optionally dashboard) include the mini-kanban widget; `dependencies[]` includes `deck`.

## 4. CRM stage rules (ADR-031)

- [ ] 4.1 Map "card in won stack" → lead/request lifecycle status via declarative rule.
  - **spec_ref**: `specs/pipeline/spec.md#Requirement: CRM stage rules are kept as leaf-adjacent declarative logic`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json` (x-openregister-lifecycle/relations)
  - **acceptance_criteria**:
    - GIVEN a card moved into a won-mapped stack
    - THEN the linked lead/request `status` updates declaratively; no board engine re-implemented.

## 5. Verification

- [ ] 5.1 `npm run build` and `npm run check:manifest` pass.
- [ ] 5.2 Register imports cleanly via `ConfigurationService::importFromApp()`.
- [ ] 5.3 Browser check: with NC `deck` + leaf installed, open a lead detail; deck tab creates a card on a board/stack; mini-kanban widget shows position.
- [ ] 5.4 Confirm `PipelineBoard.vue` and bespoke board mechanics are gone.

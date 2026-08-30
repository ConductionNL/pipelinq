# Proposal: migrate-pipeline-to-deck-leaf

## Why

Pipelinq ships a bespoke kanban board — `src/views/pipeline/PipelineBoard.vue`
(930 LOC) — backed by app-owned `pipeline` and stage schemas. It re-implements
columns, cards, drag-and-drop, and board CRUD that the Nextcloud **Deck** app
already provides, and that OpenRegister now exposes as the **deck leaf**
(`integration-deck`).

Per hydra ADR-022, an app must consume an OR abstraction rather than reinvent
it. The pipeline spec itself even notes the equivalence: "A pipeline is
comparable to Trello boards or Nextcloud Deck — each pipeline has columns
(stages) and cards (leads/requests)." The deck leaf ships `DeckProvider` +
`CnDeckTab` (list linked cards, **create new card inline** with board/stack
selection, link existing, unlink) + `CnDeckCard` widget on all four surfaces,
including a **mini-kanban view** for detail pages showing stack position. Its
key capability is creating cards from OR, not just linking — exactly the
pipeline use case.

What is genuinely pipelinq-specific is the **CRM semantics layered on stages**:
win/closed flags, per-stage probability/scoring, default-pipeline-per-entity
rules, and lead/request-on-the-same-board logic. Those stay as leaf-adjacent
logic; the board mechanics move to Deck.

## What Changes

### Replace the bespoke kanban with the deck leaf

1. **Remove `PipelineBoard.vue`** and the bespoke board mechanics (columns,
   cards, drag-and-drop, board CRUD UI). The Deck app + leaf own those.
2. **Map pipeline → Deck board, stage → Deck stack, deal/lead card → Deck card.**
   A Pipelinq pipeline is realised as a Deck board; each stage a stack; each
   lead/request a Deck card created via the leaf's inline-create flow.
3. **Add `deck` to `linkedTypes`** on the `lead` and `request` schemas so the
   leaf's tab + mini-kanban widget appear on those objects.
4. **Place the leaf via the manifest (ADR-024).** The `CnDeckTab` mounts in the
   lead/request detail sidebars; the `CnDeckCard` mini-kanban widget renders on
   detail pages and (optionally) the dashboard. The full board view is reached
   through Deck.
5. **Declare the `deck` dependency** in `src/manifest.json` `dependencies[]`.

### Keep CRM-specific stage rules as leaf-adjacent logic

6. **Retain stage scoring/rules** (`probability`, `isWon`, `isClosed`,
   `isDefault`-per-entity) as a small pipelinq concern that maps OR object state
   onto Deck stack membership — e.g. moving a card to a "won" stack updates the
   lead/request lifecycle status. This is declarative business logic (ADR-031),
   not a parallel board engine.

## Out of Scope

- Board/column/card UI + drag-and-drop — owned by Deck + the leaf.
- Deep kanban editing — lives in Deck.
- Modifying the Deck app itself.

## Impact

- **Removed**: `src/views/pipeline/PipelineBoard.vue` + bespoke board store/CRUD.
- **Modified schemas**: `lead`, `request` gain `deck` in `linkedTypes`. The
  bespoke `pipeline`/stage schemas are reduced to CRM stage-rule config (or
  retired in favour of Deck board/stack identity — decided in design.md).
- **Modified files**: `src/manifest.json` (tab/widget placement + `deck`
  dependency), `lib/Settings/pipelinq_register.json`.
- **Dependency**: OpenRegister `integration-deck` leaf shipped; NC `deck` app
  installed.
- **Risk**: Medium — board → Deck mapping changes the primary CRM workflow
  surface; CRM stage semantics must be preserved on top of Deck stacks.

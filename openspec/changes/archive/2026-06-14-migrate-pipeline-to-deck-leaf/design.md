# Design: migrate-pipeline-to-deck-leaf

**status: pr-created**

## Architecture

The bespoke board is replaced by the OpenRegister **deck leaf**
(`integration-deck`), which wraps the NC Deck app.

```
Pipelinq pipeline      →  Deck board
Pipelinq stage         →  Deck stack
lead / request card    →  Deck card (created via CnDeckTab inline-create)
```

The leaf provides:
- `DeckProvider` (registered in the integration registry).
- `CnDeckTab` — list linked cards, create new card inline (board + stack
  selection), link existing, unlink.
- `CnDeckCard` widget — 4 surfaces, including a **mini-kanban** detail-page view
  showing the card's stack position.
- Link table `openregister_deck_links`.

## Board/stack identity decision

Two options for the bespoke `pipeline`/stage schemas:

- **(A) Retire them** — Deck boards/stacks become the source of truth for board
  structure; the lead/request links to its Deck card via the leaf link table.
- **(B) Keep a thin `pipeline` config object** that maps a pipeline to a Deck
  board id and stores the CRM stage rules (probability/won/closed/default), with
  Deck owning the live board state.

**Decision: (B).** Deck has no concept of per-stage win-probability or
"closed/won" CRM semantics, and Pipelinq needs default-pipeline-per-entity and
lead+request-on-one-board rules. Keeping a thin config object preserves those as
declarative business logic (ADR-031) without re-implementing the board. The
bespoke board *mechanics* (columns/cards/drag-drop/CRUD UI) are removed; only
the stage-rule config survives, mapped onto Deck stacks.

## What Pipelinq owns after migration

1. `linkedTypes: ["deck", ...]` on `lead` and `request`.
2. Manifest placement (ADR-024): `CnDeckTab` in lead/request detail sidebars;
   `CnDeckCard` mini-kanban widget on detail pages (+ optional dashboard).
3. `deck` in manifest `dependencies[]`.
4. A thin stage-rules config (probability/won/closed/default-per-entity) and the
   declarative rule that moving a card to a "won" stack updates the
   lead/request lifecycle status.

## Removed

| Bespoke artefact | Replaced by |
|---|---|
| `src/views/pipeline/PipelineBoard.vue` (930 LOC) | Deck app + `CnDeckTab` / `CnDeckCard` |
| board columns / cards / drag-drop / board CRUD UI | Deck + leaf inline-create / link / unlink |
| stage-as-board-engine schema fields | Deck stack identity (rules kept as config) |

## CRM stage rules as leaf-adjacent logic (ADR-031)

- `probability` (0–100), `isWon`, `isClosed`, `isDefault`-per-entity stay on a
  thin pipeline-config object.
- A declarative `x-openregister-lifecycle`/relation maps "card in won stack" →
  lead/request `status = won`. This is config, not a board engine.

## Risks

- Medium. The primary CRM workflow surface moves to Deck; users navigate to Deck
  (or the mini-kanban widget) instead of the in-app board. Mitigated by the
  detail-page mini-kanban widget keeping context in Pipelinq.
- Deck stacks must mirror the CRM stage set; an initial sync/seed maps existing
  stages to stacks. The leaf's create-card flow handles new cards.

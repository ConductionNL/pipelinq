---
kind: code
depends_on: []
---

# Proposal: pipeline-stage-limit

## Summary

Give a pipeline stage a limit, say eight deals in Proposal, and see on the board
when a stage is full. A move into a full stage is refused with a message that
says why, on the board and in the edit form alike. Today a stage takes any
number of items and a refused drop disappears without a word.

## Motivation

One row of the pipelinq capability matrix (`openspec/parity/capabilities.json`,
compared 2026-09-24), decided `build` by the OpenSpec pass of 2026-09-27. It is
in the core area of the matrix (pipeline).

**`pipeline-stage-limit`**, "Cap how many items may sit in one stage at a time".
Rated no, built.state none. Matrix evidence: "a pipeline stage carries name,
order, deckStackId, probability, isClosed, isWon, isDefault
(lib/Settings/pipelinq_register.json:886-935) and no limit;
src/views/pipeline/PipelineBoard.vue enforces none". Demand: feature request,
https://github.com/odoo/odoo/issues/141745. No competitor rates it yes: espocrm,
odoo-crm and kiss no, hubspot-crm and pipedrive unknown. Decided on the core
area rule.

## What changes

- A stage in the pipeline editor gets an optional Limit.
- The board column header shows the count against the limit, and a full column
  looks full.
- pipelinq refuses, on the server, any create or update that would put one item
  more into a full stage, whatever screen the write came from.
- The board shows the refusal instead of swallowing it.

## Out of scope

- Limits per person or per team inside a stage.
- Closed stages (won, lost): a limit there would block closing a deal, so the
  editor does not offer one on a closed stage.

## Impact

- `pipeline.stages[]` gains `maxItems`.
- New `lib/Listener/StageLimitGuard.php` on OpenRegister's `ObjectCreatingEvent`
  and `ObjectUpdatingEvent`.
- `src/views/pipeline/PipelineBoard.vue` (header count, refusal message) and
  `src/dialogs/PipelineFormDialog.vue` (Limit field).

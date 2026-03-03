# Proposal: pipeline-enhancements

## Summary

Add two remaining MVP pipeline features: a list view toggle (alternative to kanban) and quick actions on pipeline cards (move stage, assign without opening detail).

## Motivation

The pipeline kanban board is built but users need a data-dense list view for large pipelines, and quick actions on cards to change stages or assign items without navigating away from the board.

## Affected Projects
- [x] Project: `pipelinq` — Enhance PipelineBoard.vue and PipelineCard.vue

## Scope
### In Scope
- Pipeline view toggle: kanban board vs list table (Feature #18)
- Quick actions on cards: move to next/previous stage, assign user (Feature #19)

### Out of Scope
- Pipeline analytics, funnel charts (V1)
- Stage probability mapping (V1)

## Approach

Add a view mode toggle (kanban/list) to PipelineBoard.vue header. List mode renders items in a sortable table with stage, title, assignee, value, and date columns. Quick actions added as dropdown menus on PipelineCard.vue — stage selector and assignee selector, stopping click propagation so they don't open the detail view.

## Cross-Project Dependencies
None.

## Rollback Strategy
Changes limited to PipelineBoard.vue and PipelineCard.vue. Easy to revert.

## Open Questions
None.

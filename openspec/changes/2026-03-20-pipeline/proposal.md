# Proposal: pipeline search and stage validation

## Problem

The pipeline spec identifies MVP gaps:
1. REQ-PIPE-022: No search/filter controls on the pipeline kanban/list view
2. REQ-PIPE-005: No stage validation (isWon requires isClosed, probability range, etc.)

## Proposed Change

- Add a search bar to the pipeline header that filters kanban cards and list items by title
- Add stage validation rules in PipelineForm.vue

### Out of Scope
- Pipeline analytics (V1)
- Funnel visualization (V1)
- Pipeline templates (Enterprise)
- Stage automation (Enterprise)
- Pipeline access control (V1)
- View persistence (V1)

## Impact
- **Files modified**: 2 Vue files (PipelineBoard.vue, PipelineForm.vue)
- **Risk**: Low

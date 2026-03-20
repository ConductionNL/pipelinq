# Delta Spec: pipeline search and stage validation

## Changes to specs/pipeline/spec.md

### Newly Implemented

- **REQ-PIPE-022 (Pipeline Search)**: Search input added to pipeline board header. Filters kanban cards and list items case-insensitively by title. Column counts and values update to reflect filtered results.
- **REQ-PIPE-005 Scenario 24 (Probability range)**: Stage probability validation added (must be 0-100 if provided).

### Corrections to Previous Assessment

The following items were previously listed as "NOT implemented" but were already present:
- **REQ-PIPE-005 (Stage Validation)**: isWon requires isClosed, stage name required, at least one non-closed stage -- all implemented in PipelineForm.vue's `stageErrors` computed property.

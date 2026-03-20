# Tasks: pipeline search and stage validation

## 1. Pipeline Search

- [ ] 1.1 Add search input to PipelineBoard header
  - **spec_ref**: `specs/pipeline/spec.md#REQ-PIPE-022`
  - **files**: `pipelinq/src/views/pipeline/PipelineBoard.vue`
  - **acceptance_criteria**:
    - GIVEN items on the pipeline board
    - WHEN user types in the search box
    - THEN only items matching the search term (case-insensitive title match) MUST be shown
    - AND column counts and values MUST update to reflect filtered results

## 2. Stage Validation

- [ ] 2.1 Add validation rules to PipelineForm.vue
  - **spec_ref**: `specs/pipeline/spec.md#REQ-PIPE-005`
  - **files**: `pipelinq/src/views/settings/PipelineForm.vue`
  - **acceptance_criteria**:
    - GIVEN a stage with isWon=true and isClosed=false
    - THEN validation MUST reject with error
    - AND probability MUST be 0-100 if provided

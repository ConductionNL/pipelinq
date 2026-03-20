# Design: pipeline search and stage validation

## Architecture Overview

Frontend-only. Search filters the existing in-memory items array. Stage validation is client-side before save.

## Key Design Decisions

### 1. Pipeline Search Bar
Add an NcTextField search input in the pipeline header. Filter items case-insensitively by title. Update kanban column counts and values to reflect filtered results.

### 2. Stage Validation
In PipelineForm.vue, validate:
- isWon requires isClosed to be true
- Probability must be 0-100 if provided
- At least one non-closed stage must exist

# Tasks: pipeline-insights overdue exclusion and stale threshold

## 1. Closed item overdue exclusion
- [ ] 1.1 Fix isItemOverdue() to check stage.isClosed and request terminal statuses
  - **spec_ref**: `specs/pipeline-insights/spec.md#Closed/terminal items are not overdue`
  - **files**: `pipelinq/src/views/pipeline/PipelineBoard.vue`

## 2. Request overdue calculation
- [ ] 2.1 Apply 30-day threshold for request overdue with status check
  - **spec_ref**: `specs/pipeline-insights/spec.md#Overdue request calculation`
  - **files**: `pipelinq/src/views/pipeline/PipelineBoard.vue`

## 3. Stale threshold configurability
- [ ] 3.1 Add threshold parameter to isStale() in pipelineUtils.js
  - **spec_ref**: `specs/pipeline-insights/spec.md#Stale threshold configurability`
  - **files**: `pipelinq/src/services/pipelineUtils.js`

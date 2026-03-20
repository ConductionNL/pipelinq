# Proposal: pipeline-insights overdue exclusion and stale threshold

## Problem

The pipeline-insights spec identifies V1 gaps:
1. `isItemOverdue()` in PipelineBoard.vue does not check `stage.isClosed` — items in closed stages can appear as overdue
2. Stale threshold is hardcoded to 14 days in `pipelineUtils.js` — should be configurable
3. Request overdue logic does not check for terminal statuses (completed, rejected, converted)

## Proposed Change

1. Fix `isItemOverdue()` to check if the item's current stage is closed (isClosed) — closed items are never overdue
2. Also check request terminal statuses in overdue logic
3. Make stale threshold configurable via a parameter (default 14), accept it from pipeline settings
4. Add `staleThreshold` property to pipeline schema for per-pipeline configurability

### Out of Scope
- Pipeline conversion analytics (Enterprise)
- Revenue forecasting (Enterprise)
- Dashboard analytics widgets (Enterprise)
- Pipeline export/reporting (Enterprise)
- Pipeline comparison (Enterprise)

## Impact
- **Files modified**: 3 (PipelineBoard.vue, pipelineUtils.js, PipelineCard.vue)
- **Risk**: Low — corrective fixes to existing logic

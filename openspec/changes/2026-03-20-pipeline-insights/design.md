# Design: pipeline-insights overdue exclusion and stale threshold

## Closed Item Overdue Exclusion

In `isItemOverdue()`:
1. Look up the item's current stage in `sortedStages` by matching `stage.name` to the item's column value
2. If `stage.isClosed === true`, return false immediately
3. For request entities, also check if status is terminal (completed, rejected, converted) — if so, not overdue

## Stale Threshold Configurability

In `pipelineUtils.js`:
- Change `isStale()` to accept a `threshold` parameter (default 14)
- PipelineBoard.vue and PipelineCard.vue pass a configurable threshold
- Pipeline schema gets optional `staleThreshold` property (not added to register since it's a UI concern, stored in pipeline object's stages configuration)

## Request Overdue Logic

The spec says requests are overdue when `requestedAt` is > 30 days ago AND status is new/in_progress. Update `isItemOverdue()` to:
- For requests: check `requestedAt` > 30 days AND status is "new" or "in_progress"
- For leads: check `expectedCloseDate < today`

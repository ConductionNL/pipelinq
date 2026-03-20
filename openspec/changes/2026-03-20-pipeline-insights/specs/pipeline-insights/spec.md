# Delta Spec: pipeline-insights overdue exclusion and stale threshold

## Newly Implemented

- **Closed item overdue exclusion**: `isItemOverdue()` checks if the item's current stage has `isClosed === true`. Closed items are never marked overdue.
- **Request overdue calculation**: Requests use 30-day threshold from `requestedAt` and only flag as overdue when status is "new" or "in_progress". Terminal statuses (completed, rejected, converted) are excluded.
- **Stale threshold configurability**: `isStale()` in `pipelineUtils.js` accepts an optional `threshold` parameter (default 14 days). `getAgingClass()` also accepts a threshold parameter for consistent coloring.

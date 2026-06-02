# Design: Reverse-spec — My Work UI

## Context

The personal work overview screen (`src/views/MyWork.vue`) is already implemented and exercised in the running app. This change retroactively documents the observed behavior of 17 methods as formal REQs and annotates each method with an `@spec` reference pointing at the corresponding task in this change's `tasks.md`.

No code logic changes are made. This is a pure reverse-spec retrofit (ADR-020).

## Affected methods

| Method | Entity type | Task |
|---|---|---|
| `allItems` | computed | task-1 |
| `closedStageNames` | computed | task-2 |
| `computeGroup` | method | task-3 |
| `currentUser` | computed | task-4 |
| `emptyMessage` | computed | task-5 |
| `fetchAll` | method | task-6 |
| `fetchRaw` | method | task-7 |
| `filteredItems` | computed | task-8 |
| `formatDate` | method | task-9 |
| `groupedItems` | computed | task-10 |
| `leadCount` | computed | task-11 |
| `objectStore` | computed | task-12 |
| `openItem` | method | task-13 |
| `pipelineMap` | computed | task-14 |
| `requestCount` | computed | task-15 |
| `totalCount` | computed | task-16 |
| `visibleGroups` | computed | task-17 |

## Requirements captured

Three REQs are documented in `specs/my-work/spec.md`:

1. **Documented operations are available** — all 17 listed methods MUST be present and behave as implemented.
2. **Results derived from current CRM state** — no hard-coded or stubbed responses; derivations reflect live data.
3. **Defensive handling of absent or invalid input** — methods return safe defaults rather than crashing on missing/malformed input.

## Implementation notes

All `@spec openspec/changes/reverse-2026-05-26-fe-mywork-ui/tasks.md#task-N` annotations were applied to the corresponding method docblocks in `src/views/MyWork.vue` as part of this change.

## Status

status: pr-created

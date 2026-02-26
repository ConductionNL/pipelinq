# Design: my-work

## Architecture Overview

My Work is a single Vue component (MyWork.vue) that fetches leads and requests assigned to the current user, computes temporal groups client-side, and renders grouped item cards. It follows the same pattern as Dashboard.vue — direct `fetchRaw` calls to OpenRegister API to avoid overwriting objectStore collections.

```
MyWork.vue
├── Header (title, item counts, filter buttons, show-completed toggle)
├── Overdue group (red highlight, "N days overdue")
├── Due This Week group
├── Upcoming group
└── No Due Date group
```

## API Design

No new API endpoints. Uses existing OpenRegister API with assignee filter:

- `GET /apps/openregister/api/objects/{register}/{lead_schema}?assignee={currentUser}&_limit=200`
- `GET /apps/openregister/api/objects/{register}/{pipeline_schema}?_limit=100`
- `GET /apps/openregister/api/objects/{register}/{request_schema}?assignee={currentUser}&_limit=200`

For the "show completed" toggle, a second fetch without status/stage filtering is needed, or all items are fetched and filtered client-side.

## Database Changes

None.

## Nextcloud Integration

- `OC.currentUser` — current username for assignee filter
- `@nextcloud/vue` — NcButton, NcLoadingIcon for UI

## File Structure

```
src/
  views/
    MyWork.vue         ← new file
  navigation/
    MainMenu.vue       ← add "My Work" nav item
  App.vue              ← add route
```

## Security Considerations

- Read-only view, no write operations
- Only fetches items assigned to the current user

## NL Design System

- Uses CSS variables for all colors
- Overdue highlighting uses var(--color-error)
- Entity badges reuse lead/request color scheme from PipelineCard

## Trade-offs

**Single component vs sub-components**: Keeping it as one component (MyWork.vue ~250 lines) rather than splitting into MyWorkCard + MyWorkGroup. The item card markup is simple enough to inline. Can extract later if needed.

**Client-side grouping**: Temporal grouping computed in the browser. Same trade-off as Dashboard — works well for typical workloads (<200 items per user).

**Request "due date"**: Requests don't have a dedicated due date field. Using `requestedAt` as a proxy — items requested more than 30 days ago with non-terminal status are considered overdue, matching the Dashboard logic.

# Design: dashboard

## Architecture Overview

The dashboard is a single Vue component (Dashboard.vue) that replaces the current placeholder. It fetches leads, requests, and pipelines on mount using the existing object store, computes all metrics client-side, and renders them with Nextcloud Vue components and CSS.

No backend changes are needed. All data comes from the OpenRegister API via the object store's `fetchCollection` action.

```
Dashboard.vue
├── KPI Cards row (4 cards)
├── Charts row
│   ├── Requests by Status (left, CSS bar chart)
│   └── My Work Preview (right)
└── Quick Actions (in header)
```

## API Design

No new API endpoints. The dashboard uses existing endpoints:

- `GET /apps/openregister/api/objects/{register}/{schema}?_limit=500` — fetch all leads
- `GET /apps/openregister/api/objects/{register}/{schema}?_limit=500` — fetch all requests
- `GET /apps/openregister/api/objects/{register}/{schema}?_limit=100` — fetch all pipelines

The dashboard fetches with high limits to get complete counts. The object store's `fetchCollection` handles the actual API calls.

For the "My Work" preview, the dashboard fetches leads/requests with `assignee={currentUser}` filter.

## Database Changes

None. All data already exists in OpenRegister schemas.

## Nextcloud Integration

- `OC.currentUser` — get current username for My Work assignee filter
- `@nextcloud/vue` — NcButton, NcLoadingIcon, NcEmptyContent for UI components
- Pinia stores — objectStore for data access

## File Structure

```
src/
  views/
    Dashboard.vue  ← rebuild (single file, ~300 lines)
```

## Security Considerations

- Dashboard only reads data, no write operations
- All API calls use the existing request token via `_getHeaders()`
- No user input beyond navigation clicks

## NL Design System

- KPI cards use CSS variables for theming (var(--color-primary), var(--color-warning), etc.)
- Status bar chart reuses colors from requestStatus.js service
- All text meets WCAG AA contrast requirements via Nextcloud CSS variables

## Trade-offs

**CSS bar chart vs charting library**: Using plain CSS `<div>` bars instead of Chart.js or similar. Pros: zero dependencies, fast, simple. Cons: no animations, no tooltips. Appropriate for MVP — can swap in a library for V1 funnel chart.

**Client-side aggregation vs server-side**: Fetching all items and computing counts/sums in the browser. Pros: no backend changes, instant filters, offline-capable. Cons: doesn't scale past ~1000 items. Acceptable for MVP — can add a dedicated stats endpoint later if needed.

**Combined dashboard fetching**: All data fetched in parallel on mount. Loading state shown as a single loading indicator over the whole page until all fetches complete. Sections could render independently (progressive loading) but that adds complexity — keeping it simple for MVP.

# Tasks: dashboard

## 1. KPI Cards

- [x] 1.1 Build KPI card sub-component or template section: card with icon, label, value, optional warning style. Use CSS variables for theming.
- [x] 1.2 Compute open leads count (leads in non-closed pipeline stages) and open requests count (status `new` or `in_progress`).
- [x] 1.3 Compute pipeline total value (sum of lead values in non-closed stages, formatted as EUR currency).
- [x] 1.4 Compute overdue items count (leads with past expectedCloseDate in non-closed stages + stale requests). Warning style when > 0.
- [x] 1.5 Handle zero values — all cards show `0` when no data exists.

## 2. Requests by Status Chart

- [x] 2.1 Build a horizontal bar chart using CSS divs showing request count per status. Use colors from requestStatus.js.
- [x] 2.2 Handle empty state — show "No requests yet" message when no requests exist.

## 3. My Work Preview

- [x] 3.1 Fetch leads and requests assigned to the current user (filter by `assignee` = `OC.currentUser`).
- [x] 3.2 Merge, sort (overdue first, then priority, then due date), and display top 5 items with entity type badge, title, stage/status, due date.
- [x] 3.3 Highlight overdue items visually (red text/icon).
- [x] 3.4 Show "No items assigned to you" when empty. Show total count in section header.

## 4. Quick Actions

- [x] 4.1 Add quick action buttons ("New Lead", "New Request", "New Client") in the dashboard header. Each navigates to the respective creation form via `$emit('navigate', ...)`.

## 5. Data Lifecycle

- [x] 5.1 Fetch all data (leads, requests, pipelines) in parallel on mount with loading indicator. Re-fetch when component is mounted again (data refresh on return).
- [x] 5.2 Handle fetch errors gracefully — show error message per section, successful sections still render.

## 6. Layout and Empty State

- [x] 6.1 Build responsive grid layout: KPI row (4 cards), then two-column row (chart left, My Work right), quick actions in header.
- [x] 6.2 Show welcome message and quick actions when no data exists at all (fresh installation).

## 7. Build and Verify

- [x] 7.1 Run `npm run build` and verify no errors.
- [ ] 7.2 Test dashboard with data via API — verify KPI counts, chart bars, My Work items.

## Verification
- [x] All tasks checked off
- [ ] Manual testing against acceptance criteria

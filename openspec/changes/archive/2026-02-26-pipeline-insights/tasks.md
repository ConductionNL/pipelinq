# Tasks: pipeline-insights

## 1. Shared Utilities

- [x] 1.1 Create `src/services/pipelineUtils.js` with `getDaysAge(item)`, `isStale(item, entityType)`, `getAgingClass(days)`, `formatAge(days)` helper functions.

## 2. PipelineCard Enhancements

- [x] 2.1 Add aging indicator badge to PipelineCard footer — show "Xd" with color coding (normal/amber/red) using `getDaysAge()` and `getAgingClass()`.
- [x] 2.2 Add stale badge to PipelineCard header — orange "Stale" pill for leads with 14+ days since modification. Only show for leads, not requests.
- [x] 2.3 Add overdue card styling — red left border on cards where item is overdue. Ensure `isOverdue` computed works for both leads (expectedCloseDate) and requests (requestedAt > 30 days).

## 3. PipelineBoard Enhancements

- [x] 3.1 Enhance stage column header to show total EUR value prominently — format with `toLocaleString('nl-NL')` and show "EUR 0" for empty stages.
- [x] 3.2 Add "Age" column to list view table — show days in stage with color coding.
- [x] 3.3 Add stale indicator to list view — show "Stale" badge next to lead titles for items with 14+ days.
- [x] 3.4 Add overdue row styling in list view — red date text and subtle background tint for overdue items.

## 4. MyWork Enhancements

- [x] 4.1 Enhance overdue group styling in MyWork — red date text, red count badge in group header, subtle red background.
- [x] 4.2 Add stale badge to My Work items — show "Stale" indicator next to lead titles.

## 5. Build and Verify

- [x] 5.1 Run `npm run build` and verify no errors.
- [ ] 5.2 Test pipeline insights via browser.

## Verification
- [ ] All tasks checked off
- [ ] Manual testing against acceptance criteria

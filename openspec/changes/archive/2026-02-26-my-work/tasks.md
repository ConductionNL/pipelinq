# Tasks: my-work

## 1. Data Fetching and Grouping

- [x] 1.1 Create MyWork.vue with data fetching: fetch leads and requests assigned to `OC.currentUser`, plus pipelines for closed stage detection. Use `fetchRaw` pattern (direct API calls).
- [x] 1.2 Compute temporal groups: Overdue (due date in past), Due This Week (today through end of week), Upcoming (after this week), No Due Date. Hide empty groups.
- [x] 1.3 Sort items within each group: priority (urgent > high > normal > low), then due date ascending.

## 2. Header and Filtering

- [x] 2.1 Build header with title, total count, and breakdown ("Leads (N) · Requests (N) — N items total").
- [x] 2.2 Add entity type filter buttons (All / Leads / Requests). Filter updates counts and preserves grouping.
- [x] 2.3 Add "Show completed" toggle that includes closed/terminal items when enabled, with visual distinction.

## 3. Item Cards

- [x] 3.1 Build item card layout: entity badge ([LEAD]/[REQ]), title, stage/status, pipeline name, value (leads only), due date, priority badge.
- [x] 3.2 Overdue highlighting: red visual indicator, "N days overdue" text. "Due today" indicator for items due today.
- [x] 3.3 Click handler navigating to lead-detail or request-detail.

## 4. Navigation and Routing

- [x] 4.1 Add "My Work" item to MainMenu.vue navigation with appropriate icon.
- [x] 4.2 Add MyWork route to App.vue (import, component registration, route case).
- [x] 4.3 Wire dashboard "View all" link to navigate to my-work route.

## 5. Empty State and Loading

- [x] 5.1 Empty state: "No items assigned to you" when no assigned items exist.
- [x] 5.2 Loading indicator and error handling with retry.

## 6. Build and Verify

- [x] 6.1 Run `npm run build` and verify no errors.
- [ ] 6.2 Test My Work view via browser — verify grouping, filtering, navigation.

## Verification
- [x] All tasks checked off
- [ ] Manual testing against acceptance criteria

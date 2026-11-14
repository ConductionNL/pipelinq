# Tasks: leads-list-board-line

### Task 1: The leads list is the board
- **spec_ref**: `openspec/changes/leads-list-board-line/specs/leads-list-board-line/spec.md#requirement-the-leads-list-is-the-board`
- **files**: `src/views/leads/LeadList.vue`, `l10n/*`
- [x] Test first: `tests/vitest/leadsBoardLine.spec.js` (red)
- [x] Implement

### Task 2: The weighted forecast follows the list
- **spec_ref**: `openspec/changes/leads-list-board-line/specs/leads-list-board-line/spec.md#requirement-the-weighted-forecast-follows-the-list`
- **files**: `lib/Service/AnalyticsService.php`, `lib/Controller/AnalyticsController.php`, `src/views/leads/LeadList.vue`
- [x] Test first: `CommercialAnalyticsServiceTest::testOpenPipelineFollowsALeadFilter`, `AnalyticsControllerTest::testCommercialPassesTheListFilter` (red)
- [x] Implement

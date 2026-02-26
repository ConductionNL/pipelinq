# Tasks: pipeline-enhancements

## 1. Pipeline View Toggle

- [x] 1.1 Add `viewMode` state ('kanban'/'list') and toggle buttons (kanban icon / list icon) to PipelineBoard.vue header.
- [x] 1.2 Build list table view: table with columns title, entity type badge, stage, assignee, value, due date, priority. Rows clickable. Sortable columns.
- [x] 1.3 Conditionally render kanban or list based on viewMode. Preserve pipeline/filter state across toggles.

## 2. Pipeline Card Quick Actions

- [x] 2.1 Pass `stages` array as prop from PipelineBoard to PipelineCard.
- [x] 2.2 Add quick stage change: small NcSelect on card footer showing pipeline stages. On select, save item with new stage (spread full item). Stop click propagation.
- [x] 2.3 Add quick assign: small NcSelect on card footer for user assignment. Fetch users from OCS API. On select, save item with new assignee (spread full item). Stop click propagation.

## 3. Build and Verify

- [x] 3.1 Run `npm run build` and verify no errors.
- [ ] 3.2 Test view toggle and quick actions via browser.

## Verification
- [ ] All tasks checked off
- [ ] Manual testing against acceptance criteria

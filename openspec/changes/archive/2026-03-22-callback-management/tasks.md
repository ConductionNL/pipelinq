## 1. Schema and Register Setup [MVP]

- [x] 1.1 Add `task` schema to `lib/Settings/pipelinq_register.json` with all properties (type, subject, description, status, priority, deadline, assigneeUserId, assigneeGroupId, clientId, requestId, contactMomentSummary, callbackPhoneNumber, preferredTimeSlot, createdBy, completedAt, resultText, attempts)
- [x] 1.2 Add `task` to the schema map in `SchemaMapService` so it is recognized by the app
- [x] 1.3 Bump app version in `appinfo/info.xml` to trigger repair step re-import

## 2. Task Store [MVP]

- [x] 2.1 Create `src/services/taskUtils.js` with task utility functions, OCS group fetching, and sharees search (app uses shared objectStore from @conduction/nextcloud-vue, not separate Pinia stores per entity)
- [x] 2.2 Add user group fetching to the task utilities (query OCS API for current user's Nextcloud groups, cache result)
- [x] 2.3 Add task filtering logic: personal tasks (assigneeUserId) + group tasks (assigneeGroupId in user's groups)

## 3. Task Views [MVP]

- [x] 3.1 Create `src/views/tasks/TaskList.vue` — list view with task cards, sorted by deadline, filtered by type and status
- [x] 3.2 Create `src/views/tasks/TaskDetail.vue` — detail view showing all task fields, attempt history, and action buttons (Claim, Afgerond, Niet bereikbaar, Hertoewijzen, Heropenen)
- [x] 3.3 Create `src/views/tasks/TaskForm.vue` — creation form with type selector, subject, description, assignee autocomplete, priority, deadline, optional fields (clientId, requestId, callbackPhoneNumber, preferredTimeSlot)
- [x] 3.4 Add assignee autocomplete component using Nextcloud OCS sharees API with user/group visual distinction
- [x] 3.5 Register task routes in `src/router/index.js` (TaskList, TaskDetail)
- [x] 3.6 Add "Tasks" navigation entry in the app sidebar/navigation

## 4. Task Claim and Status Actions [MVP]

- [x] 4.1 Implement claim mechanism in TaskDetail.vue: PATCH with version check, handle 409 conflict with user-friendly message
- [x] 4.2 Implement "Afgerond" action: set status to afgerond, prompt for resultText, set completedAt
- [x] 4.3 Implement "Niet bereikbaar" action: add attempt entry to attempts array, show attempt count, suggest closing after 3 attempts
- [x] 4.4 Implement "Hertoewijzen" action: show assignee autocomplete, update assigneeUserId/assigneeGroupId, add attempt entry with result "hertoegewezen"
- [x] 4.5 Implement "Heropenen" action: set status back to open, set new deadline, add attempt entry with result "heropend"

## 5. My Work Integration [MVP]

- [x] 5.1 Modify `src/views/MyWork.vue` to fetch tasks alongside leads and requests (use task store)
- [x] 5.2 Add "Tasks" filter button to the existing filter bar (All / Leads / Requests / Tasks)
- [x] 5.3 Update item count display to include tasks ("Leads (5) . Requests (3) . Tasks (4) -- 12 items total")
- [x] 5.4 Add task card rendering in MyWork.vue with [TASK] badge, type sub-label, subject, status, deadline, priority
- [x] 5.5 Integrate tasks into temporal grouping (Overdue/Due This Week/Upcoming/No Due Date) using task deadline field

## 6. Background Job [MVP]

- [x] 6.1 Create `lib/BackgroundJob/TaskExpiryJob.php` implementing TimedJob with 900-second interval
- [x] 6.2 Implement expiry logic: query OpenRegister for tasks with status open/in_behandeling and deadline in the past, set status to verlopen
- [x] 6.3 Implement approaching-deadline detection: tasks with deadline within 4 hours, send reminder notification (with duplicate prevention)
- [x] 6.4 Register TaskExpiryJob in `appinfo/info.xml` under `<background-jobs>`

## 7. Notification Integration [MVP]

- [x] 7.1 Extend `NotificationService` with task notification types: assignment, completion, reassignment, escalation
- [x] 7.2 Extend `Notifier.php` to handle task notification rendering (subject, message, link to task detail)
- [x] 7.3 Send notification on task assignment (to assignee user)
- [x] 7.4 Send notification on task completion (to creating agent via createdBy)
- [x] 7.5 Send notification on task expiry/escalation (to assignee and creating agent)

## 8. Quality and Testing [MVP]

- [x] 8.1 Run `composer check:strict` and fix any PHPCS, PHPMD, Psalm, PHPStan issues in new PHP files
- [x] 8.2 Run `npm run lint` and fix any ESLint issues in new Vue/JS files
- [ ] 8.3 Verify task schema imports correctly via repair step (test with OpenRegister)
- [ ] 8.4 Verify task CRUD operations work end-to-end via the frontend

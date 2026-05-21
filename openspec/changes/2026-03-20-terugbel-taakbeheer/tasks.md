# Tasks: terugbel-taakbeheer

## 0. Deduplication Check

- [x] 0.1 Verify no overlap with OpenRegister built-in `TasksController` — confirmed: that controller manages internal OpenRegister object-level tasks (CnTasksCard); the `taak` schema here is domain-specific KCC callback management, distinct in purpose and lifecycle
- [x] 0.2 Verify no overlap with `request` schema — confirmed: different lifecycle (open/in_behandeling/afgerond/verlopen vs. new/in_progress/completed/rejected/converted) and different domain (backoffice routing vs. service request tracking)
- [x] 0.3 Confirm `NotificationService` reuse for assignment and escalation notifications
- [x] 0.4 Confirm `ITimedJob` is the correct Nextcloud mechanism for background deadline monitoring

## 1. Data Model

- [x] 1.1 Add `taak` schema to `lib/Settings/pipelinq_register.json` with all properties matching ADR-000 `task` entity (`type`, `subject`, `description`, `status`, `priority`, `deadline`, `assigneeUserId`, `assigneeGroupId`, `clientId`, `requestId`, `contactMomentSummary`, `callbackPhoneNumber`, `preferredTimeSlot`, `createdBy`, `completedAt`, `resultText`, `attempts`)
- [x] 1.2 Update register's `schemas` list to include `taak`
- [x] 1.3 Add seed data objects (5 realistic Dutch taak objects) to `pipelinq_register.json` under `components.objects[]` using `@self` envelope with `register: pipelinq`, `schema: taak`, and unique slugs

## 2. Backend

- [x] 2.1 Create `lib/Service/TaskService.php`:
  - `calculateDeadline(string $createdAt, int $businessHours): string` — business-hours calculation (Mon-Fri 08:00-17:00), skips weekends
  - `getDefaultDeadline(): string` — next business day at 17:00
  - `validateTask(array $data): array` — required fields (type, subject, at least one assignee)
  - `logAttempt(string $taskId, string $result, string $notes): void` — appends to `attempts` array
  - `claimTask(string $taskId, string $userId): array` — sets `assigneeUserId`, clears `assigneeGroupId`, status → `in_behandeling`
  - All methods tagged with `@spec openspec/changes/2026-03-20-terugbel-taakbeheer/tasks.md#2.1`
- [x] 2.2 Create `lib/BackgroundJob/TaskEscalationJob.php`:
  - Extends `ITimedJob` with 15-minute interval (900 seconds)
  - Queries OpenRegister for tasks with status `open` and past deadline → changes status to `verlopen`
  - Queries for tasks `in_behandeling` and >24h past deadline → changes status to `verlopen`
  - Queries for tasks approaching deadline within 4 hours → sends escalation notification via `NotificationService`
  - Tracks last reminder timestamp per task to prevent duplicate notifications
  - Tagged with `@spec openspec/changes/2026-03-20-terugbel-taakbeheer/tasks.md#2.2`
- [x] 2.3 Register `TaskEscalationJob` in `appinfo/info.xml` under `<background-jobs>`

## 3. Frontend Views

- [x] 3.1 Create `src/views/tasks/TaskList.vue`:
  - Uses `CnIndexPage` with `useListView('taak', { sidebarState, objectStore })`
  - `CnFacetSidebar` with facets: type, status, priority, assigneeUserId, assigneeGroupId
  - `CnActionsBar` with search and "Nieuwe taak" button
  - `CnStatusBadge` for status and priority columns
  - Row click → `$router.push({ name: 'TaskDetail', params: { id } })`
- [x] 3.2 Create `src/views/tasks/TaskDetail.vue`:
  - Uses `CnDetailPage` with `useDetailView` composable
  - Conditional header action buttons: Claim (group tasks), In behandeling nemen, Afgerond, Heropenen, Verwijderen
  - Highlighted banner for `preferredTimeSlot` when set
  - Prominent display of `callbackPhoneNumber` alongside client default phone
  - `CnDetailCard` sections: Task Info, Linked Entities (client, request), Attempt Log
  - `CnObjectSidebar` with Files, Notes, Audit tabs
- [x] 3.3 Create `src/views/tasks/TaskForm.vue`:
  - Unified form for all task types; type selector controls visible fields
  - User/group assignment autocomplete querying Nextcloud OCS API; icon differentiation for users vs groups
  - Priority selector (Hoog / Normaal / Laag)
  - Deadline field defaulting to `TaskService.getDefaultDeadline()`
  - `preferredTimeSlot` text field
  - `callbackPhoneNumber` field shown only when type is `terugbelverzoek`
  - All user-visible strings via `t(appName, '...')`; no hardcoded colors; uses Nextcloud CSS variables

## 4. Navigation and Routing

- [x] 4.1 Add task routes to `src/router/index.js`:
  - `{ path: '/tasks', name: 'TaskList', component: TaskList }`
  - `{ path: '/tasks/:id', name: 'TaskDetail', component: TaskDetail, props: route => ({ taskId: route.params.id }) }`
- [x] 4.2 Add "Taken" entry to `src/navigation/MainMenu.vue` with checklist icon and route `/tasks`

## 5. My Work Integration

- [x] 5.1 Extend `src/views/MyWork.vue`:
  - Fetch tasks from `objectStore` in parallel with leads and requests via `Promise.all`
  - Add "Taken" filter button to existing filter-buttons pattern
  - Include tasks in temporal grouping (overdue → today → this week → later)
  - Apply `getPriorityColor` to tasks using `priority` field
  - Show task type badge (Terugbelverzoek / Opvolgtaak / Informatievraag) per row
  - Update count display: "Leads (N) — Verzoeken (N) — Taken (N) — N items totaal"
  - Task row click → `$router.push({ name: 'TaskDetail', params: { id } })`

## 6. Store Registration

- [x] 6.1 Register `taak` object type in `src/store/store.js` via `objectStore.registerObjectType('taak', 'taak', 'pipelinq')` inside `initializeStores()`

## 7. Verification

- [ ] 7.1 Run `npm run build` and verify no errors
- [ ] 7.2 Manual testing via browser: create terugbelverzoek, assign to group, claim task, complete task, verify My Work shows task
- [ ] 7.3 Verify seed data loads on fresh install: 5 taak objects visible in TaskList
- [ ] 7.4 Verify `TaskEscalationJob` registered correctly via Admin → Background Jobs

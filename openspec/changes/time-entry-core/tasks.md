# Tasks: time-entry-core (timer, manual, weekly grid)

## 0. Deduplication Check

- [ ] 0.1 Search `openspec/specs/` and `openregister/lib/Service/` for any existing time tracking, duration, or hour-logging functionality
  - **acceptance_criteria**:
    - GIVEN the search is complete
    - THEN document findings in a comment on this task (expected: no overlap found — no existing timeEntry schema or timer logic exists in Pipelinq)

## 1. Data Model

- [ ] 1.1 Add `timeEntry` schema to `lib/Settings/pipelinq_register.json`
  - **spec_ref**: `specs/time-entry-core/spec.md#Data Model`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the schema is added
    - THEN it MUST include all properties: `date`, `startTime`, `endTime`, `duration`, `description`, `comment`, `entryType`, `billable`, `userId`, `client`, `lead`
    - AND `duration`, `entryType`, `userId`, `date` MUST be marked required
    - AND `entryType` MUST have enum: `["timer", "manual"]`
    - AND the schema MUST be added to the register's schema list

- [ ] 1.2 Add 5 seed `timeEntry` objects to `pipelinq_register.json`
  - **spec_ref**: `design.md#Seed Data`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the seed data is added
    - THEN there MUST be exactly 5 objects using the `@self` envelope with unique slugs
    - AND all objects MUST use Dutch descriptions and realistic values
    - AND mix of `entryType: timer` and `entryType: manual` entries MUST be present
    - AND the import MUST be idempotent (slug-matched, no duplicates on re-import)

## 2. Backend Service

- [ ] 2.1 Create `lib/Service/TimeEntryService.php`
  - **spec_ref**: `design.md#TimeEntryService`
  - **files**: `pipelinq/lib/Service/TimeEntryService.php`
  - **acceptance_criteria**:
    - GIVEN the service is created
    - THEN it MUST implement: `startTimer()`, `stopTimer()`, `getActiveTimer()`, `getWeeklyEntries()`, `getWeeklyTotal()`
    - AND `startTimer()` MUST create a `timeEntry` with `entryType=timer`, `startTime=now`, `status=active`
    - AND `stopTimer()` MUST compute `duration = (endTime - startTime)` in whole minutes
    - AND `getActiveTimer()` MUST return null when no active timer exists for the user
    - AND all methods MUST include `@spec openspec/changes/time-entry-core/tasks.md#2.1` PHPDoc tag

- [ ] 2.2 Create `lib/Controller/TimerController.php`
  - **spec_ref**: `specs/time-entry-core/spec.md#REQ-TIME-001`
  - **files**: `pipelinq/lib/Controller/TimerController.php`
  - **acceptance_criteria**:
    - GIVEN the controller is created
    - THEN it MUST expose: `POST /api/timer/start`, `POST /api/timer/stop/{id}`, `GET /api/timer/active`
    - AND all endpoints MUST be `@NoAdminRequired`
    - AND `stopTimer` MUST return HTTP 403 if the entry does not belong to the current user
    - AND `startTimer` MUST return HTTP 409 if the user already has an active timer
    - AND the controller MUST delegate all logic to `TimeEntryService` (thin controller, ≤10 lines/method)
    - AND it MUST include `@spec openspec/changes/time-entry-core/tasks.md#2.2` PHPDoc tag

## 3. Routes

- [ ] 3.1 Add timer API routes to `appinfo/routes.php`
  - **spec_ref**: `design.md#TimerController`
  - **files**: `pipelinq/appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN the routes are added
    - THEN `POST /api/timer/start`, `POST /api/timer/stop/{id}`, `GET /api/timer/active` MUST be registered
    - AND specific routes MUST appear before any wildcard `{slug}` routes
    - AND route names MUST follow the `appname.controller.method` convention

## 4. Frontend — Timer Widget

- [ ] 4.1 Create `src/components/timer/TimerWidget.vue`
  - **spec_ref**: `specs/time-entry-core/spec.md#REQ-TIME-001`
  - **files**: `pipelinq/src/components/timer/TimerWidget.vue`
  - **acceptance_criteria**:
    - GIVEN no active timer exists
    - THEN the widget MUST show a Start button
    - GIVEN an active timer is running
    - THEN the widget MUST show elapsed time in `HH:MM:SS` format updated every second
    - AND a Stop button MUST be visible
    - AND clicking Stop MUST call `timerStore.stopTimer()` and open `ManualEntryDialog.vue` pre-filled
    - AND ALL user-visible strings MUST use `t(appName, '...')` for translation

- [ ] 4.2 Create `src/store/modules/timerStore.js`
  - **spec_ref**: `design.md#Store`
  - **files**: `pipelinq/src/store/modules/timerStore.js`
  - **acceptance_criteria**:
    - GIVEN the store is created
    - THEN it MUST be a Pinia `defineStore` with state: `activeEntry`, `elapsedSeconds`
    - AND actions: `startTimer()`, `stopTimer()`, `fetchActiveTimer()`, `tick()`
    - AND `fetchActiveTimer()` MUST be called in `App.vue` on startup to restore active timer state
    - AND `tick()` MUST use `setInterval` updating `elapsedSeconds` every 1000ms
    - AND the interval MUST be cleared when the timer stops to prevent memory leaks

- [ ] 4.3 Mount `TimerWidget.vue` in `src/App.vue`
  - **spec_ref**: `specs/time-entry-core/spec.md#REQ-TIME-001 (Timer widget visible from all pages)`
  - **files**: `pipelinq/src/App.vue`
  - **acceptance_criteria**:
    - GIVEN the app loads
    - THEN `TimerWidget.vue` MUST be rendered in the header area visible on every route
    - AND `timerStore.fetchActiveTimer()` MUST be called in `created()` to restore state on reload

## 5. Frontend — Manual Entry Dialog

- [ ] 5.1 Create `src/views/timeEntries/ManualEntryDialog.vue`
  - **spec_ref**: `specs/time-entry-core/spec.md#REQ-TIME-002, REQ-TIME-005`
  - **files**: `pipelinq/src/views/timeEntries/ManualEntryDialog.vue`
  - **acceptance_criteria**:
    - GIVEN the dialog opens for a new entry
    - THEN the date field MUST default to today and the billable toggle MUST default to ON
    - AND the form MUST include: date (date picker), duration (HH:MM input), description, comment, billable toggle, client selector, lead selector
    - GIVEN the user enters "0:00" for duration
    - THEN a validation error "Duur moet groter zijn dan 0 minuten" MUST be shown and submit blocked
    - GIVEN the user selects a future date
    - THEN a validation error "Datum mag niet in de toekomst liggen" MUST be shown and submit blocked
    - AND the dialog MUST accept pre-fill props (`date`, `duration`, `startTime`, `endTime`) for post-timer flow
    - AND EVERY `await store.action()` MUST be wrapped in `try/catch` with user-facing error feedback

## 6. Frontend — Time Entry List

- [ ] 6.1 Create `src/views/timeEntries/TimeEntryList.vue`
  - **spec_ref**: `specs/time-entry-core/spec.md#REQ-TIME-004`
  - **files**: `pipelinq/src/views/timeEntries/TimeEntryList.vue`
  - **acceptance_criteria**:
    - GIVEN the list loads
    - THEN it MUST use `CnIndexPage` with `useListView('timeEntry', ...)`
    - AND columns MUST include: date, description, comment (truncated to 80 chars), duration (formatted as `Xu YYm`), billable badge, entryType chip
    - AND an Add button MUST open `ManualEntryDialog.vue`
    - AND clicking a row MUST navigate to `TimeEntryDetail.vue`
    - AND a filter bar MUST support filtering by date range and billable status

## 7. Frontend — Weekly Grid

- [ ] 7.1 Create `src/views/timeEntries/WeeklyGrid.vue`
  - **spec_ref**: `specs/time-entry-core/spec.md#REQ-TIME-003`
  - **files**: `pipelinq/src/views/timeEntries/WeeklyGrid.vue`
  - **acceptance_criteria**:
    - GIVEN the grid loads
    - THEN it MUST display Mon–Sun column headers with Dutch date labels (e.g., "Ma 11 mei")
    - AND a "Vorige week" and "Volgende week" navigation control MUST be present
    - AND a totals row MUST show per-day totals and a grand total for the week
    - AND totals MUST show billable hours separately (e.g., "4u 15m totaal / 3u 30m factureerbaar")
    - AND clicking an empty day cell MUST open `ManualEntryDialog.vue` pre-filled with that date
    - AND clicking an existing entry pill MUST open the edit dialog for that entry
    - AND `TimeEntryService::getWeeklyEntries()` MUST be called via the backend; do NOT compute week grouping in the frontend

## 8. Frontend — Time Entry Detail

- [ ] 8.1 Create `src/views/timeEntries/TimeEntryDetail.vue`
  - **spec_ref**: `specs/time-entry-core/spec.md#REQ-TIME-004, REQ-TIME-007`
  - **files**: `pipelinq/src/views/timeEntries/TimeEntryDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the detail view loads for an existing entry
    - THEN it MUST use `CnDetailPage` with `CnDetailCard` sections: "Ureninvoer", "Gekoppelde records"
    - AND the "Ureninvoer" card MUST show: date, duration, description, comment, billable badge, entryType, userId
    - AND the "Gekoppelde records" card MUST show linked client and/or lead with names (not just UUIDs)
    - AND header actions MUST include Edit (opens `ManualEntryDialog.vue`) and Delete (`CnDeleteDialog`)
    - AND the Edit action MUST be hidden if the entry's `userId` does not match the current Nextcloud user
    - AND `CnObjectSidebar` MUST be shown with Notes and Audit Trail tabs

## 9. Navigation and Routing

- [ ] 9.1 Add time entry routes to `src/router/index.js`
  - **spec_ref**: `design.md#Routes`
  - **files**: `pipelinq/src/router/index.js`
  - **acceptance_criteria**:
    - GIVEN the router is updated
    - THEN `/time-entries` MUST resolve to `TimeEntryList.vue`
    - AND `/time-entries/:id` MUST resolve to `TimeEntryDetail.vue` with `id` prop from params
    - AND `/time-entries/weekly` MUST resolve to `WeeklyGrid.vue`
    - AND the `/time-entries/weekly` route MUST be declared BEFORE `/time-entries/:id` to avoid slug conflict

- [ ] 9.2 Add "Urenregistratie" entry to `src/navigation/MainMenu.vue`
  - **spec_ref**: `design.md#Navigation`
  - **files**: `pipelinq/src/navigation/MainMenu.vue`
  - **acceptance_criteria**:
    - GIVEN the nav is updated
    - THEN a "Urenregistratie" item with a clock icon MUST appear in the main navigation
    - AND clicking it MUST navigate to `/time-entries`
    - AND the item MUST use `t(appName, 'Urenregistratie')` for the label

## 10. Verification

- [ ] 10.1 Run `npm run build` and verify no compilation errors
- [ ] 10.2 Verify `pipelinq_register.json` imports cleanly via `ConfigurationService::importFromApp()` (no schema validation errors, no duplicate slugs)
- [ ] 10.3 Manual browser test: start a timer, navigate away, verify elapsed time continues; stop timer, verify entry is created with correct duration
- [ ] 10.4 Manual browser test: create a manual entry, verify it appears in the list and in the correct day column of the weekly grid
- [ ] 10.5 Manual browser test: edit an entry's comment and verify the updated comment appears in the list and detail views
- [ ] 10.6 Verify that attempting to edit another user's entry returns HTTP 403

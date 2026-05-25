# Tasks: project-task-hierarchy

## 0. Deduplication Check

- [ ] 0.1 Search `openspec/specs/` for any existing project, WBS, or time-tracking spec — document findings
- [ ] 0.2 Search `lib/Service/` for any ProjectService, PhaseService, or TaskService that could be reused
- [ ] 0.3 Confirm no existing schema in `pipelinq_register.json` overlaps with project/phase/task/activity

## 1. Data Model

- [ ] 1.1 Add `project` schema to `lib/Settings/pipelinq_register.json` with properties: name (required), client (uuid), description, status, billable, budgetHours, budgetAmount, hourlyRate, startDate, endDate, color
- [ ] 1.2 Add `projectPhase` schema with properties: name (required), project (uuid, required), description, order, status, billable, budgetHours, startDate, endDate
- [ ] 1.3 Add `projectTask` schema with properties: name (required), phase (uuid, required), project (uuid), description, order, status, billable, estimatedHours, assignee, deadline
- [ ] 1.4 Add `projectActivity` schema with properties: task (uuid, required), project (uuid), description, date (required), durationMinutes (required), billable, user (required), hourlyRate
- [ ] 1.5 Add all four schemas to the register's `schemas` list
- [ ] 1.6 Add seed data objects (3–5 per schema) as specified in design.md

## 2. Store Registration

- [ ] 2.1 Add `objectStore.registerObjectType('project', 'project', 'pipelinq')` to `src/store/store.js`
- [ ] 2.2 Add `objectStore.registerObjectType('projectPhase', 'projectPhase', 'pipelinq')` to `src/store/store.js`
- [ ] 2.3 Add `objectStore.registerObjectType('projectTask', 'projectTask', 'pipelinq')` to `src/store/store.js`
- [ ] 2.4 Add `objectStore.registerObjectType('projectActivity', 'projectActivity', 'pipelinq')` to `src/store/store.js`

## 3. Frontend Views

- [ ] 3.1 Create `src/views/projects/ProjectList.vue` using `CnIndexPage` + `useListView` — columns: name, client, status, billable, budget hours / logged hours, end date; filters: status, client, billable
- [ ] 3.2 Create `src/views/projects/ProjectDetail.vue` with:
  - [ ] 3.2a Header section: name, client link, status badge, colour swatch, edit/delete actions
  - [ ] 3.2b Budget summary cards using `CnStatsBlock`: geplande uren, gelogde uren, factureerbaar, resterende uren
  - [ ] 3.2c WBS tree section embedding `ProjectWbsTree.vue`
  - [ ] 3.2d "Fase toevoegen" button opening `CnFormDialog` for projectPhase
  - [ ] 3.2e `CnObjectSidebar` with Files, Notes, Tags, Audit tabs
- [ ] 3.3 Create `src/views/projects/ProjectActivityList.vue` — table of time entries for the project: date, user, task, description, duration, billable; totals row; filters: date range, user, task, billable
- [ ] 3.4 Create `src/components/ProjectWbsTree.vue`:
  - [ ] 3.4a Render list of phases as collapsible rows
  - [ ] 3.4b Render tasks indented under each phase
  - [ ] 3.4c Phase row shows: name, status chip, billable indicator (with "(geërfd)" label when inherited), tasks completed/total progress bar
  - [ ] 3.4d Task row shows: name, assignee avatar, estimated hours, logged hours, status chip, "Taak toevoegen" and "Tijdregistratie" inline buttons
  - [ ] 3.4e Implement `resolvedBillable(level, object)` helper: walk up hierarchy returning first explicitly set value, defaulting to `true`

## 4. Navigation and Routing

- [ ] 4.1 Add routes to `src/router/index.js`:
  - `/projects` → `ProjectList`
  - `/projects/:id` → `ProjectDetail`
  - `/projects/:id/activities` → `ProjectActivityList`
- [ ] 4.2 Add "Projecten" entry to `src/navigation/MainMenu.vue` with briefcase MDI icon and route `/projects`

## 5. Client Detail Integration

- [ ] 5.1 Add a "Projecten" `CnDetailCard` section to `src/views/clients/ClientDetail.vue` using `fetchUsed` to retrieve projects referencing this client
- [ ] 5.2 Project rows in the client detail section link to `/projects/:id`

## 6. Verification

- [ ] 6.1 Run `npm run build` and verify no errors or warnings
- [ ] 6.2 Verify seed data imports correctly via OpenRegister admin (3–5 objects visible per schema)
- [ ] 6.3 Create a project linked to an existing client — confirm it appears in client detail "Projecten" section
- [ ] 6.4 Add a phase, then a task — confirm WBS tree renders with correct hierarchy
- [ ] 6.5 Register a time entry on a task — confirm it appears in project activity list and updates logged hours total
- [ ] 6.6 Set phase `billable: false` on a project with `billable: true` — confirm task and activity show "(geërfd van fase): niet-factureerbaar"
- [ ] 6.7 Verify budget over-budget warning appears when logged hours exceed budgetHours

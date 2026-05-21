# Proposal: terugbel-taakbeheer

## Problem

KCC agents cannot create callback requests or follow-up tasks when citizen questions cannot be resolved immediately. There is no task entity, no assignment to departments (Nextcloud groups), no deadline tracking, and no escalation system. 31% of tenders explicitly require this capability.

## Solution

Implement callback and task management with:
1. **Taak schema** in OpenRegister with types: terugbelverzoek, opvolgtaak, informatievraag
2. **Task creation forms** for callbacks and follow-ups with assignment to users/groups
3. **Status lifecycle** (open/in_behandeling/afgerond/verlopen) with deadline monitoring
4. **Background job** for deadline escalation and auto-expiry
5. **My Work integration** — tasks appear in the existing personal inbox
6. **Task list view** with search, filtering, and bulk operations

## Scope

- Taak schema with all properties per spec
- Task creation form (callback and generic follow-up)
- Assignment to users and Nextcloud groups
- Status tracking with history
- Priority and deadline management
- Background job for deadline monitoring
- My Work inbox integration
- Task list/detail views

## Out of scope

- Citizen status notifications (V1)
- Task templates (V1)
- SLA reporting on tasks (V1)
- Procest-specific task types (cross-app)



## Design

# Design: terugbel-taakbeheer

## Architecture

### Data Model (OpenRegister Schema)

New `taak` schema in the pipelinq register:

- `type` (string, required, enum: terugbelverzoek/opvolgtaak/informatievraag, facetable)
- `subject` (string, required) — Task subject
- `description` (string) — Detailed description
- `client` (string, format: uuid) — Client reference
- `zaak` (string, format: uuid) — Case reference
- `contactmoment` (string, format: uuid) — Originating contactmoment
- `request` (string, format: uuid) — Linked request
- `assignee` (string, required, facetable) — User UID or group ID
- `assigneeType` (string, required, enum: user/group) — Assignment target type
- `priority` (string, required, enum: hoog/normaal/laag, default: normaal, facetable)
- `deadline` (string, format: date-time, required) — Completion deadline
- `status` (string, required, enum: open/in_behandeling/afgerond/verlopen, default: open, facetable)
- `preferredTimeSlot` (string) — Preferred callback window
- `callbackPhone` (string) — Override callback number
- `result` (string) — Completion result text
- `completedAt` (string, format: date-time) — Completion timestamp
- `createdBy` (string, required) — Creating agent's UID
- `attempts` (integer, default: 0) — Callback attempt counter
- `sourceApp` (string, default: pipelinq) — Originating app

### Backend

#### TaskEscalationJob (`lib/BackgroundJob/TaskEscalationJob.php`)

`ITimedJob` running every 15 minutes. Checks for:
1. Tasks approaching deadline (4 hours) — sends escalation notification
2. Tasks past deadline still open — changes status to "verlopen"

#### TaskService (`lib/Service/TaskService.php`)

- `calculateDeadline(string $createdAt, int $businessHours): string` — Calculate deadline respecting business hours (Mon-Fri 08:00-17:00)
- `getDefaultDeadline(): string` — Next business day at 17:00
- `validateTask(array $data): array` — Validate required fields

### Frontend

#### Routes
- `/tasks` — TaskList
- `/tasks/new` — TaskForm (create)
- `/tasks/:id` — TaskDetail

#### Views

**TaskList.vue** (`src/views/tasks/TaskList.vue`)
- Filterable list with status/priority badges
- Filter by type, status, assignee, priority
- Search by subject and client name

**TaskDetail.vue** (`src/views/tasks/TaskDetail.vue`)
- Full task context with linked entities
- Status actions (claim, complete, reopen)
- Callback attempt logging
- Status history timeline

**TaskForm.vue** (`src/views/tasks/TaskForm.vue`)
- Unified form for callbacks and follow-ups
- User/group assignment autocomplete
- Priority and deadline fields
- Preferred callback time slot

#### Navigation
Add "Tasks" entry to MainMenu.vue.

#### My Work Integration
Extend MyWork.vue to include tasks alongside leads and requests.

## Files Changed

### New Files
- `lib/Service/TaskService.php`
- `lib/BackgroundJob/TaskEscalationJob.php`
- `src/views/tasks/TaskList.vue`
- `src/views/tasks/TaskDetail.vue`
- `src/views/tasks/TaskForm.vue`

### Modified Files
- `lib/Settings/pipelinq_register.json` — Add taak schema
- `appinfo/routes.php` — No new API routes (uses OpenRegister directly)
- `src/router/index.js` — Add task routes
- `src/navigation/MainMenu.vue` — Add Tasks nav item
- `src/views/MyWork.vue` — Extend to include tasks



## Tasks

# Tasks: terugbel-taakbeheer

## 1. Data Model
- [x] 1.1 Add `taak` schema to `pipelinq_register.json`
- [x] 1.2 Update register's schemas list

## 2. Backend
- [x] 2.1 Create `lib/Service/TaskService.php` with deadline calculation and validation
- [x] 2.2 Create `lib/BackgroundJob/TaskEscalationJob.php` for deadline monitoring

## 3. Frontend Views
- [x] 3.1 Create `src/views/tasks/TaskList.vue`
- [x] 3.2 Create `src/views/tasks/TaskDetail.vue`
- [x] 3.3 Create `src/views/tasks/TaskForm.vue`

## 4. Navigation and Routing
- [x] 4.1 Add task routes to `src/router/index.js`
- [x] 4.2 Add Tasks entry to `src/navigation/MainMenu.vue`

## 5. Verification
- [ ] 5.1 Run `npm run build` and verify no errors
- [ ] 5.2 Manual testing via browser
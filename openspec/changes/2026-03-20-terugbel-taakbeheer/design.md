# Design: terugbel-taakbeheer

**Status:** pr-created

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

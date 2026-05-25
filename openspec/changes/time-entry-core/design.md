# Design: time-entry-core (timer, manual, weekly grid)

## Architecture Overview

The time entry module follows the standard Pipelinq thin-client architecture: one new OpenRegister schema (`timeEntry`) stored in the `pipelinq` register, a lightweight backend service handling timer state and computed aggregates, and Vue frontend views that query OpenRegister directly via `objectStore`.

A persistent `TimerWidget.vue` in the app header holds the active-timer state in a Pinia store module. Start/stop calls a dedicated `TimerController` which records `startTime` / `endTime` and computes `duration`. All other CRUD goes through the standard OpenRegister `ObjectService` pattern.

## Data Model

### timeEntry schema (`lib/Settings/pipelinq_register.json`)

New schema added to the `pipelinq` register.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| date | string (date) | Yes | Calendar date of the work session (YYYY-MM-DD) |
| startTime | string (date-time) | No | UTC timestamp when timer started (timer mode only) |
| endTime | string (date-time) | No | UTC timestamp when timer stopped (timer mode only) |
| duration | integer | Yes | Duration in minutes (computed from start/end for timer; entered directly for manual) |
| description | string | No | Short description of the work performed |
| comment | string | No | Additional free-text comment on the entry |
| entryType | string | Yes | How the entry was created: `timer` or `manual` |
| billable | boolean | No | Whether this entry is billable (default: true) |
| userId | string | Yes | Nextcloud user UID of the person who logged time |
| client | string (uuid) | No | UUID reference to a `client` object |
| lead | string (uuid) | No | UUID reference to a `lead` object |

OpenRegister built-ins used: `id`, `uuid`, `createdAt`, `updatedAt`, `owner`, `status`, `tags`, `notes`, `auditTrail`.

## Seed Data

Three seed `timeEntry` objects per Dutch work context. Loaded via `components.objects[]` in `pipelinq_register.json`.

### timeEntry-1

```json
{
  "@self": { "register": "pipelinq", "schema": "timeEntry", "slug": "time-entry-vergadering-amsterdam" },
  "date": "2026-05-19",
  "startTime": "2026-05-19T09:00:00Z",
  "endTime": "2026-05-19T10:30:00Z",
  "duration": 90,
  "description": "Strategisch overleg gemeentelijke diensten",
  "comment": "Inclusief voorbereiding en notulen",
  "entryType": "timer",
  "billable": true,
  "userId": "admin",
  "client": null,
  "lead": null
}
```

### timeEntry-2

```json
{
  "@self": { "register": "pipelinq", "schema": "timeEntry", "slug": "time-entry-offerte-rotterdam" },
  "date": "2026-05-19",
  "startTime": null,
  "endTime": null,
  "duration": 120,
  "description": "Opstellen offerte digitalisering archief",
  "comment": "Op basis van eerder projectvoorstel gemeente Rotterdam",
  "entryType": "manual",
  "billable": true,
  "userId": "admin",
  "client": null,
  "lead": null
}
```

### timeEntry-3

```json
{
  "@self": { "register": "pipelinq", "schema": "timeEntry", "slug": "time-entry-support-den-haag" },
  "date": "2026-05-20",
  "startTime": null,
  "endTime": null,
  "duration": 45,
  "description": "Telefonisch support omgevingsvergunning portaal",
  "comment": "",
  "entryType": "manual",
  "billable": false,
  "userId": "admin",
  "client": null,
  "lead": null
}
```

### timeEntry-4

```json
{
  "@self": { "register": "pipelinq", "schema": "timeEntry", "slug": "time-entry-demo-eindhoven" },
  "date": "2026-05-20",
  "startTime": "2026-05-20T13:00:00Z",
  "endTime": "2026-05-20T14:15:00Z",
  "duration": 75,
  "description": "Productdemo CRM-integratie gemeente Eindhoven",
  "comment": "Opvolging nodig: integratievoorstel opsturen",
  "entryType": "timer",
  "billable": true,
  "userId": "admin",
  "client": null,
  "lead": null
}
```

### timeEntry-5

```json
{
  "@self": { "register": "pipelinq", "schema": "timeEntry", "slug": "time-entry-rapportage-utrecht" },
  "date": "2026-05-18",
  "startTime": null,
  "endTime": null,
  "duration": 60,
  "description": "Maandrapportage projectstatus gemeente Utrecht",
  "comment": "",
  "entryType": "manual",
  "billable": true,
  "userId": "admin",
  "client": null,
  "lead": null
}
```

## Backend

### TimerController (`lib/Controller/TimerController.php`)

Handles start/stop of the active timer. Requires `@NoAdminRequired`. `@NoCSRFRequired` on GET only.

| Method | URL | Action |
|--------|-----|--------|
| POST | `/api/timer/start` | Start a new timer session — creates a `timeEntry` object with `startTime = now`, `entryType = timer`, `status = active` |
| POST | `/api/timer/stop/{id}` | Stop the active timer — sets `endTime = now`, computes `duration`, sets `status = complete` |
| GET | `/api/timer/active` | Return the current user's active timer entry (if any) |

### TimeEntryService (`lib/Service/TimeEntryService.php`)

- `startTimer(string $userId, string $description, ?string $clientId, ?string $leadId): array` — Creates active timer `timeEntry` via `ObjectService::saveObject()`
- `stopTimer(string $entryId, string $userId): array` — Fetches entry, sets `endTime`, computes `duration` in minutes, saves, returns updated object
- `getActiveTimer(string $userId): ?array` — Queries OpenRegister for `entryType=timer AND status=active` for this user
- `getWeeklyEntries(string $userId, string $weekStart): array` — Returns all entries for the ISO week containing `$weekStart`, grouped by date
- `getWeeklyTotal(string $userId, string $weekStart): int` — Sums duration (minutes) for the week

## Reuse Analysis

OpenRegister and `@conduction/nextcloud-vue` provide:

| Need | Provided by |
|------|-------------|
| CRUD for timeEntry | `ObjectService::saveObject()` / `deleteObject()` |
| List with filters | `CnIndexPage` + `useListView` composable |
| Create/edit form | `CnFormDialog` (schema-driven) |
| Detail view | `CnDetailPage` + `CnDetailCard` |
| Audit trail | `CnObjectSidebar` → `CnAuditTrailTab` (automatic) |
| Pinia store | `createObjectStore('timeEntry')` |
| Search / filter bar | `CnFilterBar` + `useListView` |

Custom code required:
- `TimerWidget.vue` — persistent header widget with running clock (no platform equivalent)
- `WeeklyGrid.vue` — spreadsheet-style week view (no platform equivalent)
- `TimerController.php` + `TimeEntryService.php` — timer start/stop session logic

## Frontend

### Routes (added to `src/router/index.js`)

- `/time-entries` → `TimeEntryList.vue` (list + quick add)
- `/time-entries/:id` → `TimeEntryDetail.vue` (view / edit)
- `/time-entries/weekly` → `WeeklyGrid.vue` (weekly grid view)

### Views

**TimerWidget.vue** (`src/components/timer/TimerWidget.vue`)

Persistent component mounted in the Pipelinq header (via `App.vue`). Shows:
- Start button (when no active timer) or running elapsed time `HH:MM:SS` + Stop button
- Optional description input when starting
- On stop: opens `ManualEntryDialog.vue` pre-filled with timer data for review/save

**TimeEntryList.vue** (`src/views/timeEntries/TimeEntryList.vue`)

- `CnIndexPage` with `useListView('timeEntry', { sidebarState, objectStore })`
- Columns: date, description, duration (formatted), billable badge, entryType chip, client link
- Add button opens `ManualEntryDialog.vue`
- Row click → `TimeEntryDetail.vue`
- Filter bar: date range, billable toggle, entryType

**ManualEntryDialog.vue** (`src/views/timeEntries/ManualEntryDialog.vue`)

- `CnFormDialog` for create/edit
- Fields: date (date picker), duration (HH:MM input), description, comment, billable toggle, client selector, lead selector
- Also used as post-timer review dialog (pre-fills from timer data)

**WeeklyGrid.vue** (`src/views/timeEntries/WeeklyGrid.vue`)

- Spreadsheet-style week view: rows = time entries, columns = Mon–Sun
- Week navigator (prev/next week arrows + week label)
- Day cells show total duration; bottom row shows column totals
- Click a cell → opens `ManualEntryDialog.vue` for that day
- Existing entries shown as pills; click to edit

**TimeEntryDetail.vue** (`src/views/timeEntries/TimeEntryDetail.vue`)

- `CnDetailPage` with `CnDetailCard` sections: Entry Info, Linked Records
- Header actions: Edit (opens `ManualEntryDialog.vue`) + Delete (`CnDeleteDialog`)
- `CnObjectSidebar` with Notes, Audit Trail tabs

### Navigation

Add "Urenregistratie" entry to `src/navigation/MainMenu.vue` with clock icon, linking to `/time-entries`.

### Store

Use `createObjectStore('timeEntry')` with `auditTrailsPlugin` and `relationsPlugin`. Timer active state managed in a dedicated `timerStore` Pinia store (`src/store/modules/timerStore.js`):

- `activeEntry` — the current active timeEntry object or null
- `elapsedSeconds` — computed from `startTime` to now, updated every second via `setInterval`
- `startTimer(description, clientId, leadId)` — calls `POST /api/timer/start`
- `stopTimer()` — calls `POST /api/timer/stop/{id}`, clears interval

## Files Changed

### New Files
- `lib/Controller/TimerController.php`
- `lib/Service/TimeEntryService.php`
- `src/components/timer/TimerWidget.vue`
- `src/views/timeEntries/TimeEntryList.vue`
- `src/views/timeEntries/ManualEntryDialog.vue`
- `src/views/timeEntries/WeeklyGrid.vue`
- `src/views/timeEntries/TimeEntryDetail.vue`
- `src/store/modules/timerStore.js`

### Modified Files
- `lib/Settings/pipelinq_register.json` — Add `timeEntry` schema + seed objects
- `appinfo/routes.php` — Add timer API routes
- `src/router/index.js` — Add time entry routes
- `src/navigation/MainMenu.vue` — Add Urenregistratie nav item
- `src/App.vue` — Mount `TimerWidget.vue` in header

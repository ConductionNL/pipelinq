# Design: task-background-jobs

## Architecture

### Data Model

No new OpenRegister schemas are introduced. This change builds on the existing `task` schema defined in ADR-000:

| Property used | Type | Role in scheduling |
|---|---|---|
| `type` | string (enum) | terugbelverzoek / opvolgtaak / informatievraag |
| `subject` | string | Task title exposed in API responses |
| `description` | string | Human-readable schedule description |
| `status` | string | Lifecycle: open → in_behandeling → afgerond → verlopen |
| `priority` | string | hoog / normaal / laag — affects notification urgency |
| `deadline` | string (ISO 8601) | Scheduled execution date/time |
| `assigneeUserId` | string | Nextcloud user UID receiving the task |
| `assigneeGroupId` | string | Nextcloud group ID for team assignment |
| `clientId` | string (uuid) | Linked client reference |
| `requestId` | string (uuid) | Linked request reference |
| `callbackPhoneNumber` | string | Override callback number (terugbelverzoek) |
| `preferredTimeSlot` | string | Preferred execution window |
| `createdBy` | string | UID of the creating agent/system |
| `completedAt` | string | When the task was completed |
| `resultText` | string | Completion summary |
| `attempts` | array | Execution attempt log entries |

The Schedules API reads and writes `task` objects exclusively. No new properties are added to the schema.

### Reuse Analysis

The following existing OpenRegister services and platform capabilities are leveraged directly:

| Existing service / component | Usage in this change |
|---|---|
| `ObjectService.findObjects($register, $schema, $params)` | Querying tasks by deadline window, status, and assignee — 3 positional args throughout |
| `ObjectService.findObject($register, $schema, $id)` | Single task lookup for GET /api/schedules/{id} and per-object authorization check |
| `ObjectService.saveObject($register, $schema, $object)` | Creating and updating tasks via POST/PUT — 3 positional args |
| `ObjectService.deleteObject($register, $schema, $id)` | Deleting scheduled tasks via DELETE |
| `NotificationService` | Dispatching Nextcloud notifications to assignees when tasks become due |
| `IAppConfig` | Reading `task_register` and `task_schema` config keys (same pattern as existing SettingsService) |
| `IUserSession` | Deriving `createdBy` from authenticated session — never from request body |
| `IGroupManager` | Admin check on mutation endpoints (ADR-003, ADR-005) |

Explicitly NOT duplicated:

- **TaskEscalationJob (terugbel-taakbeheer)** — runs every 15 minutes, checks tasks approaching deadline (4 hours) for escalation, and marks overdue tasks `verlopen`. The new `ScheduledTaskJob` runs every 5 minutes with a broader mandate: processing due tasks across the full status lifecycle, logging attempt records, and dispatching structured notifications with schedule context. Different frequency, different scope.
- **AutomationService (crm-workflow-automation)** — handles event-driven trigger-action automations on CRM entity changes. The Schedules API is time-driven, not event-driven. Different trigger mechanism.
- **CnObjectSidebar Tasks tab** — displays tasks attached to an OpenRegister object via the built-in relation system. The Schedules API is a first-class REST integration surface for external consumers. Different purpose.

### Backend

#### ScheduledTaskService (`lib/Service/ScheduledTaskService.php`)

Constructor injection: `ObjectService`, `IAppConfig`, `IUserSession`, `NotificationService`, `LoggerInterface`.

Methods:

- `getScheduledTasks(array $params): array` — Query tasks with optional filters: `status`, `assigneeUserId`, `assigneeGroupId`, `from` (deadline ≥), `to` (deadline ≤), `_page`, `_limit`. Returns `{items, total, page, pages}`. Calls `ObjectService.findObjects($register, $taskSchema, $filterParams)`.
- `getScheduledTask(string $id): array` — Fetch single task by ID. Calls `ObjectService.findObject($register, $taskSchema, $id)`. Throws `\RuntimeException` with generic message if not found.
- `createScheduledTask(array $data): array` — Validate required fields (`type`, `subject`, `deadline`). Set `createdBy` from `IUserSession->getUser()->getUID()`. Calls `ObjectService.saveObject($register, $taskSchema, $data)`. Returns saved object.
- `updateScheduledTask(string $id, array $data): array` — Fetch existing task, apply partial updates, call `saveObject`. Never allows overwriting `createdBy` from request data.
- `deleteScheduledTask(string $id): void` — Calls `ObjectService.deleteObject($register, $taskSchema, $id)`.
- `getPendingTasks(int $windowMinutes = 60): array` — Find tasks with `status = open` and `deadline` within next `$windowMinutes`. Used by background job and `GET /api/schedules/pending` endpoint.
- `processScheduledTasks(): void` — Called by `ScheduledTaskJob`. Iterates pending due tasks: dispatches notification via `NotificationService`, appends attempt entry to `attempts` array, updates `status` to `in_behandeling`. Tasks past deadline by > 4 hours with `status = open` are transitioned to `verlopen`.
- `authorizeTaskMutation(array $task, string $userId): void` — Checks that `$userId === $task['assigneeUserId']` OR user is in `$task['assigneeGroupId']` OR user is admin. Throws `OCSForbiddenException` with static message `'Not authorized'` if none apply (ADR-005 per-object auth).

#### SchedulesController (`lib/Controller/SchedulesController.php`)

Extends `Controller`. Constructor: `ScheduledTaskService`, `IRequest`, `IGroupManager`, `IUserSession`, `LoggerInterface`.

All non-admin endpoints annotated `#[NoAdminRequired]`. No `#[PublicPage]`. Per-object auth via `ScheduledTaskService.authorizeTaskMutation()` on PUT and DELETE.

| Method | URL | Action | Auth |
|--------|-----|--------|------|
| GET | `/api/schedules` | List scheduled tasks | `#[NoAdminRequired]` |
| POST | `/api/schedules` | Create scheduled task | `#[NoAdminRequired]` |
| GET | `/api/schedules/pending` | Tasks due within window | `#[NoAdminRequired]` |
| GET | `/api/schedules/{id}` | Get single task | `#[NoAdminRequired]` |
| PUT | `/api/schedules/{id}` | Update task | `#[NoAdminRequired]` + per-object |
| DELETE | `/api/schedules/{id}` | Cancel task | `#[NoAdminRequired]` + per-object |

**Route ordering in `appinfo/routes.php`:** `schedules/pending` route MUST be registered BEFORE `schedules/{id}` to prevent the slug catching `pending` as an ID.

All error responses use static messages only — never `$e->getMessage()`. Full exception logged via `$this->logger->error('context', ['exception' => $e])`.

Response shape for list endpoints:
```json
{
  "items": [ { ...task fields... } ],
  "total": 24,
  "page": 1,
  "pages": 2
}
```

**GET `/api/schedules`** query parameters: `status`, `assigneeUserId`, `assigneeGroupId`, `from` (ISO date), `to` (ISO date), `_page` (default: 1), `_limit` (default: 20, max: 100).

**GET `/api/schedules/pending`** query parameters: `window` (minutes, default: 60, max: 1440).

**POST `/api/schedules`** required body fields: `type` (enum: terugbelverzoek/opvolgtaak/informatievraag), `subject`, `deadline` (ISO 8601). Optional: `description`, `priority`, `assigneeUserId`, `assigneeGroupId`, `clientId`, `requestId`, `callbackPhoneNumber`, `preferredTimeSlot`. Returns 201 on success.

#### ScheduledTaskJob (`lib/BackgroundJob/ScheduledTaskJob.php`)

Implements `TimedJob`. Interval: 5 minutes (300 seconds). Constructor: `ScheduledTaskService`, `LoggerInterface`.

`run(array $argument): void`:
1. Call `ScheduledTaskService.processScheduledTasks()`
2. Catch `\Throwable` — log error, do NOT rethrow (background job must not crash the queue)
3. Log summary: tasks processed, notifications sent, tasks expired

Registered in `appinfo/info.xml` under `<background-jobs>`.

### Seed Data

No new schemas are introduced by this change — the `task` schema pre-exists from the terugbel-taakbeheer change. The Schedules API reads and writes task objects; seed data for the task schema should be present in `lib/Settings/pipelinq_register.json` from that prior change.

Example task objects illustrating scheduling patterns (for reference — actual seed data lives in `pipelinq_register.json`):

```json
{
  "@self": { "register": "pipelinq", "schema": "task", "slug": "task-callback-gemeente-amsterdam" },
  "type": "terugbelverzoek",
  "subject": "Terugbellen: vraag over omgevingsvergunning",
  "description": "Burger wil teruggebeld worden over status aanvraag omgevingsvergunning Kalverstraat 12",
  "status": "open",
  "priority": "hoog",
  "deadline": "2026-04-24T10:00:00+02:00",
  "assigneeUserId": "j.de.vries",
  "callbackPhoneNumber": "0612345678",
  "preferredTimeSlot": "Donderdag 09:00 - 11:00",
  "createdBy": "k.smit"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "task", "slug": "task-followup-lead-bv-noorderpoort" },
  "type": "opvolgtaak",
  "subject": "Opvolgen offerte BV Noorderpoort",
  "description": "Nazorg na toesturen offerte softwarelicenties, controleer of vragen zijn beantwoord",
  "status": "open",
  "priority": "normaal",
  "deadline": "2026-04-25T14:00:00+02:00",
  "assigneeUserId": "m.bakker",
  "createdBy": "m.bakker"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "task", "slug": "task-info-request-vergunning" },
  "type": "informatievraag",
  "subject": "Aanvullende informatie opvragen: bouwvergunning Brouwersgracht",
  "description": "Opvragen tekeningen en kadastrale gegevens bij aanvrager voor volledigheid dossier",
  "status": "in_behandeling",
  "priority": "normaal",
  "deadline": "2026-04-26T17:00:00+02:00",
  "assigneeGroupId": "team-vergunningen",
  "createdBy": "l.van.den.berg"
}
```

## Files Changed

### New Files
- `lib/Service/ScheduledTaskService.php`
- `lib/Controller/SchedulesController.php`
- `lib/BackgroundJob/ScheduledTaskJob.php`
- `tests/Unit/Service/ScheduledTaskServiceTest.php`

### Modified Files
- `appinfo/routes.php` — add 6 schedules routes (pending before `{id}`)
- `appinfo/info.xml` — register `ScheduledTaskJob` in `<background-jobs>`

# Tasks: task-background-jobs

## 0. Deduplication Check

- [ ] 0.1 Verify no overlap with `TaskEscalationJob` (terugbel-taakbeheer): that job runs every 15 minutes for deadline escalation and `verlopen` transitions on existing tasks. `ScheduledTaskJob` runs every 5 minutes with broader scope (notification dispatch, attempt logging, full lifecycle processing) and is the execution engine for the Schedules API. Different frequency and scope — document finding in PR description.
- [ ] 0.2 Verify no overlap with `AutomationService` / `AutomationController` (crm-workflow-automation): automations are event-driven (CRM entity change triggers). The Schedules API is time-driven (deadline-based). Different trigger mechanism — document finding.
- [ ] 0.3 Search `openspec/specs/` and `lib/Controller/` for any existing schedule or timed-task endpoint. If found, reference rather than duplicate.
- [ ] 0.4 Verify `ObjectService.findObjects`, `findObject`, `saveObject`, `deleteObject` are used with 3 positional args throughout — no 1-arg shortcuts.

## 1. Backend: ScheduledTaskService

- [ ] 1.1 Create `lib/Service/ScheduledTaskService.php`
  - SPDX header: `// SPDX-License-Identifier: EUPL-1.2`
  - File-level `@spec openspec/changes/task-background-jobs/tasks.md#task-1`
  - Constructor: inject `ObjectService`, `IAppConfig`, `IUserSession`, `NotificationService`, `LoggerInterface` — all `private readonly`
  - Read `task_register` and `task_schema` from `IAppConfig` (same pattern as existing SettingsService)

- [ ] 1.2 Implement `getScheduledTasks(array $params): array`
  - Build filter array from `$params`: `status`, `assigneeUserId`, `assigneeGroupId`, `from` (deadline ≥), `to` (deadline ≤)
  - Call `$this->objectService->findObjects($register, $taskSchema, $filterParams)` — 3 positional args
  - Apply `_page` / `_limit` pagination
  - Return `['items' => [...], 'total' => n, 'page' => p, 'pages' => pp]`

- [ ] 1.3 Implement `getScheduledTask(string $id): array`
  - Call `$this->objectService->findObject($register, $taskSchema, $id)` — 3 positional args
  - Throw `\RuntimeException('Task not found')` if null returned

- [ ] 1.4 Implement `createScheduledTask(array $data): array`
  - Validate required fields: `type` (must be terugbelverzoek/opvolgtaak/informatievraag), `subject`, `deadline`
  - Throw `\InvalidArgumentException` with static message if validation fails
  - Set `createdBy = $this->userSession->getUser()->getUID()` — NEVER from `$data`
  - Default `status = 'open'` if not provided
  - Call `$this->objectService->saveObject($register, $taskSchema, $data)` — 3 positional args
  - Return saved object

- [ ] 1.5 Implement `updateScheduledTask(string $id, array $data): array`
  - Fetch existing task with `getScheduledTask($id)`
  - Merge `$data` into existing fields — strip `createdBy` from `$data` before merge
  - Call `saveObject` with merged data — 3 positional args
  - Return updated object

- [ ] 1.6 Implement `deleteScheduledTask(string $id): void`
  - Call `$this->objectService->deleteObject($register, $taskSchema, $id)` — 3 positional args

- [ ] 1.7 Implement `getPendingTasks(int $windowMinutes = 60): array`
  - Cap `$windowMinutes` at 1440 (24 hours)
  - Build filter: `status = open`, `deadline` between now and now + `$windowMinutes`
  - Call `findObjects` with filter — 3 positional args
  - Return array of task objects

- [ ] 1.8 Implement `processScheduledTasks(): void`
  - Call `getPendingTasks(240)` — tasks due within 4 hours
  - For each task with `deadline` ≤ now and `status = open`:
    - If `deadline` > 4 hours ago: dispatch notification, set `status = in_behandeling`, append attempt `{timestamp, result: 'notified'}`
    - If `deadline` ≤ 4 hours ago (overdue): set `status = verlopen`, append attempt `{timestamp, result: 'expired'}`
    - Call `saveObject` to persist update — 3 positional args
  - Skip tasks already in `in_behandeling`, `afgerond`, or `verlopen`

- [ ] 1.9 Implement `authorizeTaskMutation(array $task, string $userId): void`
  - Allow if `$userId === $task['assigneeUserId']`
  - Allow if `$task['assigneeGroupId']` is non-empty and user is in that Nextcloud group (via `IGroupManager`)
  - Allow if user is admin (`IGroupManager::isAdmin($userId)`)
  - Otherwise throw `OCSForbiddenException` with static message `'Not authorized'`

## 2. Backend: SchedulesController

- [ ] 2.1 Create `lib/Controller/SchedulesController.php`
  - SPDX header and `@spec` PHPDoc tag
  - Extend `Controller`
  - Constructor: inject `ScheduledTaskService`, `IRequest`, `IGroupManager`, `IUserSession`, `LoggerInterface`

- [ ] 2.2 Implement `index(): JSONResponse` — `GET /api/schedules`
  - Annotate `#[NoAdminRequired]`
  - Read `status`, `assigneeUserId`, `assigneeGroupId`, `from`, `to`, `_page`, `_limit` from `$this->request`
  - Call `ScheduledTaskService.getScheduledTasks($params)`
  - Wrap in `try/catch (\Throwable $e)` → return 500 with `['message' => 'Operation failed']`, log exception

- [ ] 2.3 Implement `create(): JSONResponse` — `POST /api/schedules`
  - Annotate `#[NoAdminRequired]`
  - Read body fields from `$this->request`
  - Validate `type` is one of terugbelverzoek/opvolgtaak/informatievraag — return 400 with static message if invalid
  - Validate `subject` and `deadline` present — return 400 if missing
  - Call `ScheduledTaskService.createScheduledTask($data)` — return 201 on success
  - Catch `\InvalidArgumentException` → return 400 with `['message' => 'Invalid input']`
  - Catch `\Throwable` → return 500 with `['message' => 'Operation failed']`, log full exception

- [ ] 2.4 Implement `pending(): JSONResponse` — `GET /api/schedules/pending`
  - Annotate `#[NoAdminRequired]`
  - Read `window` (default 60, cap at 1440) from `$this->request->getParam('window', 60)`
  - Call `ScheduledTaskService.getPendingTasks((int)$window)`
  - Return 200 with `['items' => [...], 'total' => n]`

- [ ] 2.5 Implement `show(string $id): JSONResponse` — `GET /api/schedules/{id}`
  - Annotate `#[NoAdminRequired]`
  - Call `ScheduledTaskService.getScheduledTask($id)`
  - Catch `\RuntimeException` → return 404 with `['message' => 'Not found']`

- [ ] 2.6 Implement `update(string $id): JSONResponse` — `PUT /api/schedules/{id}`
  - Annotate `#[NoAdminRequired]`
  - Fetch task, call `authorizeTaskMutation($task, $currentUserId)` — catch `OCSForbiddenException` → return 403
  - Call `ScheduledTaskService.updateScheduledTask($id, $data)` — return 200
  - Catch `\Throwable` → return 500 with static message, log exception

- [ ] 2.7 Implement `destroy(string $id): JSONResponse` — `DELETE /api/schedules/{id}`
  - Annotate `#[NoAdminRequired]`
  - Fetch task, call `authorizeTaskMutation($task, $currentUserId)` — catch `OCSForbiddenException` → return 403
  - Call `ScheduledTaskService.deleteScheduledTask($id)` — return 204
  - Catch `\Throwable` → return 500 with static message, log exception

## 3. Routes

- [ ] 3.1 Add to `appinfo/routes.php` — CRITICAL: `schedules/pending` MUST be before `schedules/{id}`:
  ```php
  ['name' => 'Schedules#index',   'url' => '/api/schedules',          'verb' => 'GET'],
  ['name' => 'Schedules#create',  'url' => '/api/schedules',          'verb' => 'POST'],
  ['name' => 'Schedules#pending', 'url' => '/api/schedules/pending',  'verb' => 'GET'],
  ['name' => 'Schedules#show',    'url' => '/api/schedules/{id}',     'verb' => 'GET'],
  ['name' => 'Schedules#update',  'url' => '/api/schedules/{id}',     'verb' => 'PUT'],
  ['name' => 'Schedules#destroy', 'url' => '/api/schedules/{id}',     'verb' => 'DELETE'],
  ```
  Place BEFORE any existing wildcard `{slug}` catch-all routes.

## 4. Background Job

- [ ] 4.1 Create `lib/BackgroundJob/ScheduledTaskJob.php`
  - SPDX header: `// SPDX-License-Identifier: EUPL-1.2`
  - `@spec openspec/changes/task-background-jobs/tasks.md#task-4`
  - Extend `TimedJob`. Set interval to 300 seconds (5 minutes) in constructor: `$this->setInterval(300)`
  - Constructor: inject `ScheduledTaskService`, `LoggerInterface`

- [ ] 4.2 Implement `run(array $argument): void`
  - Call `$this->scheduledTaskService->processScheduledTasks()`
  - Wrap in `try/catch (\Throwable $e)` — log error with `$this->logger->error('ScheduledTaskJob failed', ['exception' => $e])`
  - Do NOT rethrow — background job must not crash the queue

- [ ] 4.3 Register in `appinfo/info.xml`:
  ```xml
  <background-jobs>
    <job>OCA\Pipelinq\BackgroundJob\ScheduledTaskJob</job>
  </background-jobs>
  ```

## 5. Tests

- [ ] 5.1 Create `tests/Unit/Service/ScheduledTaskServiceTest.php`
  - SPDX header
  - Test `createScheduledTask()`: verify `createdBy` is set from session (not from `$data`), and that required-field validation throws on missing `subject` or `deadline`
  - Test `getPendingTasks()`: verify window cap at 1440, and that completed/expired tasks are excluded from results
  - Test `authorizeTaskMutation()`: verify assignee is allowed, non-related user is rejected with `OCSForbiddenException`, admin is always allowed
  - Test `processScheduledTasks()`: verify due task transitions to `in_behandeling`, overdue task transitions to `verlopen`, already-processed task is skipped

## 6. Verification

- [ ] 6.1 Run `npm run build` — verify zero build errors (no frontend changes, but ensure no JS is broken)
- [ ] 6.2 Run `composer check:strict` — verify zero PHP errors or type errors
- [ ] 6.3 Smoke test `POST /api/schedules` with `curl` — verify 201 and task created with correct `createdBy`
- [ ] 6.4 Smoke test `GET /api/schedules?status=open` with `curl` — verify 200 and correct response shape with `total`, `page`, `pages`
- [ ] 6.5 Smoke test `GET /api/schedules/pending?window=60` with `curl` — verify only open tasks within 60 minutes are returned
- [ ] 6.6 Smoke test `PUT /api/schedules/{id}` as non-assignee — verify 403 with static `{ "message": "Not authorized" }`
- [ ] 6.7 Smoke test `DELETE /api/schedules/{id}` as assignee — verify 204 and subsequent GET returns 404
- [ ] 6.8 Test error paths: 401 (no auth), 400 (missing `subject`), 400 (invalid `type`), 404 (unknown ID), 403 (unauthorized mutation)
- [ ] 6.9 Verify `ScheduledTaskJob` is registered in `info.xml` and appears in Nextcloud's background job list
- [ ] 6.10 Run SPDX header check: `grep -rL 'SPDX-License-Identifier' lib/Service/ScheduledTask* lib/Controller/Schedules* lib/BackgroundJob/ScheduledTask*` — MUST return zero files
- [ ] 6.11 Verify ObjectService calls use 3 positional args: `grep -n 'findObjects\|saveObject\|findObject\|deleteObject' lib/Service/ScheduledTaskService.php` — each call MUST have 3 args
- [ ] 6.12 Verify no `$e->getMessage()` in responses: `grep -n 'getMessage()' lib/Controller/SchedulesController.php` — MUST return zero matches
- [ ] 6.13 Verify route ordering: in `appinfo/routes.php`, confirm `schedules/pending` route appears before `schedules/{id}` route

# Spec: Task Background Jobs — Schedules API

## Purpose

Expose a REST API for programmatic creation and management of scheduled CRM tasks, backed by a background job that processes due tasks, dispatches notifications, and transitions task lifecycle status. Enables external integrators (n8n, TenderNed, enterprise schedulers) to orchestrate CRM task scheduling without direct database access. Demand score: 38 (12 tender mentions, integration category).

---

## REQ-TBJ-001: Create a Scheduled Task via API [V1]

The `POST /api/schedules` endpoint MUST create a new `task` object in OpenRegister and return the created task with HTTP 201.

### Scenario: Create a callback task via API

- GIVEN an authenticated agent
- WHEN `POST /api/schedules` is called with `{ "type": "terugbelverzoek", "subject": "Terugbellen over vergunningsaanvraag", "deadline": "2026-04-25T10:00:00+02:00", "assigneeUserId": "j.de.vries", "callbackPhoneNumber": "0612345678" }`
- THEN the response status MUST be 201
- AND the response body MUST contain the created task object including a generated `id`
- AND `createdBy` MUST be set to the authenticated user's UID (derived from `IUserSession` — NEVER from the request body)
- AND the task MUST be retrievable via `GET /api/schedules/{id}`

### Scenario: Create a follow-up task with group assignment

- GIVEN an authenticated agent
- WHEN `POST /api/schedules` is called with `{ "type": "opvolgtaak", "subject": "Nazorg offerte BV Noorderpoort", "deadline": "2026-04-26T14:00:00+02:00", "assigneeGroupId": "team-verkoop" }`
- THEN the response status MUST be 201
- AND `assigneeGroupId` MUST be stored as `"team-verkoop"` on the created task

### Scenario: Missing required fields return 400

- GIVEN an authenticated agent
- WHEN `POST /api/schedules` is called without `subject` or `deadline`
- THEN the response status MUST be 400
- AND the response MUST contain a `message` field with a user-readable static error string
- AND the response MUST NOT contain stack traces, internal paths, or exception messages

### Scenario: Unauthenticated request is rejected

- GIVEN no valid Nextcloud session
- WHEN `POST /api/schedules` is called
- THEN the response status MUST be 401
- AND no task MUST be created

---

## REQ-TBJ-002: List Scheduled Tasks via API [V1]

The `GET /api/schedules` endpoint MUST return a paginated list of `task` objects with optional filters.

### Scenario: List all open scheduled tasks

- GIVEN several tasks exist with `status = open`
- WHEN `GET /api/schedules?status=open` is called by an authenticated user
- THEN the response status MUST be 200
- AND `items` MUST contain only tasks with `status = open`
- AND the response MUST include `total`, `page`, and `pages` fields (ADR-002 pagination)

### Scenario: Filter tasks by assignee

- GIVEN tasks are assigned to users `j.de.vries` and `m.bakker`
- WHEN `GET /api/schedules?assigneeUserId=j.de.vries` is called
- THEN `items` MUST contain ONLY tasks assigned to `j.de.vries`
- AND tasks assigned to `m.bakker` MUST NOT appear

### Scenario: Filter tasks by deadline range

- GIVEN tasks exist with various deadline dates
- WHEN `GET /api/schedules?from=2026-04-24&to=2026-04-30` is called
- THEN ONLY tasks with `deadline` between 2026-04-24 and 2026-04-30 (inclusive) MUST be returned

### Scenario: Pagination returns correct page

- GIVEN 35 scheduled tasks exist
- WHEN `GET /api/schedules?_limit=20&_page=2` is called
- THEN `items` MUST contain exactly 15 records
- AND `total` MUST be 35
- AND `pages` MUST be 2
- AND `page` MUST be 2

---

## REQ-TBJ-003: Query Pending Tasks Within a Time Window [V1]

The `GET /api/schedules/pending` endpoint MUST return all tasks with `status = open` whose `deadline` falls within the specified time window from now.

### Scenario: Retrieve tasks due in the next 60 minutes

- GIVEN tasks exist with deadlines at T+30m, T+90m, T+3h, and T-1h (past due)
- WHEN `GET /api/schedules/pending?window=60` is called
- THEN ONLY the task with deadline at T+30m MUST be returned
- AND tasks with deadlines outside the 60-minute window MUST NOT appear
- AND the past-due task (T-1h) MUST NOT appear (it is not pending — it is overdue)

### Scenario: Default window is 60 minutes

- GIVEN tasks exist with deadlines at T+45m and T+90m
- WHEN `GET /api/schedules/pending` is called without a `window` parameter
- THEN ONLY the task due at T+45m MUST be returned

### Scenario: Maximum window capped at 1440 minutes

- GIVEN an authenticated user
- WHEN `GET /api/schedules/pending?window=9999` is called
- THEN the effective window MUST be capped at 1440 minutes (24 hours)
- AND the response MUST succeed with 200

---

## REQ-TBJ-004: Retrieve a Single Scheduled Task [V1]

The `GET /api/schedules/{id}` endpoint MUST return the full task object for the given ID.

### Scenario: Retrieve existing task by ID

- GIVEN a scheduled task exists with ID `abc123`
- WHEN `GET /api/schedules/abc123` is called by an authenticated user
- THEN the response status MUST be 200
- AND the response body MUST contain all task fields from ADR-000

### Scenario: Non-existent task returns 404

- GIVEN no task exists with ID `nonexistent-id`
- WHEN `GET /api/schedules/nonexistent-id` is called
- THEN the response status MUST be 404
- AND the response MUST contain a `message` field with a static error string
- AND the response MUST NOT contain stack traces or internal error details

---

## REQ-TBJ-005: Update a Scheduled Task via API [V1]

The `PUT /api/schedules/{id}` endpoint MUST update an existing task and enforce per-object authorization.

### Scenario: Task assignee updates their own task

- GIVEN a task assigned to `m.bakker` exists with ID `task-456`
- AND the authenticated user is `m.bakker`
- WHEN `PUT /api/schedules/task-456` is called with `{ "deadline": "2026-04-28T11:00:00+02:00", "priority": "hoog" }`
- THEN the response status MUST be 200
- AND the task's `deadline` MUST be updated to `2026-04-28T11:00:00+02:00`
- AND `createdBy` MUST NOT be modified by the request body

### Scenario: Unauthorized user cannot update another user's task

- GIVEN a task assigned to `j.de.vries` exists with ID `task-789`
- AND the authenticated user is `k.smit` who is neither the assignee, a group member, nor an admin
- WHEN `PUT /api/schedules/task-789` is called
- THEN the response status MUST be 403
- AND the response MUST contain `{ "message": "Not authorized" }`
- AND the task MUST remain unchanged

### Scenario: Admin can update any task

- GIVEN a task assigned to `j.de.vries` exists
- AND the authenticated user is a Nextcloud admin
- WHEN `PUT /api/schedules/{id}` is called with updated fields
- THEN the response status MUST be 200
- AND the task MUST be updated

---

## REQ-TBJ-006: Cancel a Scheduled Task via API [V1]

The `DELETE /api/schedules/{id}` endpoint MUST delete the task and enforce per-object authorization.

### Scenario: Assignee cancels their own scheduled task

- GIVEN a task assigned to `l.van.den.berg` exists with ID `task-321`
- AND the authenticated user is `l.van.den.berg`
- WHEN `DELETE /api/schedules/task-321` is called
- THEN the response status MUST be 204
- AND `GET /api/schedules/task-321` MUST subsequently return 404

### Scenario: Unauthorized deletion is rejected

- GIVEN a task assigned to `j.de.vries` exists
- AND the authenticated user is `p.smits` with no relation to the task
- WHEN `DELETE /api/schedules/{id}` is called
- THEN the response status MUST be 403
- AND the task MUST NOT be deleted

---

## REQ-TBJ-007: Background Job Processes Due Tasks [V1]

The `ScheduledTaskJob` MUST run at least every 5 minutes and process all tasks that have become due, transitioning lifecycle status and dispatching notifications.

### Scenario: Due task triggers assignee notification

- GIVEN a task with `status = open`, `deadline` in the past (within 4 hours), and `assigneeUserId = j.de.vries` exists
- WHEN the `ScheduledTaskJob` runs
- THEN a Nextcloud notification MUST be dispatched to `j.de.vries` referencing the task subject
- AND the task's `status` MUST transition to `in_behandeling`
- AND a new entry MUST be appended to the task's `attempts` array with a timestamp and result `"notified"`

### Scenario: Overdue task is auto-expired

- GIVEN a task with `status = open` and `deadline` more than 4 hours in the past exists
- WHEN the `ScheduledTaskJob` runs
- THEN the task's `status` MUST be updated to `verlopen`
- AND an attempt entry MUST be appended with result `"expired"`

### Scenario: Background job failure does not crash the queue

- GIVEN the `ScheduledTaskService.processScheduledTasks()` call throws an unexpected exception
- WHEN the `ScheduledTaskJob` runs
- THEN the exception MUST be caught and logged (NOT rethrown)
- AND the Nextcloud background job queue MUST continue processing other jobs

### Scenario: Background job is idempotent for already-processed tasks

- GIVEN a task with `status = in_behandeling` exists (already processed)
- WHEN the `ScheduledTaskJob` runs
- THEN the task's `status` MUST NOT be changed
- AND no duplicate notification MUST be sent

---

## REQ-TBJ-008: API Enforces Input Validation and Safe Error Responses [V1]

All Schedules API endpoints MUST return safe, static error messages and validate required input fields.

### Scenario: Invalid task type returns 400

- GIVEN an authenticated agent
- WHEN `POST /api/schedules` is called with `{ "type": "ongeldig_type", "subject": "Test", "deadline": "2026-05-01T10:00:00+02:00" }`
- THEN the response status MUST be 400
- AND the response MUST contain a `message` field
- AND the message MUST be a static string — NEVER `$e->getMessage()` or internal exception text

### Scenario: Internal service failure returns safe 500

- GIVEN OpenRegister is temporarily unavailable
- WHEN any Schedules API endpoint is called
- THEN the response status MUST be 500
- AND the response MUST contain `{ "message": "Operation failed" }` (static)
- AND the full exception MUST be logged server-side with `$this->logger->error()`
- AND no stack trace, SQL, or internal path MUST appear in the response body

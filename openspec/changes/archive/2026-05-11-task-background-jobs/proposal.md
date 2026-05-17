# Proposal: task-background-jobs

## Problem

Pipelinq has a `task` entity (terugbelverzoek, opvolgtaak, informatievraag) and a basic `TaskEscalationJob` for deadline monitoring, but no REST API that allows external systems to programmatically create and manage scheduled tasks. 12 tender evaluations (demand score: 38, integration category) explicitly require scheduled task management to be exposed via a stable API so that enterprise schedulers, n8n workflows, and TenderNed integrations can orchestrate CRM task creation without direct database access.

The existing background job (`TaskEscalationJob`) handles escalation of existing tasks approaching their deadline, but there is no background job that processes the full scheduled task lifecycle: claiming, dispatching, retrying, and completing scheduled tasks at defined execution windows. External integrators also have no way to query pending scheduled work, update schedules, or cancel tasks programmatically.

## Solution

Implement a Schedules API and supporting background job infrastructure with:

1. **ScheduledTaskService** (`lib/Service/ScheduledTaskService.php`) — schedule-aware task CRUD and processing logic using the existing `task` OpenRegister schema
2. **SchedulesController** (`lib/Controller/SchedulesController.php`) — REST API with full CRUD plus a dedicated `pending` endpoint for time-window queries
3. **ScheduledTaskJob** (`lib/BackgroundJob/ScheduledTaskJob.php`) — `ITimedJob` running every 5 minutes for processing due tasks: status transitions, notification dispatch, attempt logging
4. Integration-ready JSON response format aligned with ADR-002 (pagination, error shape)

## Scope

- Schedules REST API: `POST`, `GET`, `PUT`, `DELETE /api/schedules` and `GET /api/schedules/pending`
- Background job for processing task deadlines: due → notify, past deadline still open → auto-expire (`verlopen`), attempt logging
- Assignee notification dispatch via Nextcloud `NotificationService` when task becomes due
- Per-object authorization on all mutation endpoints (ADR-005 IDOR prevention)
- PHPUnit tests for `ScheduledTaskService` (≥ 3 methods)
- `@spec` PHPDoc tags on every new class and public method (ADR-003 traceability)

## Out of scope

- Recurring/repeating schedule patterns (V2)
- Calendar event generation from scheduled tasks (covered by email-calendar-sync change)
- Webhook push delivery of schedule events (V2)
- Frontend views for schedule management (task UI exists in terugbel-taakbeheer)
- Dry-run / preview of scheduled execution (V2)
- Cross-app schedule synchronisation with Procest (Enterprise)

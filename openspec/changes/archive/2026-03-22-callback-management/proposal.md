## Why

KCC agents handling citizen contacts often cannot resolve questions immediately and need to schedule callbacks (terugbelverzoeken) or route follow-up tasks to backoffice departments. 31% of klantinteractie tenders (16/52) explicitly require callback/task management. Pipelinq currently has no task entity, no callback workflow, and no group-based routing — agents must track callbacks outside the system.

## What Changes

- Add a new `task` (taak) schema to the pipelinq register supporting types: terugbelverzoek, opvolgtaak, informatievraag
- Create callback/task creation forms with assignment to Nextcloud users or groups
- Implement task lifecycle: open -> in_behandeling -> afgerond -> verlopen
- Add priority (hoog/normaal/laag) and deadline management with business-hours calculation
- Add group-based routing: team inboxes where any member can claim a task
- Add a background job (ITimedJob) for deadline expiry detection and escalation notifications
- Extend the existing My Work view to include tasks alongside leads and requests
- Add callback attempt logging with attempt counter and "not reached" workflow
- Integrate with existing NotificationService for assignment and escalation notifications

## Capabilities

### New Capabilities
- `callback-management`: Core callback/task CRUD, task schema, creation forms, assignment (user/group), status lifecycle, priority/deadline management, callback attempt logging, and claim mechanism
- `task-background-jobs`: ITimedJob for auto-expiry of overdue tasks and escalation notifications when deadlines approach

### Modified Capabilities
- `my-work`: Add task type to the personal inbox alongside leads and requests; extend filter buttons and counts to include tasks; show terugbelverzoek badge and detail navigation

## Impact

- **Schema**: New `task` schema added to `lib/Settings/pipelinq_register.json` (OpenAPI 3.0.0)
- **Backend**: New `TaskExpiryJob` (ITimedJob) background job; extend `NotificationService` for task notifications; extend `SchemaMapService` for task type
- **Frontend**: New `src/views/tasks/` directory with list, detail, and create views; new task store; modifications to `MyWork.vue` for task integration
- **Routes**: New API routes for task-specific operations (claim, reassign, log attempt)
- **Nextcloud integration**: OCS user/group search for assignment autocomplete; Nextcloud group API for team routing
- **Procest**: No direct impact — tasks are internal to Pipelinq (not forwarded as cases)
- **Dependencies**: No new external dependencies; uses existing OpenRegister API and Nextcloud OCP interfaces

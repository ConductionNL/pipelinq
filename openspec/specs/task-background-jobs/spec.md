---
status: done
---

## Purpose

Run the periodic background jobs that keep task state current without user interaction: a TimedJob that expires tasks past their deadline (status → "verlopen") and deadline-escalation notifications. These run in PHP cron with no UI surface and are covered by PHPUnit.

The deadline sweep is owned by `ScheduledTaskJob` (registered in `appinfo/info.xml`), which delegates to `ScheduledTaskService::processScheduledTasks()`. A separate `TaskExpiryJob` was registered historically but its `run()` only logged — it never expired anything — and it was removed in `money-and-bridge-fixes` (2026-07-16) in favour of the path that actually runs.

## ADDED Requirements

@e2e exclude backend background job — task expiry TimedJob runs in PHP cron; no UI surface; covered by PHPUnit

### Requirement: Task Expiry Background Job

The system MUST run a periodic background job that detects tasks past their deadline and updates their status to "verlopen".

**Feature tier**: MVP
**Nextcloud OCP**: OCP\BackgroundJob\TimedJob

#### Scenario: Auto-expire tasks overdue beyond the expiry cut

- **WHEN** `ScheduledTaskJob` runs and `ScheduledTaskService::processScheduledTasks()` finds a due task whose deadline is more than 4 hours in the past (the expiry cut)
- **THEN** the task status MUST be changed to "verlopen"
- AND an `expired` attempt MUST be appended to the task's `attempts` trail (system-generated, not user-initiated)
- AND an escalation notification MUST be sent to the assignee via NotificationService

#### Scenario: Warn on tasks due within the expiry cut

- **WHEN** a due task's deadline is in the past but within the 4-hour expiry cut
- **THEN** the task status MUST be changed to "in_behandeling"
- AND a `notified` attempt MUST be appended
- AND a reminder notification MUST be sent to the assignee

#### Scenario: Background job registration

- **WHEN** the Pipelinq app is installed or updated
- **THEN** `ScheduledTaskJob` MUST be registered in `appinfo/info.xml` under `<background-jobs>`
- AND the job MUST implement `OCP\BackgroundJob\TimedJob` with a 5-minute interval (300 seconds)
- AND no background job MAY be registered whose `run()` performs no work

---

### Requirement: Deadline Escalation Notifications

The system MUST send escalation notifications when task deadlines are approaching.

**Feature tier**: MVP

#### Scenario: Approaching deadline warning

- **WHEN** `ScheduledTaskService::processScheduledTasks()` finds a due task whose deadline is within the 4-hour expiry cut
- **THEN** the system MUST send a reminder notification to the assignee via NotificationService
- AND the notification MUST include the task subject and deadline
- AND the notification MUST be skipped when the task has no assignee

#### Scenario: Expired task escalation

- **WHEN** a task status changes to "verlopen" via the background job
- **THEN** the system MUST send an escalation notification to the assignee via `NotificationService::notifyTaskExpired()`
- AND the notification MUST indicate that the task has expired and requires attention
- AND a notification failure MUST be logged and swallowed so it never aborts the batch run
## Requirements
### Requirement: Background job execution — documented operations

The scheduled CRM background jobs implemented in this app MUST provide the operations enumerated in this change's tasks.md (for example `run`, `run`). Each listed method realises an observable part of scheduled CRM background jobs and MUST behave as implemented in the current codebase.

**Feature tier**: V1

#### Scenario: Documented operations are available

- GIVEN the backend service/controller is loaded
- WHEN a caller invokes one of the documented operations for scheduled CRM background jobs
- THEN the operation MUST execute and return a result consistent with the current implementation

---

### Requirement: Background job execution — results derived from current CRM state

Operations for scheduled CRM background jobs MUST read their inputs from the relevant CRM entities/configuration and compute results from that live state (no hard-coded or stubbed responses). Derivations such as formatting, aggregation, filtering and validation MUST reflect the data present at call time.

**Feature tier**: V1

#### Scenario: Results reflect live state

- GIVEN CRM data backing scheduled CRM background jobs
- WHEN a documented operation runs
- THEN its output MUST be derived from that data
- AND it MUST change when the underlying data changes

---

### Requirement: Background job execution — defensive handling of absent or invalid input

Operations for scheduled CRM background jobs MUST tolerate missing, empty, or malformed input without throwing unhandled errors — returning empty or default results, or surfacing a validation outcome as implemented, rather than crashing the surrounding flow.

**Feature tier**: V1

#### Scenario: Missing input does not crash the flow

- GIVEN an operation for scheduled CRM background jobs is called with absent or invalid input
- WHEN it executes
- THEN it MUST return a safe default or a validation result
- AND it MUST NOT raise an unhandled exception

### Requirement: Task validation and deadlines — documented operations

The task validation and deadline calculation implemented in this app MUST provide the operations enumerated in this change's tasks.md (for example `calculateDeadline`, `getDefaultDeadline`, `validateTask`). Each listed method realises an observable part of task validation and deadline calculation and MUST behave as implemented in the current codebase.

**Feature tier**: V1

#### Scenario: Documented operations are available

- GIVEN the backend service/controller is loaded
- WHEN a caller invokes one of the documented operations for task validation and deadline calculation
- THEN the operation MUST execute and return a result consistent with the current implementation

---

### Requirement: Task validation and deadlines — results derived from current CRM state

Operations for task validation and deadline calculation MUST read their inputs from the relevant CRM entities/configuration and compute results from that live state (no hard-coded or stubbed responses). Derivations such as formatting, aggregation, filtering and validation MUST reflect the data present at call time.

**Feature tier**: V1

#### Scenario: Results reflect live state

- GIVEN CRM data backing task validation and deadline calculation
- WHEN a documented operation runs
- THEN its output MUST be derived from that data
- AND it MUST change when the underlying data changes

---

### Requirement: Task validation and deadlines — defensive handling of absent or invalid input

Operations for task validation and deadline calculation MUST tolerate missing, empty, or malformed input without throwing unhandled errors — returning empty or default results, or surfacing a validation outcome as implemented, rather than crashing the surrounding flow.

**Feature tier**: V1

#### Scenario: Missing input does not crash the flow

- GIVEN an operation for task validation and deadline calculation is called with absent or invalid input
- WHEN it executes
- THEN it MUST return a safe default or a validation result
- AND it MUST NOT raise an unhandled exception


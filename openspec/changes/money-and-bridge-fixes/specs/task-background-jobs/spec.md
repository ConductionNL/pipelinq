# task-background-jobs Specification Delta

## MODIFIED Requirements

### Requirement: Task Expiry Background Job

The system MUST run a periodic background job that detects tasks past their
deadline and updates their status to "verlopen".

The sweep is owned by `ScheduledTaskJob` (registered in `appinfo/info.xml`),
which delegates to `ScheduledTaskService::processScheduledTasks()`. The
previously-registered `TaskExpiryJob` performed no work — its `run()` only
logged — and is removed. No background job may be registered whose `run()`
performs no work.

@e2e exclude backend background job — task expiry TimedJob runs in PHP cron; no UI surface; covered by PHPUnit

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

### Requirement: Deadline Escalation Notifications

The system MUST send escalation notifications when task deadlines are
approaching.

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

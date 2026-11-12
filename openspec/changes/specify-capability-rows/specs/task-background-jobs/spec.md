# task-background-jobs Specification (delta)

## Purpose

Requirements for capability matrix rows whose behaviour no requirement stated yet. Rows: `work-recurring-task`, `auto-overnight`.

## ADDED Requirements

### Requirement: A task with a repeat schedule comes back on that schedule

The system SHALL let a user give a task a repeat schedule and SHALL create the next occurrence each time the schedule comes due.

#### Scenario: A weekly task

- GIVEN a task set to repeat every Monday
- WHEN the scheduled task job runs on the next Monday
- THEN a new open task with the same title and assignee exists, due that Monday

### Requirement: Slow work runs as a background job, not in the user's request

The system SHALL run long-running work, such as deadline sweeps, recalculations and exports, as a Nextcloud background job outside the user's request.

#### Scenario: The deadline sweep runs on cron

- GIVEN open tickets with SLA deadlines
- WHEN the Nextcloud cron runs
- THEN the SLA deadline sweep job checks the deadlines
- AND no user waits on a page while it runs

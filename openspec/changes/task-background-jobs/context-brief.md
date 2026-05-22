## ADDED Requirements

### Requirement: Task Expiry Background Job

The system MUST run a periodic background job that detects tasks past their deadline and updates their status to "verlopen".

**Feature tier**: MVP
**Nextcloud OCP**: OCP\BackgroundJob\TimedJob

#### Scenario: Auto-expire overdue open tasks

- **WHEN** the TaskExpiryJob runs (every 15 minutes)
- **THEN** the system MUST query OpenRegister for all tasks with status "open" and deadline in the past
- AND each matching task MUST have its status changed to "verlopen"
- AND the status change MUST be recorded (system-generated, not user-initiated)

#### Scenario: Auto-expire overdue in-progress tasks

- **WHEN** the TaskExpiryJob runs and finds tasks with status "in_behandeling" and deadline more than 24 hours in the past
- **THEN** the system MUST change the status to "verlopen"
- AND an escalation notification MUST be sent to the assignee and the creating agent

#### Scenario: Background job registration

- **WHEN** the Pipelinq app is installed or updated
- **THEN** the TaskExpiryJob MUST be registered in `appinfo/info.xml` under `<background-jobs>`
- AND the job MUST implement `OCP\BackgroundJob\TimedJob` with a 15-minute interval (900 seconds)

---

### Requirement: Deadline Escalation Notifications

The system MUST send escalation notifications when task deadlines are approaching.

**Feature tier**: MVP

#### Scenario: Approaching deadline warning

- **WHEN** the TaskExpiryJob finds a task with status "open" or "in_behandeling" and deadline within 4 hours
- **THEN** the system MUST send a reminder notification to the assignee via NotificationService
- AND the notification MUST include the task subject, deadline, and client name (if linked)
- AND the system MUST NOT send duplicate reminders (track last reminder timestamp per task)

#### Scenario: Expired task escalation

- **WHEN** a task status changes to "verlopen" via the background job
- **THEN** the system MUST send an escalation notification to both the assignee and the creating agent
- AND the notification MUST indicate that the task has expired and requires attention

## Placement & Information Architecture

**Placement type:** `INFRA+SETTING` (compound — implement all of the following):

- **`INFRA`** — Cross-cutting infrastructure with no end-user surface (or only an internal/admin one). No menu item; backend wiring only.
- **`SETTING`** — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Beheer → Observability

**Rationale:** Job runner; status pane.  
_Source: /tmp/ia-pipelinq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

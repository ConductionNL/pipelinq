# Delta Spec: callback-management

## ADDED Requirements

### Requirement: Citizen Status Notification

The system MUST support notifying citizens about the status of their callback request via configurable outbound channels (email, MijnOverheid). Internal details (agent name, department, priority) MUST be excluded from citizen notifications.

**Feature tier**: V1
**Source**: Merged from archived terugbel-taakbeheer spec (2026-05-24)

#### Scenario: Notify citizen that callback is scheduled

- **GIVEN** a terugbelverzoek has been created for citizen "Jan de Vries" with a preferred callback time
- **WHEN** the system is configured to send citizen notifications
- **THEN** the citizen SHOULD receive a notification (via configured channel: email or MijnOverheid) confirming that a callback is scheduled
- AND the notification MUST NOT contain internal details (agent name, department, priority)
- AND the notification MUST include a reference number (task UUID prefix) and expected callback window

#### Scenario: Notify citizen that callback was attempted

- **GIVEN** a backoffice agent attempted to call back but the citizen did not answer
- **WHEN** the agent logs the attempt and selects "niet_bereikbaar"
- **THEN** the citizen SHOULD receive a notification that a callback was attempted
- AND the notification SHOULD include instructions for how to reach the municipality and office hours

#### Scenario: Notify citizen that callback is completed

- **GIVEN** a callback was successfully completed
- **WHEN** the agent marks the task as "Afgerond"
- **THEN** the citizen SHOULD receive a satisfaction survey link (if configured)
- AND the notification MUST include a summary of the resolution without internal details

---

### Requirement: Task Templates

The system MUST support predefined task templates for common callback scenarios. Templates pre-fill the task creation form and MUST be manageable by administrators.

**Feature tier**: V1
**Source**: Merged from archived terugbel-taakbeheer spec (2026-05-24)

#### Scenario: Use a template for common callback

- **GIVEN** a template "Terugbellen over vergunningsstatus" exists with predefined subject, default priority "Normaal", default assignee group "Afdeling Vergunningen", and deadline "2 werkdagen"
- **WHEN** an agent selects this template while creating a terugbelverzoek
- **THEN** the form MUST be pre-filled with the template values
- AND the agent MUST be able to override any pre-filled field
- AND the template MUST be stored as an OpenRegister object (schema: `task_template`) in the pipelinq register

#### Scenario: Manage task templates

- **GIVEN** an administrator accesses the task template settings
- **WHEN** they create a new template with name, default values, and assignee
- **THEN** the template MUST be available for all KCC agents when creating tasks
- AND templates MUST be editable and deletable by administrators

#### Scenario: Template usage statistics

- **GIVEN** 5 task templates are configured
- **WHEN** the administrator views template management
- **THEN** the system MUST display usage count per template over the past 30 days
- AND rarely used templates (0 uses in 30 days) MUST be flagged for review

---

### Requirement: Manager Task Search and Dashboard

The system MUST support org-wide task search and a manager dashboard for supervisors overseeing multiple departments. This scope extends the personal `my-work` and `Task List View` REQs with cross-department visibility.

**Feature tier**: V1
**Source**: Merged from archived terugbel-taakbeheer spec (2026-05-24)

#### Scenario: Search tasks by citizen name

- **GIVEN** 50 open tasks across the organization
- **WHEN** a manager searches for "de Vries"
- **THEN** the system MUST display all tasks linked to clients matching "de Vries"
- AND results MUST show: task type, subject, assignee, deadline, and status
- AND the search MUST respect the manager's authorization scope (only departments they oversee)

#### Scenario: Filter tasks by department

- **GIVEN** tasks assigned to various Nextcloud groups
- **WHEN** a manager filters by group "Afdeling Vergunningen"
- **THEN** only tasks with `assigneeGroupId` matching that group (or tasks claimed by its members) MUST be displayed
- AND the count per status (open/in_behandeling/afgerond/verlopen) MUST be shown

#### Scenario: Manager task dashboard

- **GIVEN** a KCC manager oversees 3 departments
- **WHEN** the manager views the task dashboard
- **THEN** the system MUST display: total open tasks, overdue tasks count, average completion time, and tasks per department
- AND the dashboard MUST highlight departments with the most overdue tasks

---

### Requirement: Deadline Business Hours and Bulk Reassignment

The system MUST calculate task deadlines respecting business hours and MUST support bulk reassignment of multiple tasks to a single user.

**Feature tier**: V1
**Source**: Merged from archived terugbel-taakbeheer spec (2026-05-24)

#### Scenario: Deadline business hours calculation

- **GIVEN** an agent creates a task on Friday at 16:00 with a 24-hour deadline
- **WHEN** the system calculates the deadline
- **THEN** the deadline MUST be set to Monday 16:00 (skipping weekend)
- AND configurable business hours (default: Monday-Friday 08:00-17:00) MUST be respected
- AND national holidays MUST be optionally configurable via IAppConfig

#### Scenario: Priority escalation on approaching deadline

- **GIVEN** a terugbelverzoek with priority "Normaal" and deadline in 2 hours
- **WHEN** the CallbackOverdueJob detects the approaching deadline
- **THEN** the task's visual priority MUST be elevated to display as "Hoog" in the inbox
- AND the original priority value MUST be preserved in the data (visual escalation only, no field mutation)
- AND the assignee MUST receive a reminder notification

#### Scenario: Bulk reassignment

- **GIVEN** 5 open tasks are assigned to agent "Petra Bakker" who is unexpectedly absent
- **WHEN** a manager selects all 5 tasks and chooses "Hertoewijzen aan" > "Mark de Groot"
- **THEN** all 5 tasks MUST be reassigned to Mark de Groot
- AND Mark MUST receive a single notification summarizing all reassigned tasks
- AND each task's attempts array MUST record the reassignment with result "hertoegewezen" and an optional reason (e.g., "Afwezigheid collega")

## REMOVED Specifications

### Capability: terugbel-taakbeheer

**Reason**: Duplicate of `callback-management`. All 9 REQs in `terugbel-taakbeheer` were either:
- Already covered by `callback-management` (Create Terugbelverzoek, Create Follow-up Task, Task Assignment and Routing, Task Status Tracking) and/or `my-work` (Overlap with My-Work scenarios), or
- Unique scope (Citizen Status Notification, Task Templates, Task Search and Filtering, Priority and Deadline Management business-hours/escalation/bulk scenarios) — these were merged into `callback-management` as ADDED Requirements above.

**Migration**: None required. The `terugbel-taakbeheer` spec was draft (`status: draft`) and had zero matching code in the lib/ scan (coverage-report.md bucket 3b). Future implementers should consult `callback-management/spec.md` for the canonical task/callback REQs.

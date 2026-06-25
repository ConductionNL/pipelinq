---
status: done
---

## Purpose

Manage callback requests (terugbelverzoeken), follow-up tasks (opvolgtaken), and information requests (informatievragen) in Pipelinq via a `task` schema mapped to VNG `InterneTaak` and Schema.org `Action`. The capability lets agents register, assign, schedule, and track these tasks through to completion.

## ADDED Requirements

@e2e exclude backend schema/register config — task schema registration is a PHP repair-step; covered by PHPUnit

### Requirement: Task Schema Registration

The system MUST register a `task` schema in the pipelinq OpenRegister register with properties supporting terugbelverzoeken, opvolgtaken, and informatievragen. The schema maps to VNG `InterneTaak` and Schema.org `Action`.

**Feature tier**: MVP
**VNG mapping**: InterneTaak (gevraagdeHandeling, toelichting, status, toegewezenAanMedewerker)
**Schema.org**: schema:Action, schema:ScheduleAction

#### Scenario: Task schema exists in register configuration

- **WHEN** the pipelinq register is imported via the repair step
- **THEN** the register MUST contain a `task` schema with the following properties:
  - `type` (string, enum: terugbelverzoek/opvolgtaak/informatievraag, required)
  - `subject` (string, required) — maps to VNG `gevraagdeHandeling`
  - `description` (string) — maps to VNG `toelichting`
  - `status` (string, enum: open/in_behandeling/afgerond/verlopen, default: open) — maps to VNG `status`
  - `priority` (string, enum: hoog/normaal/laag, default: normaal)
  - `deadline` (datetime)
  - `assigneeUserId` (string, nullable) — maps to VNG `toegewezenAanMedewerker`
  - `assigneeGroupId` (string, nullable)
  - `clientId` (string, nullable) — UUID reference to client object
  - `requestId` (string, nullable) — UUID reference to request object
  - `contactMomentSummary` (string) — context from the originating contact
  - `callbackPhoneNumber` (string, nullable) — override phone number for callback
  - `preferredTimeSlot` (string, nullable) — e.g., "Dinsdag 14:00 - 16:00"
  - `createdBy` (string) — Nextcloud user UID of creating agent
  - `completedAt` (datetime, nullable)
  - `resultText` (string, nullable) — completion summary
  - `attempts` (array) — callback attempt log entries
- AND at least one of `assigneeUserId` or `assigneeGroupId` MUST be set (validated in frontend)
- AND the schema MUST be added to `lib/Settings/pipelinq_register.json` in OpenAPI 3.0.0 format

---

### Requirement: Create Terugbelverzoek

The system MUST allow agents to create callback requests (terugbelverzoeken) with subject, assignee, priority, deadline, and optional preferred callback time.

**Feature tier**: MVP

#### Scenario: Create callback with required fields

- **WHEN** an agent fills in the task creation form with type "terugbelverzoek", subject "Terugbellen over status vergunning", assignee user "Petra Bakker", priority "Normaal", and deadline "2024-03-20 17:00"
- **THEN** the system MUST create a task object in the OpenRegister pipelinq register via the OpenRegister API
- AND the task MUST have status "open" and the creating agent stored as `createdBy`
- AND the task MUST appear in the assignee's My Work inbox

#### Scenario: Create callback assigned to group

- **WHEN** an agent creates a terugbelverzoek assigned to Nextcloud group "Afdeling Vergunningen"
- **THEN** the task MUST store the group ID in `assigneeGroupId` with `assigneeUserId` null
- AND the task MUST appear in the team inbox for all members of "Afdeling Vergunningen"

#### Scenario: Create callback with preferred time slot

- **WHEN** an agent enters a preferred callback time "Dinsdag 14:00 - 16:00"
- **THEN** the task MUST store this in the `preferredTimeSlot` property
- AND the time slot MUST be displayed prominently in a highlighted banner on the task detail view

#### Scenario: Create callback with phone number override

- **WHEN** an agent enters a callback number "+31 6 98765432" different from the client's primary phone
- **THEN** the task MUST store the number in `callbackPhoneNumber`
- AND the backoffice agent MUST see this number prominently on the task detail (not just the client's default)

#### Scenario: Create callback linked to client and request

- **WHEN** an agent creates a terugbelverzoek from a request detail page
- **THEN** the form MUST pre-fill the client reference (`clientId`) and request reference (`requestId`) from the current context
- AND the agent MUST only need to add subject, assignee, priority, and deadline

#### Scenario: Validate required fields on creation

- **WHEN** an agent attempts to save a task without subject or assignee
- **THEN** the system MUST display inline validation errors for the missing required fields
- AND the form MUST NOT submit until subject and at least one assignee (user or group) are provided
- AND the deadline MUST default to the next business day at 17:00

---

### Requirement: Create Follow-up Task

The system MUST allow agents to create generic follow-up tasks (opvolgtaak, informatievraag) for backoffice handling.

**Feature tier**: MVP

#### Scenario: Create information request task

- **WHEN** an agent creates a task with type "informatievraag", subject "Opzoeken of erfpachtregeling van toepassing is", and assigns to "Afdeling Vastgoed"
- **THEN** the system MUST create a task with type "informatievraag" in the pipelinq register
- AND the task MUST include context fields: clientId, requestId, and contactMomentSummary

#### Scenario: Create follow-up task without client

- **WHEN** an agent creates a follow-up task for an anonymous caller about a pothole report
- **THEN** the system MUST allow creating a task without a clientId (the field is optional)
- AND the task type MUST be "opvolgtaak"

---

### Requirement: Task Assignment Autocomplete

The system MUST provide an autocomplete search for assigning tasks to Nextcloud users or groups.

**Feature tier**: MVP

#### Scenario: Search users and groups

- **WHEN** an agent types "Burg" in the assignment field
- **THEN** the system MUST query the Nextcloud OCS sharees API and display matching users and groups
- AND users and groups MUST be visually distinguished with different icons (user icon vs group icon)

#### Scenario: Select group assignment

- **WHEN** an agent selects group "Afdeling Burgerzaken" from the autocomplete
- **THEN** the task form MUST set `assigneeGroupId` to the Nextcloud group ID
- AND `assigneeUserId` MUST remain null

#### Scenario: Select user assignment

- **WHEN** an agent selects user "Petra Bakker" from the autocomplete
- **THEN** the task form MUST set `assigneeUserId` to the Nextcloud user UID
- AND `assigneeGroupId` MUST remain null

---

### Requirement: Task Claim Mechanism

The system MUST allow group members to claim tasks assigned to their group, transferring ownership to themselves.

**Feature tier**: MVP

#### Scenario: Claim group task

- **WHEN** a member of "Afdeling Burgerzaken" clicks "Claim" on a task assigned to their group
- **THEN** the system MUST set `assigneeUserId` to the claiming user's UID and clear `assigneeGroupId`
- AND the task status MUST change to "in_behandeling"
- AND the task MUST move from the group inbox to the claiming user's personal My Work

#### Scenario: Concurrent claim conflict

- **WHEN** two group members attempt to claim the same task simultaneously
- **THEN** the first claim MUST succeed via OpenRegister optimistic concurrency (version check)
- AND the second claim MUST fail with a user-friendly message: "This task has already been claimed"
- AND the task list MUST refresh to reflect the current state

---

### Requirement: Task Status Lifecycle

The system MUST support tracking tasks through their lifecycle: open, in_behandeling, afgerond, verlopen.

**Feature tier**: MVP

#### Scenario: Complete a callback task

- **WHEN** a backoffice agent marks a terugbelverzoek as "Afgerond" with result text "Burger geinformeerd over doorlooptijd"
- **THEN** the task status MUST change to "afgerond"
- AND `completedAt` MUST be set to the current timestamp
- AND `resultText` MUST store the completion summary
- AND the originating agent (stored in `createdBy`) MUST receive a Nextcloud notification

#### Scenario: Reopen a completed task

- **WHEN** a KCC agent reopens a task marked as "Afgerond"
- **THEN** the status MUST change back to "open"
- AND a new deadline MUST be set (defaulting to next business day 17:00)
- AND the reopen action MUST be recorded (new attempt entry with result "heropend")

#### Scenario: Log unsuccessful callback attempt

- **WHEN** a backoffice agent logs a callback attempt with result "niet_bereikbaar"
- **THEN** the system MUST add an entry to the `attempts` array with timestamp, result, and optional notes
- AND the task MUST remain in "in_behandeling" status
- AND the attempt count MUST be displayed on the task detail
- AND after 3 unsuccessful attempts, the system MUST show a suggestion to close the task

---

### Requirement: Task Reassignment

The system MUST allow reassigning tasks to a different user or group.

**Feature tier**: MVP

#### Scenario: Reassign to different colleague

- **WHEN** an agent reassigns a task from themselves to "Mark de Groot"
- **THEN** `assigneeUserId` MUST update to Mark's UID
- AND Mark MUST receive a Nextcloud notification about the new assignment
- AND the reassignment MUST be recorded as an attempt entry with result "hertoegewezen"

#### Scenario: Reassign back to group

- **WHEN** an agent reassigns a claimed task back to group "Afdeling Vergunningen"
- **THEN** `assigneeGroupId` MUST be set to the group ID and `assigneeUserId` MUST be cleared
- AND the task MUST reappear in the group inbox

---

### Requirement: Task Detail View

The system MUST provide a detail view for tasks showing all context, status history, and action buttons.

**Feature tier**: MVP

#### Scenario: View task detail

- **WHEN** an agent navigates to a task detail view
- **THEN** the system MUST display: type badge, subject, description, status, priority, deadline, assignee, client link (if set), request link (if set), callback phone number (if set), preferred time slot (if set), created by, creation timestamp
- AND if the task is a terugbelverzoek with a `callbackPhoneNumber`, the phone number MUST be displayed in a highlighted banner
- AND if the task has a `preferredTimeSlot`, it MUST be displayed in a highlighted banner

#### Scenario: View callback attempt history

- **WHEN** an agent views a terugbelverzoek that has callback attempts logged
- **THEN** the system MUST display a chronological list of attempts with timestamp, result, and notes
- AND the total attempt count MUST be shown (e.g., "Pogingen: 2/3")

#### Scenario: Task action buttons based on status

- **WHEN** a task has status "open" and is assigned to a group
- **THEN** the detail view MUST show a "Claim" button
- **WHEN** a task has status "in_behandeling"
- **THEN** the detail view MUST show "Afgerond", "Niet bereikbaar" (for terugbelverzoek), and "Hertoewijzen" buttons
- **WHEN** a task has status "afgerond"
- **THEN** the detail view MUST show a "Heropenen" button

---

### Requirement: Task List View

The system MUST provide a list view showing all tasks the current user can access.

**Feature tier**: MVP

#### Scenario: Personal task list

- **WHEN** an agent navigates to the Tasks section
- **THEN** the system MUST display all tasks where `assigneeUserId` matches the current user
- AND tasks assigned to groups the user belongs to MUST also be shown (group inbox)
- AND the list MUST be sorted by deadline ascending (soonest first), with overdue tasks at the top

#### Scenario: Task list card layout

- **WHEN** tasks are displayed in the list
- **THEN** each task card MUST show: type badge (Terugbelverzoek/Opvolgtaak/Informatievraag), subject, assignee, deadline, priority badge, status badge
- AND overdue tasks MUST have a red visual indicator

#### Scenario: Filter tasks by type and status

- **WHEN** the agent uses the filter controls on the task list
- **THEN** the system MUST support filtering by task type (all/terugbelverzoek/opvolgtaak/informatievraag) and status (all/open/in_behandeling/afgerond/verlopen)

---

### Requirement: Task Notification Integration

The system MUST send Nextcloud notifications for task assignment, completion, and escalation events.

**Feature tier**: MVP

#### Scenario: Notification on task assignment

- **WHEN** a task is assigned to a specific user
- **THEN** the assignee MUST receive a Nextcloud notification via NotificationService with subject, deadline, and client name (if linked)

#### Scenario: Notification on task completion

- **WHEN** a task is marked as "Afgerond"
- **THEN** the creating agent (`createdBy`) MUST receive a notification that the callback/task was completed
- AND the notification MUST include the result text summary

#### Scenario: Notification on task reassignment

- **WHEN** a task is reassigned to a new user
- **THEN** the new assignee MUST receive a notification about the reassignment
- AND the notification MUST include the task subject and deadline
# Delta Spec: callback-management

## ADDED Requirements

### Requirement: Callback Controller API

The system MUST provide a `CallbackController` with endpoints for callback-specific operations: logging callback attempts, claiming group tasks, completing callbacks, and reassigning tasks.

**Feature tier**: MVP
**Schema.org**: schema:ScheduleAction
**VNG mapping**: InterneTaak (gevraagdeHandeling, status, toegewezenAanMedewerker)

#### Scenario: Log callback attempt via API

- **WHEN** an agent POSTs to `/api/callbacks/{id}/attempts` with result "niet_bereikbaar" and optional notes
- **THEN** the controller MUST append an attempt entry to the task's `attempts` array with timestamp, result, and notes
- AND the response MUST include the updated task object with the new attempt count

#### Scenario: Claim group task via API

- **WHEN** an agent POSTs to `/api/callbacks/{id}/claim`
- **THEN** the controller MUST set `assigneeUserId` to the current user and clear `assigneeGroupId`
- AND the task status MUST change to "in_behandeling"
- AND the response MUST return the updated task

#### Scenario: Complete callback via API

- **WHEN** an agent POSTs to `/api/callbacks/{id}/complete` with a `resultText` body
- **THEN** the controller MUST set status to "afgerond", `completedAt` to current timestamp, and store the `resultText`
- AND the controller MUST trigger a notification to the `createdBy` user via NotificationService

#### Scenario: Reassign task via API

- **WHEN** an agent POSTs to `/api/callbacks/{id}/reassign` with `assignee` and `assigneeType` ("user" or "group")
- **THEN** the controller MUST update the assignment fields and record a "hertoegewezen" attempt entry
- AND the controller MUST trigger a notification to the new assignee via NotificationService

---

### Requirement: Callback Service

The system MUST provide a `CallbackService` that encapsulates callback business logic: attempt logging, status transitions, claim validation, and attempt threshold checks.

**Feature tier**: MVP

#### Scenario: Add attempt to callback

- **WHEN** `addAttempt()` is called with a task data array, result string, and optional notes
- **THEN** the service MUST append an entry to the `attempts` array with keys: `timestamp` (ISO 8601), `result`, `notes`, `agentUserId`
- AND the service MUST return the modified task data array

#### Scenario: Check attempt threshold

- **WHEN** `isAttemptThresholdReached()` is called with a task that has 3 or more unsuccessful attempts
- **THEN** the service MUST return true
- AND the controller layer MUST include a `suggestClose: true` flag in the API response

#### Scenario: Validate claim eligibility

- **WHEN** `validateClaim()` is called for a task assigned to a group
- **THEN** the service MUST verify the current user belongs to the assigned group via IGroupManager
- AND return `{eligible: true}` if the user is a member, or `{eligible: false, reason: "..."}` otherwise

#### Scenario: Validate status transition

- **WHEN** `validateStatusTransition()` is called with current status "open" and target "afgerond"
- **THEN** the service MUST reject the transition (open cannot skip to afgerond)
- AND the allowed transitions MUST be: open->in_behandeling, in_behandeling->afgerond, in_behandeling->verlopen, afgerond->open (reopen), verlopen->open (reopen)

---

### Requirement: Callback Overdue Check Job

The system MUST provide a `CallbackOverdueJob` background job that checks for overdue callbacks and sends reminder notifications.

**Feature tier**: MVP

#### Scenario: Detect overdue callbacks

- **WHEN** the job runs on its 15-minute interval
- **THEN** it MUST query OpenRegister for tasks with type "terugbelverzoek", status in ["open", "in_behandeling"], and deadline in the past
- AND for each overdue task, it MUST send a notification to the assignee (or group members) via NotificationService

#### Scenario: Skip already-notified tasks

- **WHEN** the job finds an overdue callback that was already notified in the current 24-hour window
- **THEN** it MUST NOT send a duplicate notification
- AND tracking of notification timestamps MUST use IAppConfig with key pattern `callback_notified_{taskId}`

---

### Requirement: Register Schema Update for Callbacks

The system MUST ensure the `task` schema in `pipelinq_register.json` includes all callback-specific properties as defined in the existing callback-management spec.

**Feature tier**: MVP

#### Scenario: Task schema includes callback fields

- **WHEN** the pipelinq register is imported
- **THEN** the `task` schema MUST include properties: `callbackPhoneNumber` (string, nullable), `preferredTimeSlot` (string, nullable), `attempts` (array, default []), `completedAt` (datetime, nullable), `resultText` (string, nullable)
- AND existing properties (`type`, `subject`, `status`, `priority`, `deadline`, `assigneeUserId`, `assigneeGroupId`, `clientId`, `requestId`, `contactMomentSummary`, `createdBy`) MUST remain unchanged

---

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
## Requirements
### Requirement: Callback UI — documented operations

The callback call-timer component implemented in this app MUST provide the operations enumerated in this change's tasks.md (for example `formattedTime`, `isoDuration`, `reset`, `start`, `stop`). Each listed method realises an observable part of callback call-timer component and MUST behave as implemented in the current codebase.

**Feature tier**: V1

#### Scenario: Documented operations are available

- GIVEN the frontend component/store is loaded
- WHEN a caller invokes one of the documented operations for callback call-timer component
- THEN the operation MUST execute and return a result consistent with the current implementation

---

### Requirement: Callback UI — results derived from current CRM state

Operations for callback call-timer component MUST read their inputs from the relevant CRM entities/configuration and compute results from that live state (no hard-coded or stubbed responses). Derivations such as formatting, aggregation, filtering and validation MUST reflect the data present at call time.

**Feature tier**: V1

#### Scenario: Results reflect live state

- GIVEN CRM data backing callback call-timer component
- WHEN a documented operation runs
- THEN its output MUST be derived from that data
- AND it MUST change when the underlying data changes

---

### Requirement: Callback UI — defensive handling of absent or invalid input

Operations for callback call-timer component MUST tolerate missing, empty, or malformed input without throwing unhandled errors — returning empty or default results, or surfacing a validation outcome as implemented, rather than crashing the surrounding flow.

**Feature tier**: V1

#### Scenario: Missing input does not crash the flow

- GIVEN an operation for callback call-timer component is called with absent or invalid input
- WHEN it executes
- THEN it MUST return a safe default or a validation result
- AND it MUST NOT raise an unhandled exception


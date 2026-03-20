# Terugbel- en Taakbeheer Specification

## Purpose

Terugbel- en taakbeheer (callback and task management) enables KCC agents to create callback requests (terugbelverzoeken) and follow-up tasks when a citizen question cannot be resolved immediately. Tasks are assigned to backoffice colleagues with priority and deadline, tracked through completion, and optionally trigger status notifications to the citizen. **31% of klantinteractie-tenders** (16/52) explicitly require callback/task management.

**Note**: This spec has intentional overlap with the `my-work` spec, which covers the personal task view for all Pipelinq users. This spec focuses specifically on KCC-originated terugbelverzoeken and backoffice routing, while `my-work` provides the generic task inbox. Both specs share the underlying task data model.

**Standards**: VNG Klantinteracties (`InterneTaak`), Schema.org (`Action`, `ScheduleAction`)
**Feature tier**: MVP (core callbacks), V1 (SLA tracking, notifications)
**Tender frequency**: 16/52 (31%)

## Data Model

Terugbelverzoeken and follow-up tasks are stored as OpenRegister objects in the `pipelinq` register:
- **Taak**: type (terugbelverzoek/opvolgtaak/informatievraag), subject, description, client reference, zaak reference, contactmoment reference, assignee (user or group), priority (hoog/normaal/laag), deadline, status (open/in_behandeling/afgerond/verlopen), created by, created at, preferred callback time slot, result text, completion timestamp
- **Relation to my-work**: Tasks created here appear in the assignee's `my-work` inbox alongside leads and requests
- **Relation to VNG**: Maps to `InterneTaak` entity with `gevraagdeHandeling`, `toelichting`, `status`, `toegewezenAanMedewerker`

## Requirements

---

### Requirement: Create Terugbelverzoek

The system MUST allow KCC agents to create callback requests during or after a contact, capturing who should call back, when, and why.

**Feature tier**: MVP

#### Scenario: Create callback from active contact

- GIVEN an agent handling a phone contact for citizen "Jan de Vries" about zaak "Bouwvergunning #2024-001"
- WHEN the agent clicks "Terugbelverzoek aanmaken" and fills in: onderwerp "Terugbellen over status vergunning", toelichting "Burger wil update over doorlooptijd, dossiernummer 2024-001", toewijzen aan "Afdeling Vergunningen", prioriteit "Normaal", terugbellen voor "2024-03-20 17:00"
- THEN the system MUST create a taak object with type "terugbelverzoek" in the OpenRegister `pipelinq` register
- AND the taak MUST be linked to the client, contactmoment, and zaak via UUID references
- AND the taak MUST appear in the "Afdeling Vergunningen" team inbox
- AND the taak MUST store the creating agent's Nextcloud user UID as `createdBy`

#### Scenario: Create callback assigned to specific colleague

- GIVEN an agent handling a follow-up where colleague "Petra Bakker" has prior context
- WHEN the agent creates a terugbelverzoek assigned to "Petra Bakker" with priority "Hoog" and deadline tomorrow 10:00
- THEN the taak MUST appear in Petra Bakker's personal `my-work` inbox
- AND Petra Bakker MUST receive a Nextcloud notification via `NotificationService` about the new callback
- AND the notification MUST include the citizen name, phone number, subject, and deadline

#### Scenario: Create callback with preferred call time

- GIVEN a citizen requests to be called back "dinsdag tussen 14:00 en 16:00"
- WHEN the agent creates the terugbelverzoek with preferred time slot noted
- THEN the taak MUST store the preferred time slot in a dedicated `preferredTimeSlot` property
- AND the backoffice agent MUST see this preference prominently in a highlighted banner when viewing the task
- AND the preferred time slot MUST be formatted as "Dinsdag 14:00 - 16:00" in the task detail view

#### Scenario: Create callback with citizen phone number override

- GIVEN a citizen calls from a different number than what is on file
- WHEN the agent creates a terugbelverzoek and enters the callback number "+31 6 98765432"
- THEN the taak MUST store the callback number separately from the client's primary phone
- AND the backoffice agent MUST see the callback number prominently (not just the client's default phone)

#### Scenario: Validate required fields

- GIVEN an agent attempts to create a terugbelverzoek
- WHEN the agent tries to save without filling in the subject or assignee
- THEN the system MUST display inline validation errors for missing required fields
- AND the form MUST NOT submit until subject and assignee are provided
- AND the deadline field MUST default to the next business day at 17:00

---

### Requirement: Create Follow-up Task

The system MUST allow agents to create generic follow-up tasks (not just callbacks) for backoffice handling.

**Feature tier**: MVP

#### Scenario: Create information request task

- GIVEN an agent needs the backoffice to research a policy question before calling the citizen
- WHEN the agent creates a taak with type "Informatievraag", subject "Opzoeken of erfpachtregeling van toepassing is", and assigns to "Afdeling Vastgoed"
- THEN the system MUST create a taak with type "informatievraag"
- AND the taak MUST include all context: client UUID, zaak reference, contactmoment summary text
- AND the taak MUST appear in the assigned team's inbox

#### Scenario: Create follow-up task without client

- GIVEN an anonymous caller reported a pothole at "Keizersgracht ter hoogte van nr. 100"
- WHEN the agent creates a follow-up task assigned to "Afdeling Beheer Openbare Ruimte"
- THEN the system MUST allow creating a taak without a client reference (client field is optional)
- AND the taak MUST be created with the location and description information
- AND the task type MUST be "opvolgtaak"

#### Scenario: Create follow-up task from existing request

- GIVEN an agent is viewing request "Aanvraag parkeervergunning #2024-050" for client "Maria Jansen"
- WHEN the agent clicks "Opvolgtaak aanmaken" on the request detail page
- THEN the system MUST pre-fill the task form with the request title, client reference, and request UUID
- AND the agent MUST only need to add the assignee, priority, and deadline
- AND the created task MUST be linked to the originating request

---

### Requirement: Task Assignment and Routing

The system MUST support assigning tasks to individual users or groups/departments, with re-assignment capability.

**Feature tier**: MVP

#### Scenario: Assign to department (group)

- GIVEN a terugbelverzoek needs to go to "Afdeling Burgerzaken"
- WHEN the agent selects the Nextcloud group "Afdeling Burgerzaken" in the assignment field
- THEN the taak MUST appear in the shared inbox for all members of that Nextcloud group
- AND any group member MUST be able to claim the task (changing status to "in_behandeling")
- AND claiming MUST assign the task to the claiming user and remove it from the group inbox

#### Scenario: Reassign task to different colleague

- GIVEN a backoffice agent "Petra Bakker" has claimed a terugbelverzoek but realizes colleague "Mark de Groot" has better context
- WHEN Petra reassigns the task to "Mark de Groot"
- THEN the taak assignee MUST update to Mark de Groot
- AND Mark MUST receive a Nextcloud notification
- AND the reassignment MUST be recorded in the task history with reason and timestamp
- AND Petra MUST be removed as the active handler

#### Scenario: Escalate overdue task

- GIVEN a terugbelverzoek with deadline "2024-03-18 17:00" that is unclaimed at 2024-03-18 12:00
- WHEN the deadline approaches (configurable threshold, e.g., 4 hours before)
- THEN the system MUST send an escalation notification to the group manager via `NotificationService`
- AND the task priority MUST be visually elevated in the inbox (e.g., red border, "Bijna verlopen" badge)
- AND the escalation check MUST run via a Nextcloud background job (ITimedJob) every 15 minutes

#### Scenario: Assignment autocomplete search

- GIVEN an agent is creating a task and needs to assign it
- WHEN the agent types "Burg" in the assignment field
- THEN the system MUST display matching Nextcloud users and groups (e.g., "Afdeling Burgerzaken" group, "Jan Burgerhout" user)
- AND users and groups MUST be visually distinguished (icon differentiation)
- AND the search MUST query the Nextcloud user/group backend via OCS API

#### Scenario: Bulk reassignment

- GIVEN 5 open tasks are assigned to agent "Petra Bakker" who is unexpectedly absent
- WHEN a manager selects all 5 tasks and chooses "Hertoewijzen aan" > "Mark de Groot"
- THEN all 5 tasks MUST be reassigned to Mark de Groot
- AND Mark MUST receive a single notification summarizing all reassigned tasks
- AND each task's history MUST record the reassignment with reason "Afwezigheid collega"

---

### Requirement: Task Status Tracking

The system MUST support tracking tasks through their lifecycle: open, in_behandeling (in progress), afgerond (completed), verlopen (expired).

**Feature tier**: MVP

#### Scenario: Complete a callback task

- GIVEN a backoffice agent has called back citizen "Jan de Vries" successfully
- WHEN the agent marks the terugbelverzoek as "Afgerond" with result "Burger geinformeerd over doorlooptijd, verwacht besluit week 14"
- THEN the taak status MUST change to "afgerond"
- AND the completion timestamp and result text MUST be stored
- AND the originating KCC agent MUST receive a notification that the callback was completed

#### Scenario: Task expires past deadline

- GIVEN a terugbelverzoek with deadline "2024-03-18 17:00" that is still "open" at 2024-03-19 00:00
- WHEN the Nextcloud background job checks for expired tasks
- THEN the taak status MUST change to "verlopen"
- AND an escalation notification MUST be sent to the group manager and the originating agent
- AND the task MUST remain visible in the inbox with a prominent "Verlopen" badge in red

#### Scenario: Reopen a completed task

- GIVEN a terugbelverzoek marked as "Afgerond" but the citizen calls back saying they were not contacted
- WHEN the KCC agent reopens the task
- THEN the status MUST change back to "open"
- AND the reopening MUST be recorded in the task history with reason
- AND a new deadline MUST be set (defaulting to next business day 17:00)

#### Scenario: Log unsuccessful callback attempt

- GIVEN a backoffice agent attempts to call back but the citizen does not answer
- WHEN the agent logs the attempt with status "Niet bereikbaar"
- THEN the task MUST remain in "in_behandeling" status
- AND the attempt MUST be recorded with timestamp and result "Niet bereikbaar"
- AND the system MUST track the number of callback attempts (attempt counter)
- AND after 3 unsuccessful attempts, the system MUST suggest changing status to "Afgerond" with result "Burger niet bereikt na 3 pogingen"

#### Scenario: View task status history

- GIVEN a task has been through multiple status changes (open -> in_behandeling -> afgerond -> heropend -> in_behandeling -> afgerond)
- WHEN an agent views the task detail
- THEN the system MUST display a chronological status history showing each transition with timestamp, actor, and reason
- AND the history MUST be displayed in a collapsible timeline component

---

### Requirement: Priority and Deadline Management

The system MUST support priority levels and deadlines for tasks, with visual indicators and sorting in the inbox.

**Feature tier**: MVP

#### Scenario: High-priority task visual distinction

- GIVEN a terugbelverzoek with priority "Hoog" and deadline today
- WHEN a backoffice agent views their inbox
- THEN the task MUST be displayed at the top of the list
- AND the task MUST have a visual indicator (red priority badge, matching the pattern from `MyWork.vue` `getPriorityColor`)
- AND the deadline MUST be displayed with urgency indication ("Vandaag, 17:00")

#### Scenario: Sort inbox by deadline

- GIVEN 10 tasks with various deadlines and priorities
- WHEN the agent sorts by deadline ascending
- THEN tasks MUST be ordered by nearest deadline first
- AND overdue tasks MUST appear at the very top regardless of sort order, grouped under an "Overdue" header (matching the `MyWork.vue` temporal grouping pattern)

#### Scenario: Priority escalation on approaching deadline

- GIVEN a terugbelverzoek with priority "Normaal" and deadline in 2 hours
- WHEN the background job detects the approaching deadline
- THEN the task's visual priority MUST be automatically elevated to display as "Hoog" in the inbox
- AND the original priority MUST be preserved in the data (visual escalation only)
- AND the assignee MUST receive a reminder notification

#### Scenario: Deadline business hours calculation

- GIVEN an agent creates a task on Friday at 16:00 with a 24-hour deadline
- WHEN the system calculates the deadline
- THEN the deadline MUST be set to Monday 16:00 (skipping weekend)
- AND configurable business hours (default: Monday-Friday 08:00-17:00) MUST be respected
- AND national holidays MUST be optionally configurable

---

### Requirement: Citizen Status Notification

The system MUST support notifying citizens about the status of their callback request.

**Feature tier**: V1

#### Scenario: Notify citizen that callback is scheduled

- GIVEN a terugbelverzoek has been created for citizen "Jan de Vries" with a preferred callback time
- WHEN the system is configured to send citizen notifications
- THEN the citizen SHOULD receive a notification (via configured channel: email or portal message) confirming that a callback is scheduled
- AND the notification MUST NOT contain internal details (agent name, department, priority)
- AND the notification MUST include a reference number (task UUID prefix) and expected callback window

#### Scenario: Notify citizen that callback was attempted

- GIVEN a backoffice agent attempted to call back but the citizen did not answer
- WHEN the agent logs the attempt and selects "Niet bereikbaar"
- THEN the citizen SHOULD receive a notification that a callback was attempted
- AND the notification SHOULD include instructions for how to reach the municipality and office hours

#### Scenario: Notify citizen that callback is completed

- GIVEN a callback was successfully completed
- WHEN the agent marks the task as "Afgerond"
- THEN the citizen SHOULD receive a satisfaction survey link (if configured)
- AND the notification MUST include a summary of the resolution without internal details

---

### Requirement: Overlap with My-Work

Tasks created via terugbel-taakbeheer MUST integrate seamlessly with the existing `my-work` spec.

**Feature tier**: MVP

#### Scenario: Terugbelverzoek appears in my-work inbox

- GIVEN a terugbelverzoek is assigned to agent "Petra Bakker"
- WHEN Petra opens her `my-work` personal inbox
- THEN the terugbelverzoek MUST appear alongside her other tasks (leads, requests, etc.)
- AND the task MUST be identifiable as type "Terugbelverzoek" with a distinct badge (matching the `entity-badge` pattern in `MyWork.vue`)
- AND clicking the task MUST open the terugbelverzoek detail view with full context (client, zaak, contactmoment)

#### Scenario: Filter my-work by task type

- GIVEN Petra has 5 terugbelverzoeken, 3 lead follow-ups, and 2 request tasks
- WHEN she filters her `my-work` inbox by type "Terugbelverzoek"
- THEN only the 5 terugbelverzoeken MUST be displayed
- AND the filter buttons MUST extend the existing `filter-buttons` pattern in `MyWork.vue` to include "Tasks" alongside "Leads" and "Requests"

#### Scenario: My-work count includes tasks

- GIVEN Petra has 5 leads, 3 requests, and 4 tasks
- WHEN the my-work header is displayed
- THEN the counts MUST read: "Leads (5) - Requests (3) - Tasks (4) -- 12 items total"
- AND the overdue grouping MUST include tasks with passed deadlines

---

### Requirement: Task Search and Filtering

The system MUST support searching and filtering tasks across the organization for managers and supervisors.

**Feature tier**: V1

#### Scenario: Search tasks by citizen name

- GIVEN 50 open tasks across the organization
- WHEN a manager searches for "de Vries"
- THEN the system MUST display all tasks linked to clients matching "de Vries"
- AND results MUST show: task type, subject, assignee, deadline, and status

#### Scenario: Filter tasks by department

- GIVEN tasks assigned to various departments
- WHEN a manager filters by "Afdeling Vergunningen"
- THEN only tasks assigned to that Nextcloud group (or its members) MUST be displayed
- AND the count per status (open/in behandeling/afgerond/verlopen) MUST be shown

#### Scenario: Task dashboard for managers

- GIVEN a KCC manager oversees 3 departments
- WHEN the manager views the task dashboard
- THEN the system MUST display: total open tasks, overdue tasks count, average completion time, and tasks per department
- AND the dashboard MUST highlight departments with the most overdue tasks

---

### Requirement: Task Templates

The system MUST support predefined task templates for common callback scenarios.

**Feature tier**: V1

#### Scenario: Use a template for common callback

- GIVEN a template "Terugbellen over vergunningsstatus" exists with predefined subject, default priority "Normaal", default assignee group "Afdeling Vergunningen", and deadline "2 werkdagen"
- WHEN an agent selects this template while creating a terugbelverzoek
- THEN the form MUST be pre-filled with the template values
- AND the agent MUST be able to override any pre-filled field
- AND the template MUST be stored as a configuration object in the pipelinq register

#### Scenario: Manage task templates

- GIVEN an administrator accesses the task template settings
- WHEN they create a new template with name, default values, and assignee
- THEN the template MUST be available for all KCC agents when creating tasks
- AND templates MUST be editable and deletable by administrators

#### Scenario: Template usage statistics

- GIVEN 5 task templates are configured
- WHEN the administrator views template management
- THEN the system MUST display usage count per template over the past 30 days
- AND rarely used templates MUST be flagged for review

---

## Appendix

### Current Implementation Status

**Implemented:**
- Nothing from this spec is implemented. There is no task/terugbelverzoek entity, no callback workflow, and no backoffice routing system.

**Not yet implemented:**
- **Create Terugbelverzoek:** No `taak` schema in `pipelinq_register.json`. No callback creation form or API.
- **Create Follow-up Task:** No generic follow-up task entity separate from leads and requests.
- **Task Assignment and Routing:** No group/department assignment. No Nextcloud group inbox concept. No claim mechanism.
- **Task Status Tracking:** No task status lifecycle (open, in_behandeling, afgerond, verlopen). No auto-expiry for missed deadlines.
- **Priority and Deadline Management:** While lead/request priorities exist (enum: low/normal/high/urgent), there is no task-specific priority/deadline system with escalation.
- **Citizen Status Notification:** No outbound citizen notification system (email, MijnOverheid, SMS).
- **Overlap with My-Work:** The My Work view (`MyWork.vue`) exists but only shows leads and requests. It does not include tasks, terugbelverzoeken, or support filtering by task type.
- **Group-based routing:** No Nextcloud group integration for team inboxes.
- **Escalation notifications:** No automated escalation when deadlines approach.
- **Task history/audit trail:** While the audit trail plugin exists, there is no task-specific history for reassignments and status changes.

**Partial implementations:**
- The My Work view provides the "personal inbox" pattern that terugbelverzoeken should integrate with. The temporal grouping (overdue/today/this week/later), priority sorting with `getPriorityColor`, and overdue detection patterns could be reused.
- The notification infrastructure (`NotificationService` + `Notifier.php`) could be extended to support task assignment and escalation notifications.
- The request channel system (`SystemTagService`, `SystemTagCrudService`) provides a pattern for task type configuration.
- Lead/request entities have `assignee`, `priority`, and `status` properties that establish the pattern for task properties.

### Competitor Comparison

- **EspoCRM**: Activities module with Tasks, Calls, Meetings. Tasks have priority, deadline, status, and assignment. Stream panel shows activity feed per record. No KCC-specific callback workflow.
- **Twenty**: Activity tracking via timeline with tasks and notes. No dedicated callback management or group-based routing.
- **Krayin**: Activities module with calls, meetings, notes linked to leads/persons. No task management separate from activities.
- **Pipelinq advantage**: VNG `InterneTaak` compliance, Nextcloud group integration for department routing, integration with existing My Work inbox pattern.

### Standards & References
- **VNG Klantinteracties:** `InterneTaak` entity from the VNG API specification for internal task management in municipalities.
- **Schema.org:** `Action` and `ScheduleAction` types for task modeling.
- **Common Ground:** Task management is a core component of KCC workflows in Dutch municipal IT architecture.
- **MijnOverheid:** Dutch government citizen portal for status notifications (V1 feature).

### Specificity Assessment
- The spec is well-structured with clear scenarios for callback creation, assignment routing, status tracking, and priority management.
- **Implementation complexity is high:** Requires new schema, group-based routing, deadline monitoring (background job), escalation logic, and citizen notification channels.
- **Resolved design decisions:**
  - Departments are modeled as **Nextcloud groups** -- the assignment field searches both users and groups via OCS API.
  - The `taak` entity is a **new schema** in the `pipelinq` register (not a reuse of `request`) because its lifecycle (open/in_behandeling/afgerond/verlopen) differs from request (new/in_progress/completed/rejected/converted).
  - Auto-expiry runs via a `Nextcloud ITimedJob` background job every 15 minutes.
  - The "claim" mechanism uses optimistic concurrency via OpenRegister's version field -- claim fails if another user claimed simultaneously.
- **Open questions:**
  - What notification channels are supported for citizen notifications? Email is the MVP channel; MijnOverheid/SMS require external integrations via OpenConnector.
  - Should task templates be stored as OpenRegister objects or `IAppConfig` settings? Recommendation: OpenRegister objects for flexibility.

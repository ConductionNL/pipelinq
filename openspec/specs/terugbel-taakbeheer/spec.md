# Terugbel- en Taakbeheer Specification

## Purpose

Terugbel- en taakbeheer (callback and task management) enables KCC agents to create callback requests (terugbelverzoeken) and follow-up tasks when a citizen question cannot be resolved immediately. Tasks are assigned to backoffice colleagues with priority and deadline, tracked through completion, and optionally trigger status notifications to the citizen. **31% of klantinteractie-tenders** (16/52) explicitly require callback/task management.

**Note**: This spec has intentional overlap with the `my-work` spec, which covers the personal task view for all Pipelinq users. This spec focuses specifically on KCC-originated terugbelverzoeken and backoffice routing, while `my-work` provides the generic task inbox. Both specs share the underlying task data model.

**Standards**: VNG Klantinteracties (`InterneTaak`), Schema.org (`Action`, `ScheduleAction`)
**Feature tier**: MVP (core callbacks), V1 (SLA tracking, notifications)
**Tender frequency**: 16/52 (31%)

## Data Model

Terugbelverzoeken and follow-up tasks are stored as OpenRegister objects in the `pipelinq` register:
- **Taak**: type (terugbelverzoek/opvolgtaak/informatievraag), subject, description, client reference, zaak reference, contactmoment reference, assignee (user or group), priority (hoog/normaal/laag), deadline, status (open/in_behandeling/afgerond/verlopen), created by, created at
- **Relation to my-work**: Tasks created here appear in the assignee's `my-work` inbox

## Requirements

---

### Requirement: Create Terugbelverzoek

The system MUST allow KCC agents to create callback requests during or after a contact, capturing who should call back, when, and why.

**Feature tier**: MVP

#### Scenario: Create callback from active contact

- GIVEN an agent handling a phone contact for citizen "Jan de Vries" about zaak "Bouwvergunning #2024-001"
- WHEN the agent clicks "Terugbelverzoek aanmaken" and fills in: onderwerp "Terugbellen over status vergunning", toelichting "Burger wil update over doorlooptijd, dossiernummer 2024-001", toewijzen aan "Afdeling Vergunningen", prioriteit "Normaal", terugbellen voor "2024-03-20 17:00"
- THEN the system MUST create a taak object with type "terugbelverzoek"
- AND the taak MUST be linked to the client, contactmoment, and zaak
- AND the taak MUST appear in the "Afdeling Vergunningen" team inbox

#### Scenario: Create callback assigned to specific colleague

- GIVEN an agent handling a follow-up where colleague "Petra Bakker" has prior context
- WHEN the agent creates a terugbelverzoek assigned to "Petra Bakker" with priority "Hoog" and deadline tomorrow 10:00
- THEN the taak MUST appear in Petra Bakker's personal `my-work` inbox
- AND Petra Bakker MUST receive a Nextcloud notification about the new callback
- AND the notification MUST include the citizen name, phone number, subject, and deadline

#### Scenario: Create callback with preferred call time

- GIVEN a citizen requests to be called back "dinsdag tussen 14:00 en 16:00"
- WHEN the agent creates the terugbelverzoek with preferred time slot noted
- THEN the taak MUST store the preferred time slot in the description or metadata
- AND the backoffice agent MUST see this preference prominently when viewing the task

---

### Requirement: Create Follow-up Task

The system MUST allow agents to create generic follow-up tasks (not just callbacks) for backoffice handling.

**Feature tier**: MVP

#### Scenario: Create information request task

- GIVEN an agent needs the backoffice to research a policy question before calling the citizen
- WHEN the agent creates a taak with type "Informatievraag", subject "Opzoeken of erfpachtregeling van toepassing is", and assigns to "Afdeling Vastgoed"
- THEN the system MUST create a taak with type "informatievraag"
- AND the taak MUST include all context: client, zaak reference, contactmoment summary
- AND the taak MUST appear in the assigned team's inbox

#### Scenario: Create follow-up task without client

- GIVEN an anonymous caller reported a pothole at "Keizersgracht ter hoogte van nr. 100"
- WHEN the agent creates a follow-up task assigned to "Afdeling Beheer Openbare Ruimte"
- THEN the system MUST allow creating a taak without a client reference
- AND the taak MUST be created with the location and description information

---

### Requirement: Task Assignment and Routing

The system MUST support assigning tasks to individual users or groups/departments, with re-assignment capability.

**Feature tier**: MVP

#### Scenario: Assign to department (group)

- GIVEN a terugbelverzoek needs to go to "Afdeling Burgerzaken"
- WHEN the agent selects the group in the assignment field
- THEN the taak MUST appear in the shared inbox for all members of that group
- AND any group member MUST be able to claim the task (changing status to "in_behandeling")
- AND claiming MUST assign the task to the claiming user and remove it from the group inbox

#### Scenario: Reassign task to different colleague

- GIVEN a backoffice agent "Petra Bakker" has claimed a terugbelverzoek but realizes colleague "Mark de Groot" has better context
- WHEN Petra reassigns the task to "Mark de Groot"
- THEN the taak assignee MUST update to Mark de Groot
- AND Mark MUST receive a notification
- AND the reassignment MUST be recorded in the task history with reason

#### Scenario: Escalate overdue task

- GIVEN a terugbelverzoek with deadline "2024-03-18 17:00" that is unclaimed at 2024-03-18 12:00
- WHEN the deadline approaches (configurable threshold, e.g., 4 hours before)
- THEN the system MUST send an escalation notification to the group manager
- AND the task priority MUST be visually elevated in the inbox

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
- WHEN the system checks for expired tasks
- THEN the taak status MUST change to "verlopen"
- AND an escalation notification MUST be sent to the group manager and the originating agent
- AND the task MUST remain visible in the inbox with a "Verlopen" badge

#### Scenario: Reopen a completed task

- GIVEN a terugbelverzoek marked as "Afgerond" but the citizen calls back saying they were not contacted
- WHEN the KCC agent reopens the task
- THEN the status MUST change back to "open"
- AND the reopening MUST be recorded in the task history with reason

---

### Requirement: Priority and Deadline Management

The system MUST support priority levels and deadlines for tasks, with visual indicators and sorting in the inbox.

**Feature tier**: MVP

#### Scenario: High-priority task visual distinction

- GIVEN a terugbelverzoek with priority "Hoog" and deadline today
- WHEN a backoffice agent views their inbox
- THEN the task MUST be displayed at the top of the list
- AND the task MUST have a visual indicator (e.g., red priority badge)
- AND the deadline MUST be displayed with urgency indication ("Vandaag, 17:00")

#### Scenario: Sort inbox by deadline

- GIVEN 10 tasks with various deadlines and priorities
- WHEN the agent sorts by deadline ascending
- THEN tasks MUST be ordered by nearest deadline first
- AND overdue tasks MUST appear at the very top regardless of sort order

---

### Requirement: Citizen Status Notification

The system SHOULD support notifying citizens about the status of their callback request.

**Feature tier**: V1

#### Scenario: Notify citizen that callback is scheduled

- GIVEN a terugbelverzoek has been created for citizen "Jan de Vries" with a preferred callback time
- WHEN the system is configured to send citizen notifications
- THEN the citizen SHOULD receive a notification (via configured channel: email, MijnOverheid, or SMS) confirming that a callback is scheduled
- AND the notification MUST NOT contain internal details (agent name, department, priority)
- AND the notification MUST include a reference number and expected callback window

#### Scenario: Notify citizen that callback was attempted

- GIVEN a backoffice agent attempted to call back but the citizen did not answer
- WHEN the agent logs the attempt and selects "Niet bereikbaar"
- THEN the citizen SHOULD receive a notification that a callback was attempted
- AND the notification SHOULD include instructions for how to reach the municipality

---

### Requirement: Overlap with My-Work

Tasks created via terugbel-taakbeheer MUST integrate seamlessly with the existing `my-work` spec.

**Feature tier**: MVP

#### Scenario: Terugbelverzoek appears in my-work inbox

- GIVEN a terugbelverzoek is assigned to agent "Petra Bakker"
- WHEN Petra opens her `my-work` personal inbox
- THEN the terugbelverzoek MUST appear alongside her other tasks (leads, requests, etc.)
- AND the task MUST be identifiable as type "Terugbelverzoek" with a distinct icon
- AND clicking the task MUST open the terugbelverzoek detail view with full context (client, zaak, contactmoment)

#### Scenario: Filter my-work by task type

- GIVEN Petra has 5 terugbelverzoeken, 3 lead follow-ups, and 2 request tasks
- WHEN she filters her `my-work` inbox by type "Terugbelverzoek"
- THEN only the 5 terugbelverzoeken MUST be displayed

---

### Current Implementation Status

**Implemented:**
- Nothing from this spec is implemented. There is no task/terugbelverzoek entity, no callback workflow, and no backoffice routing system.

**Not yet implemented:**
- **Create Terugbelverzoek:** No `taak` schema in `pipelinq_register.json`. No callback creation form or API.
- **Create Follow-up Task:** No generic follow-up task entity separate from leads and requests.
- **Task Assignment and Routing:** No group/department assignment. No Nextcloud group inbox concept. No claim mechanism.
- **Task Status Tracking:** No task status lifecycle (open, in_behandeling, afgerond, verlopen). No auto-expiry for missed deadlines.
- **Priority and Deadline Management:** While lead/request priorities exist, there is no task-specific priority/deadline system with escalation.
- **Citizen Status Notification:** No outbound citizen notification system (email, MijnOverheid, SMS).
- **Overlap with My-Work:** The My Work view (`MyWork.vue`) exists but only shows leads and requests. It does not include tasks, terugbelverzoeken, or support filtering by task type.
- **Group-based routing:** No Nextcloud group integration for team inboxes.
- **Escalation notifications:** No automated escalation when deadlines approach.
- **Task history/audit trail:** While the audit trail plugin exists, there is no task-specific history for reassignments and status changes.

**Partial implementations:**
- The My Work view provides the "personal inbox" pattern that terugbelverzoeken should integrate with. The temporal grouping, priority sorting, and overdue detection patterns could be reused.
- The notification infrastructure (`NotificationService`) could be extended to support task assignment and escalation notifications.
- The request channel system (SystemTags) provides a pattern for task type configuration.

### Standards & References
- **VNG Klantinteracties:** `InterneTaak` entity from the VNG API specification for internal task management in municipalities.
- **Schema.org:** `Action` and `ScheduleAction` types for task modeling.
- **Common Ground:** Task management is a core component of KCC workflows in Dutch municipal IT architecture.
- **MijnOverheid:** Dutch government citizen portal for status notifications (V1 feature).

### Specificity Assessment
- The spec is well-structured with clear scenarios for callback creation, assignment routing, status tracking, and priority management.
- **Implementation complexity is high:** Requires new schema, group-based routing, deadline monitoring (background job), escalation logic, and citizen notification channels.
- **Open questions:**
  - How should group/department assignment work with Nextcloud groups? Are departments modeled as Nextcloud groups, OpenRegister objects, or a separate concept?
  - Should the `taak` entity be a new schema in the `pipelinq` register or should it reuse/extend the `request` schema with a `type` field?
  - How does the auto-expiry system work? Nextcloud background job (cron)? Or checked on-demand when inbox is viewed?
  - What notification channels are supported for citizen notifications? The spec mentions email, MijnOverheid, and SMS but does not specify integration details.
  - How should the "claim" mechanism work in a concurrent environment? Optimistic locking?
  - What is the relationship between `taak` and `request`? Can a request become a task, or are they separate entities with separate lifecycles?

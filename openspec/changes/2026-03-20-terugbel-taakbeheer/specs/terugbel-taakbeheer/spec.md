---
status: draft
---

# Terugbel- en Taakbeheer Specification

## Purpose

Terugbel- en taakbeheer (callback and task management) enables KCC agents to create callback requests (terugbelverzoeken) and follow-up tasks when a citizen question cannot be resolved immediately. Tasks are assigned to backoffice colleagues or departments (Nextcloud groups) with priority and deadline, tracked through completion, and integrated into the assignee's personal My Work inbox. **31% of klantinteractie-tenders** (16/52) explicitly require callback/task management.

**Standards**: VNG Klantinteracties (`InterneTaak`), Schema.org (`Action`, `ScheduleAction`)
**Feature tier**: MVP (core callbacks and routing), V1 (citizen notifications, templates, SLA reporting)
**Tender frequency**: 16/52 (31%)

## Data Model

Tasks are stored as OpenRegister objects in the `pipelinq` register using the `taak` schema:

| Property | Description |
|----------|-------------|
| type | terugbelverzoek / opvolgtaak / informatievraag |
| subject | Task subject line |
| description | Detailed description |
| status | open / in_behandeling / afgerond / verlopen |
| priority | hoog / normaal / laag |
| deadline | Task completion deadline (ISO 8601) |
| assigneeUserId | Nextcloud user UID |
| assigneeGroupId | Nextcloud group ID (department routing) |
| clientId | UUID reference to associated client |
| requestId | UUID reference to associated request |
| contactMomentSummary | Summary from originating contact moment |
| callbackPhoneNumber | Override callback phone number |
| preferredTimeSlot | Citizen's preferred callback window |
| createdBy | UID of creating agent |
| completedAt | Completion timestamp |
| resultText | Completion summary |
| attempts | Array of callback attempt log entries |

**Relation to my-work**: Tasks created here appear in the assignee's `my-work` personal inbox alongside leads and requests.
**Relation to VNG**: Maps to `InterneTaak` — `subject` → `gevraagdeHandeling`, `description` → `toelichting`, `status` → `status`, `assigneeUserId` → `toegewezenAanMedewerker`, `assigneeGroupId` → `toegewezenAanOrganisatorischeEenheid`.

## ADDED Requirements

---

### REQ-TTB-001: Create Terugbelverzoek

The system MUST allow KCC agents to create callback requests during or after a contact, capturing who should call back, when, and why.

**Feature tier**: MVP

#### Scenario: REQ-TTB-001-01 — Create callback from active contact

- GIVEN an agent handling a phone contact for citizen "Jan de Vries" about zaak "Bouwvergunning #2024-001"
- WHEN the agent clicks "Terugbelverzoek aanmaken" and fills in: onderwerp "Terugbellen over status vergunning", toelichting "Burger wil update over doorlooptijd", toewijzen aan "Afdeling Vergunningen", prioriteit "Normaal", terugbellen voor "2024-03-20 17:00"
- THEN the system MUST create a taak object with type "terugbelverzoek" in the OpenRegister `pipelinq` register
- AND the taak MUST be linked to the client and contactmoment via UUID references in `clientId` and `contactMomentSummary`
- AND the taak MUST appear in the "Afdeling Vergunningen" team inbox via `assigneeGroupId`
- AND the taak MUST store the creating agent's Nextcloud user UID as `createdBy`

#### Scenario: REQ-TTB-001-02 — Create callback assigned to specific colleague

- GIVEN an agent handling a follow-up where colleague "Petra Bakker" has prior context
- WHEN the agent creates a terugbelverzoek assigned to "Petra Bakker" with priority "Hoog" and deadline tomorrow 10:00
- THEN the taak MUST appear in Petra Bakker's personal `my-work` inbox via `assigneeUserId`
- AND Petra Bakker MUST receive a Nextcloud notification via `NotificationService` about the new callback
- AND the notification MUST include the citizen name, phone number, subject, and deadline

#### Scenario: REQ-TTB-001-03 — Create callback with preferred call time

- GIVEN a citizen requests to be called back "dinsdag tussen 14:00 en 16:00"
- WHEN the agent creates the terugbelverzoek with preferred time slot noted
- THEN the taak MUST store the preferred time slot in `preferredTimeSlot` as "Dinsdag 14:00 - 16:00"
- AND the backoffice agent MUST see this preference prominently in a highlighted banner when viewing the task

#### Scenario: REQ-TTB-001-04 — Create callback with citizen phone number override

- GIVEN a citizen calls from a different number than what is on file
- WHEN the agent creates a terugbelverzoek and enters the callback number "+31 6 98765432"
- THEN the taak MUST store the callback number in `callbackPhoneNumber` separately from the client's primary phone
- AND the backoffice agent MUST see the callback number prominently in the task detail view

#### Scenario: REQ-TTB-001-05 — Validate required fields

- GIVEN an agent attempts to create a terugbelverzoek
- WHEN the agent tries to save without filling in the subject or assignee
- THEN the system MUST display inline validation errors for missing required fields
- AND the form MUST NOT submit until subject and at least one of assigneeUserId or assigneeGroupId are provided
- AND the deadline field MUST default to the next business day at 17:00

---

### REQ-TTB-002: Create Follow-up Task

The system MUST allow agents to create generic follow-up tasks (not just callbacks) for backoffice handling.

**Feature tier**: MVP

#### Scenario: REQ-TTB-002-01 — Create information request task

- GIVEN an agent needs the backoffice to research a policy question before calling the citizen
- WHEN the agent creates a taak with type "Informatievraag", subject "Opzoeken of erfpachtregeling van toepassing is", and assigns to "Afdeling Vastgoed"
- THEN the system MUST create a taak with type "informatievraag"
- AND the taak MUST include all context: clientId, requestId (if applicable), contactMomentSummary
- AND the taak MUST appear in the assigned team's inbox via `assigneeGroupId`

#### Scenario: REQ-TTB-002-02 — Create follow-up task without client

- GIVEN an anonymous caller reported a pothole at "Keizersgracht ter hoogte van nr. 100"
- WHEN the agent creates a follow-up task assigned to "Afdeling Beheer Openbare Ruimte"
- THEN the system MUST allow creating a taak without a `clientId` (field is optional)
- AND the task type MUST be "opvolgtaak"

#### Scenario: REQ-TTB-002-03 — Create follow-up task from existing request

- GIVEN an agent is viewing request "Aanvraag parkeervergunning #2024-050" for client "Maria Jansen"
- WHEN the agent clicks "Opvolgtaak aanmaken" on the request detail page
- THEN the system MUST pre-fill the task form with the request title, clientId, and requestId
- AND the agent MUST only need to add the assignee, priority, and deadline
- AND the created task MUST have `requestId` set to the originating request UUID

---

### REQ-TTB-003: Task Assignment and Routing

The system MUST support assigning tasks to individual users or groups/departments, with re-assignment and claim capability.

**Feature tier**: MVP

#### Scenario: REQ-TTB-003-01 — Assign to department (group)

- GIVEN a terugbelverzoek needs to go to "Afdeling Burgerzaken"
- WHEN the agent selects the Nextcloud group "Afdeling Burgerzaken" in the assignment field
- THEN the taak MUST set `assigneeGroupId` to the Nextcloud group ID and appear in the shared inbox for all members
- AND any group member MUST be able to claim the task (changing `assigneeUserId` and status to "in_behandeling")
- AND claiming MUST clear `assigneeGroupId` so the task leaves the group inbox

#### Scenario: REQ-TTB-003-02 — Reassign task to different colleague

- GIVEN a backoffice agent "Petra Bakker" has claimed a terugbelverzoek but realizes colleague "Mark de Groot" has better context
- WHEN Petra reassigns the task to "Mark de Groot"
- THEN `assigneeUserId` MUST update to Mark de Groot's UID
- AND Mark MUST receive a Nextcloud notification
- AND the reassignment MUST be recorded in the audit trail with timestamp

#### Scenario: REQ-TTB-003-03 — Escalate overdue task

- GIVEN a terugbelverzoek with deadline "2024-03-18 17:00" that is unclaimed
- WHEN 4 hours before the deadline the background job runs
- THEN the system MUST send an escalation notification to the assignee via `NotificationService`
- AND the task MUST display a "Bijna verlopen" badge with elevated visual priority in the inbox
- AND the escalation check MUST run every 15 minutes via `TaskEscalationJob` (ITimedJob)

#### Scenario: REQ-TTB-003-04 — Assignment autocomplete search

- GIVEN an agent is creating a task and types "Burg" in the assignment field
- WHEN the autocomplete queries the Nextcloud OCS API
- THEN the system MUST display matching users (e.g., "Jan Burgerhout") and groups (e.g., "Afdeling Burgerzaken")
- AND users and groups MUST be visually distinguished with different icons

---

### REQ-TTB-004: Task Status Tracking

The system MUST support tracking tasks through their lifecycle: open → in_behandeling → afgerond / verlopen.

**Feature tier**: MVP

#### Scenario: REQ-TTB-004-01 — Complete a callback task

- GIVEN a backoffice agent has called back citizen "Jan de Vries" successfully
- WHEN the agent marks the terugbelverzoek as "Afgerond" with result text
- THEN `status` MUST change to "afgerond"
- AND `completedAt` MUST be set to the current timestamp
- AND `resultText` MUST store the completion summary

#### Scenario: REQ-TTB-004-02 — Task expires past deadline

- GIVEN a terugbelverzoek with deadline "2024-03-18 17:00" still with status "open" at 2024-03-19 00:00
- WHEN the `TaskEscalationJob` runs
- THEN `status` MUST change to "verlopen"
- AND an escalation notification MUST be sent to the assignee and `createdBy`
- AND the task MUST display a "Verlopen" badge in red in the inbox

#### Scenario: REQ-TTB-004-03 — Reopen a completed task

- GIVEN a terugbelverzoek marked as "Afgerond" but citizen calls back saying they were not contacted
- WHEN the KCC agent reopens the task
- THEN `status` MUST change back to "open"
- AND a new deadline MUST be set (defaulting to next business day 17:00 via `TaskService.getDefaultDeadline()`)

#### Scenario: REQ-TTB-004-04 — Log unsuccessful callback attempt

- GIVEN a backoffice agent attempts to call back but the citizen does not answer
- WHEN the agent logs the attempt with result "Niet bereikbaar"
- THEN the task MUST remain in "in_behandeling"
- AND an entry MUST be appended to the `attempts` array: `{ timestamp, result: "niet_bereikbaar", notes }`
- AND after 3 unsuccessful attempts, the system MUST suggest changing status to "Afgerond" with result "Burger niet bereikt na 3 pogingen"

#### Scenario: REQ-TTB-004-05 — View task status history

- GIVEN a task has been through multiple status changes
- WHEN an agent views the task detail
- THEN the system MUST display a chronological audit trail via `CnObjectSidebar` audit trail tab
- AND each transition MUST show: timestamp, actor, and previous/new value

---

### REQ-TTB-005: Priority and Deadline Management

The system MUST support priority levels and deadlines with visual indicators and business-hours calculation.

**Feature tier**: MVP

#### Scenario: REQ-TTB-005-01 — High-priority task visual distinction

- GIVEN a terugbelverzoek with priority "Hoog" and deadline today
- WHEN a backoffice agent views their inbox
- THEN the task MUST be sorted to the top of the list
- AND the task MUST display a red priority badge consistent with `getPriorityColor` in `MyWork.vue`

#### Scenario: REQ-TTB-005-02 — Deadline business hours calculation

- GIVEN an agent creates a task on Friday at 16:00 with a 24-hour deadline
- WHEN `TaskService.calculateDeadline()` computes the deadline
- THEN the deadline MUST be set to Monday 16:00 (skipping the weekend)
- AND business hours default MUST be Monday-Friday 08:00-17:00

#### Scenario: REQ-TTB-005-03 — Default deadline on task creation

- GIVEN an agent opens the task creation form
- WHEN the deadline field is displayed
- THEN the deadline MUST default to the next business day at 17:00 via `TaskService.getDefaultDeadline()`
- AND the agent MUST be able to override this value

---

### REQ-TTB-006: My Work Integration

Tasks MUST integrate seamlessly with the existing `my-work` inbox so agents see all their work in one place.

**Feature tier**: MVP

#### Scenario: REQ-TTB-006-01 — Terugbelverzoek appears in my-work inbox

- GIVEN a terugbelverzoek is assigned to agent "Petra Bakker" via `assigneeUserId`
- WHEN Petra opens her `my-work` personal inbox
- THEN the terugbelverzoek MUST appear alongside her other tasks (leads, requests)
- AND the task MUST display a type badge ("Terugbelverzoek") distinct from leads and requests
- AND clicking the task MUST navigate to the terugbelverzoek detail view

#### Scenario: REQ-TTB-006-02 — Filter my-work by task type

- GIVEN Petra has 5 terugbelverzoeken, 3 lead follow-ups, and 2 request tasks
- WHEN she clicks the "Taken" filter button in `my-work`
- THEN only the 5 terugbelverzoeken MUST be displayed
- AND the "Taken" filter button MUST be added to the existing filter-buttons pattern alongside "Leads" and "Verzoeken"

#### Scenario: REQ-TTB-006-03 — My-work counts include tasks

- GIVEN Petra has 5 leads, 3 requests, and 4 tasks
- WHEN the my-work header is displayed
- THEN the counts MUST read: "Leads (5) — Verzoeken (3) — Taken (4) — 12 items totaal"
- AND the overdue grouping MUST include tasks with passed deadlines

---

## Appendix

### Implementation Status

**Implemented (this change):**
- `taak` schema in `pipelinq_register.json` with all properties per ADR-000 `task` entity
- `TaskService.php` with deadline calculation and validation
- `TaskEscalationJob.php` for deadline monitoring and auto-expiry (ITimedJob, 15 min)
- `TaskList.vue` — filterable task list
- `TaskDetail.vue` — task detail with status actions and attempt logging
- `TaskForm.vue` — unified create/edit form with user/group assignment autocomplete
- Task routes in `src/router/index.js`
- "Taken" nav item in `MainMenu.vue`
- `MyWork.vue` extended to include tasks

**Out of scope for this change (V1):**
- Citizen status notifications (email/MijnOverheid/SMS)
- Task templates with predefined values
- SLA reporting and dashboards
- Procest-specific task types

### Standards References
- **VNG Klantinteracties:** `InterneTaak` entity from the VNG API specification
- **Schema.org:** `schema:Action` and `schema:ScheduleAction` for task modeling
- **Common Ground:** Task management is a core KCC workflow component in Dutch municipal IT

### Competitor Comparison
- **EspoCRM**: Activities module with Tasks, Calls, Meetings — no KCC-specific callback workflow or group-based routing
- **Twenty**: Activity timeline with tasks and notes — no dedicated callback management
- **Kraijn**: Activities linked to leads/persons — no task management separate from activities
- **Pipelinq advantage**: VNG `InterneTaak` compliance, Nextcloud group integration for department routing, My Work inbox integration

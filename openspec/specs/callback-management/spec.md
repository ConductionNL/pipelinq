## ADDED Requirements

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

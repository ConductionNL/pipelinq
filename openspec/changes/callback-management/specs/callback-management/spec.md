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

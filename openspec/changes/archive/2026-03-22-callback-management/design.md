## Context

Pipelinq currently manages clients, contacts, leads, and requests stored as OpenRegister objects. There is no task/callback entity. KCC agents need to create terugbelverzoeken (callback requests) and follow-up tasks routed to backoffice departments or individual colleagues. The existing My Work view shows leads and requests but not tasks.

The terugbel-taakbeheer spec (already in `openspec/specs/`) provides the detailed requirements. This change implements the MVP tier: task CRUD, assignment, status lifecycle, and My Work integration.

## Goals / Non-Goals

**Goals:**
- Add a `task` schema to the pipelinq register for terugbelverzoeken, opvolgtaken, and informatievragen
- Implement task creation, assignment (user or Nextcloud group), status lifecycle, and claim mechanism
- Extend My Work to display tasks alongside leads and requests
- Add a background job for deadline expiry and escalation notifications
- Integrate with existing NotificationService for task assignment notifications

**Non-Goals:**
- Citizen status notifications (V1 — requires external notification channels)
- Task templates (V1 — admin configuration feature)
- Task search/filtering for managers (V1 — organization-wide views)
- Business hours calculation for deadlines (V1 — requires holiday configuration)
- Bulk reassignment (V1 — manager feature)

## Decisions

### 1. Task schema as new OpenRegister object type

**Decision**: Add a `task` schema to `lib/Settings/pipelinq_register.json` alongside existing schemas (client, contact, lead, request, pipeline, product, productCategory, leadProduct).

**Rationale**: Tasks have a distinct lifecycle (open/in_behandeling/afgerond/verlopen) different from requests (new/in_progress/completed/rejected/converted). Reusing the request schema would conflate two different workflows.

**Alternative considered**: Extending the request schema with a "type" field to distinguish requests from tasks. Rejected because it complicates status validation and query filtering.

### 2. Assignment model: userId or groupId field

**Decision**: The task schema has two assignment fields: `assigneeUserId` (string, nullable) and `assigneeGroupId` (string, nullable). At least one MUST be set. When assigned to a group, any group member can claim it (setting `assigneeUserId` to themselves and clearing `assigneeGroupId`).

**Rationale**: Nextcloud groups are the natural mapping for departments. Using separate fields avoids ambiguity and simplifies queries (filter by `assigneeUserId` for personal inbox, by `assigneeGroupId` for team inbox).

**Alternative considered**: A single `assignee` field with a type prefix (e.g., `user:admin`, `group:burgerzaken`). Rejected because OpenRegister filter queries work better with separate fields.

### 3. Frontend: new task views + store

**Decision**: Create `src/views/tasks/` with TaskList.vue, TaskDetail.vue, TaskCreate.vue. Create `src/stores/task.js` (Pinia store) querying OpenRegister API. Add "Tasks" filter to MyWork.vue.

**Rationale**: Follows the existing pattern established by leads (`src/views/leads/`) and requests (`src/views/requests/`). Each entity type has its own view directory and store.

### 4. Backend: TaskExpiryJob as ITimedJob

**Decision**: Add `lib/BackgroundJob/TaskExpiryJob.php` implementing `OCP\BackgroundJob\TimedJob`. Runs every 15 minutes. Queries OpenRegister for tasks with status "open" or "in_behandeling" and deadline in the past. Changes status to "verlopen" and sends escalation notification.

**Rationale**: ITimedJob is the standard Nextcloud pattern for periodic background work. 15-minute interval balances responsiveness with server load.

**Alternative considered**: Event-driven approach using OpenRegister object update hooks. Rejected because deadline expiry happens passively (no user action triggers it).

### 5. Claim mechanism via optimistic concurrency

**Decision**: When a group member claims a task, the frontend sends a PATCH to OpenRegister with the expected version. If another user claimed it first, OpenRegister returns a 409 Conflict, and the frontend shows "This task has already been claimed by another user."

**Rationale**: OpenRegister supports version fields for optimistic concurrency. This avoids the need for a locking mechanism.

### 6. Callback attempt logging via task history array

**Decision**: The task schema includes an `attempts` array field. Each attempt is an object with `timestamp`, `result` (e.g., "niet_bereikbaar", "succesvol"), and `notes`. The attempt counter is derived from the array length.

**Rationale**: Storing attempts as an array within the task object keeps all callback context together. After 3 unsuccessful attempts, the frontend suggests closing the task.

### 7. User/group autocomplete via Nextcloud OCS API

**Decision**: The assignment field uses the Nextcloud OCS sharing autocomplete API (`/ocs/v2.php/apps/files_sharing/api/v1/sharees`) to search users and groups. Results are displayed with user/group icons for visual distinction.

**Rationale**: This is the standard Nextcloud approach for user/group search. Reuses existing infrastructure without custom backend code.

**Alternative considered**: Custom backend endpoint querying IGroupManager and IUserManager directly. Rejected because the OCS sharees endpoint already provides the needed functionality with proper pagination and access control.

## Risks / Trade-offs

- **[Risk] OpenRegister array field support** — The `attempts` array field may have limited query support in OpenRegister. → Mitigation: Attempts are only read when viewing a single task detail, not queried/filtered. Array storage is sufficient.
- **[Risk] Group inbox performance** — Querying tasks by `assigneeGroupId` requires the frontend to know which groups the current user belongs to. → Mitigation: Fetch user groups once via OCS API on My Work load, cache in the store.
- **[Risk] Background job registration** — The TaskExpiryJob must be registered in `appinfo/info.xml` under `<background-jobs>`. If missed, tasks will never auto-expire. → Mitigation: Include in the repair step verification.
- **[Trade-off] No real-time updates** — Tasks claimed by another user won't immediately disappear from the group inbox. → Acceptable for MVP; auto-refresh (V1) will address this.

## Migration Plan

1. Add `task` schema to `pipelinq_register.json`
2. Increment the app version in `appinfo/info.xml` to trigger repair step
3. The repair step (`InitializeSettings`) will import the updated register configuration via `ConfigurationService::importFromApp()`
4. No data migration needed — this is a new entity type
5. Rollback: remove the task schema from the register; existing task objects become orphaned but cause no errors

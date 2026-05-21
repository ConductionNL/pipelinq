# Proposal: terugbel-taakbeheer

## Problem

KCC agents cannot create callback requests or follow-up tasks when citizen questions cannot be resolved immediately. There is no task entity, no assignment to departments (Nextcloud groups), no deadline tracking, and no escalation system. **31% of tenders** (16/52) explicitly require this capability.

## Solution

Implement callback and task management with:
1. **Taak schema** in OpenRegister with types: terugbelverzoek, opvolgtaak, informatievraag
2. **Task creation forms** for callbacks and follow-ups with assignment to users/groups
3. **Status lifecycle** (open/in_behandeling/afgerond/verlopen) with deadline monitoring
4. **Background job** for deadline escalation and auto-expiry (every 15 minutes)
5. **My Work integration** — tasks appear in the existing personal inbox
6. **Task list view** with search, filtering, and status/priority badges

### Approach

- Add `taak` schema to `pipelinq_register.json` with all properties matching `task` entity in ADR-000
- Create `TaskService.php` with business-hours deadline calculation and field validation
- Create `TaskEscalationJob.php` as `ITimedJob` for deadline monitoring and auto-expiry
- Create `TaskList.vue`, `TaskDetail.vue`, `TaskForm.vue` using `CnIndexPage`/`CnDetailPage` patterns
- Extend `MyWork.vue` to include tasks alongside existing leads and requests
- Use `NotificationService` for assignment and escalation notifications

## Scope

- Taak schema with all properties per ADR-000 (`task` entity)
- Task creation form (callback and generic follow-up)
- Assignment to individual users and Nextcloud groups (department routing)
- Status tracking with lifecycle transitions (claim, complete, reopen, expire)
- Priority and deadline management with business-hours calculation
- Background job for deadline monitoring and auto-expiry (ITimedJob, 15 min)
- My Work inbox integration — tasks alongside leads and requests
- Task list and detail views with status/priority badges

## Out of scope

- Citizen status notifications (V1)
- Task templates (V1)
- SLA reporting on tasks (V1)
- Procest-specific task types (cross-app)

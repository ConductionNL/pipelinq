# Proposal: terugbel-taakbeheer

## Problem

KCC agents cannot create callback requests or follow-up tasks when citizen questions cannot be resolved immediately. There is no task entity, no assignment to departments (Nextcloud groups), no deadline tracking, and no escalation system. 31% of tenders explicitly require this capability.

## Solution

Implement callback and task management with:
1. **Taak schema** in OpenRegister with types: terugbelverzoek, opvolgtaak, informatievraag
2. **Task creation forms** for callbacks and follow-ups with assignment to users/groups
3. **Status lifecycle** (open/in_behandeling/afgerond/verlopen) with deadline monitoring
4. **Background job** for deadline escalation and auto-expiry
5. **My Work integration** — tasks appear in the existing personal inbox
6. **Task list view** with search, filtering, and bulk operations

## Scope

- Taak schema with all properties per spec
- Task creation form (callback and generic follow-up)
- Assignment to users and Nextcloud groups
- Status tracking with history
- Priority and deadline management
- Background job for deadline monitoring
- My Work inbox integration
- Task list/detail views

## Out of scope

- Citizen status notifications (V1)
- Task templates (V1)
- SLA reporting on tasks (V1)
- Procest-specific task types (cross-app)

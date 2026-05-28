# ADR-003: Tenancy and RBAC Model

**Status:** Accepted  
**Date:** 2026-05-27  
**Deciders:** Conduction Development Team

## Context

The initial Pipelinq codebase passed `_rbac: false` and `_multitenancy: false` to every
OpenRegister `ObjectService` call, completely bypassing OR's built-in row-level access
control. This caused an instance-wide IDOR: any authenticated user could read or mutate
CRM data belonging to any other user or organisation on the same NC instance.

## Decision

All `ObjectService` calls (findAll, findObject, saveObject, deleteObject, find) in this
application must rely on OpenRegister's built-in RBAC and multi-tenancy enforcement. The
`_rbac` and `_multitenancy` override parameters must never be set to `false` in production
code.

Per-object ownership scoping (where needed) is achieved through field filters on
`assigneeUserId`, `createdBy`, or group membership, and through OR's native
permission system — not by disabling the ACL.

**Mutation authorisation** for tasks and callbacks uses `ScheduledTaskService::authorizeTaskMutation`,
which allows the action only when the acting user is:
1. The assignee (`assigneeUserId`), or
2. A member of the assigned group (`assigneeGroupId`), or
3. A Nextcloud administrator.

**Reassignment** of tasks to arbitrary users is restricted to administrators only (via
`IGroupManager::isAdmin`) to prevent notification spam and privilege escalation.

## Consequences

- OpenRegister's row-level isolation is enforced for all CRM objects.
- Per-user task management is correctly scoped; cross-user access is blocked.
- Background jobs (ScheduledTaskJob, CallbackOverdueJob) run with system context; they must
  only operate on objects owned by the system or globally visible tasks.
- Any future code that needs elevated access must add an explicit exception with a documented
  justification comment — never a blanket flag suppression.

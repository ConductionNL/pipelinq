# Design: callback-management

**Status:** pr-created

## Context

Pipelinq already has a `task` schema and `TaskService` for basic task/callback management (deadline calculation, validation, business hours). The existing `TaskEscalationJob` and `TaskExpiryJob` background jobs provide scaffolding for deadline monitoring but lack full implementation. The `NotificationService` already supports task-related notifications (assignment, completion, reassignment, expiry).

This change adds the callback-specific business logic layer: a `CallbackService` for attempt tracking, claim validation, and status transitions; a `CallbackController` for API endpoints; and a `CallbackOverdueJob` for proactive overdue monitoring.

## Goals / Non-Goals

**Goals:**
- Provide API endpoints for callback lifecycle operations (attempt logging, claim, complete, reassign)
- Implement callback business logic (attempt threshold, status transitions, claim eligibility)
- Add background job for overdue callback detection and notification
- Ensure task schema in register config includes all callback-specific fields
- Add unit tests for all new PHP classes

**Non-Goals:**
- Direct telephony integration (CTI, click-to-call)
- Calendar/agenda integration for agent availability (future feature)
- Frontend Vue components (already exist in `src/views/tasks/`)
- Modifying existing TaskService, NotificationService, or background jobs

## Decisions

### 1. Separate CallbackService from TaskService

**Decision**: Create a new `CallbackService` rather than extending `TaskService`.

**Rationale**: TaskService handles generic task concerns (deadlines, validation, business hours). Callback-specific logic (attempts, claim validation, status transitions) is a distinct responsibility. Separation keeps both classes focused and testable.

**Alternative considered**: Extending TaskService with callback methods. Rejected because it would bloat TaskService and mix generic/specific concerns.

### 2. CallbackController for dedicated endpoints

**Decision**: Create `CallbackController` with routes under `/api/callbacks/{id}/`.

**Rationale**: Callback operations (attempt, claim, complete, reassign) are action-oriented, not CRUD. Dedicated controller keeps these separate from generic task CRUD (which goes through OpenRegister API directly from frontend).

### 3. IGroupManager for claim validation

**Decision**: Use Nextcloud's `IGroupManager` to validate group membership during claim operations.

**Rationale**: The user's group membership determines whether they can claim a group-assigned task. IGroupManager is the canonical Nextcloud API for this.

### 4. IAppConfig for overdue notification tracking

**Decision**: Track which tasks have been notified using IAppConfig keys with pattern `callback_notified_{taskId}`.

**Rationale**: Simple key-value tracking avoids needing a separate database table. Values store the last notification timestamp; the job checks if 24 hours have passed before re-notifying.

## Risks / Trade-offs

- **[Risk] OpenRegister API requires user context for queries** -> The CallbackOverdueJob runs as a background job without user context. Mitigation: Use IAppConfig to store register/schema IDs; the job logs its intent and the actual OpenRegister queries will work when system-level API access is available.
- **[Risk] Concurrent claim race condition** -> Two users claiming the same task simultaneously. Mitigation: OpenRegister's optimistic concurrency (version check) handles this; the controller catches version conflicts and returns a user-friendly error.
- **[Risk] Attempt array growth** -> The attempts array grows unbounded. Mitigation: Practical limit is ~10-20 attempts per callback; at that point the task should be closed. No hard limit needed for MVP.

## Seed Data

No new schemas are introduced. The existing `task` schema is extended with additional properties in the register JSON. Seed data for testing: create sample terugbelverzoek tasks with various statuses and attempt counts via the existing OpenRegister API.

## Migration Plan

1. Update `pipelinq_register.json` with callback-specific properties on the task schema
2. Add `CallbackService`, `CallbackController`, `CallbackOverdueJob` PHP classes
3. Register routes in `appinfo/routes.php`
4. Register background job in `appinfo/info.xml`
5. Run repair step to reimport register config (adds new properties to existing task schema)

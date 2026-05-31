# Capability — pipelinq-or-adoption (lifecycle + notification slice)

## ADDED Requirements

### Requirement: Lifecycle annotation backs status state changes

Inline `'status' => '<literal>'` writes in pipelinq SHALL be replaced with lifecycle
transition API calls. The on-wire status value SHALL remain identical.

#### Scenario: Kennisbank Dutch state literals via lifecycle

- **GIVEN** the kennisbank schema declares lifecycle states
  `nieuw`, `in_review`, `gepubliceerd`, `ingetrokken`
- **WHEN** `KennisbankService` (lines 82, 176), `KennisbankReviewJob` (line 93), or
  `PublicKennisbankController` (line 75) would have written
  `'status' => 'gepubliceerd'` or `'nieuw'`
- **THEN** the call SHALL go through `lifecycleService->transitionTo(...)`
- **AND** the on-wire payload SHALL still contain `"status": "gepubliceerd"` or
  `"status": "nieuw"` as before.

#### Scenario: Visibility is orthogonal to lifecycle

- **GIVEN** the kennisbank schema declares `visibility: { enum: [openbaar, intern] }`
  AS A SEPARATE FIELD from `status`
- **WHEN** an item transitions from `gepubliceerd` to `ingetrokken`
- **THEN** the visibility field SHALL be unaffected
- **AND** the visibility enum SHALL NOT appear in the lifecycle annotation.

#### Scenario: Calendar sync scheduled state

- **GIVEN** the calendar-sync schema declares lifecycle states
  `scheduled`, `running`, `succeeded`, `failed`
- **WHEN** `CalendarSyncService:76` would have written `'status' => 'scheduled'`
- **THEN** the service SHALL invoke `lifecycleService->transitionTo($sync, 'scheduled')`.

#### Scenario: Callback open state

- **GIVEN** the callback schema declares lifecycle states
  `open`, `claimed`, `completed`, `cancelled`
- **WHEN** `CallbackController:302` would have written `'status' => 'open'`
- **THEN** the controller SHALL invoke
  `lifecycleService->transitionTo($cb, 'open')`.

#### Scenario: Automation run skipped/failure states

- **GIVEN** the automation-run schema declares lifecycle states
  `pending`, `running`, `succeeded`, `failed`, `skipped`
- **WHEN** `AutomationService:220,249` would have written `'status' => 'skipped'` or
  `'failure'`
- **THEN** the service SHALL invoke
  `lifecycleService->transitionTo($run, 'skipped')` or `'failed'` (note: rename
  `'failure'` to `'failed'` for canonical naming, with on-wire compat alias).

### Requirement: Notification annotation backs notification calls

Direct `notificationManager->notify()` and `setSubject()` calls in pipelinq SHALL be
replaced with `x-openregister-notifications` triggers keyed on lifecycle transitions.

#### Scenario: NotificationService is annotation-driven

- **GIVEN** the relevant schemas declare `x-openregister-notifications`
- **WHEN** a lifecycle transition fires
- **THEN** the notification SHALL fire via the annotation runtime
- **AND** no direct `notificationManager->notify()` call SHALL exist in
  `lib/Service/NotificationService.php` lines 405-412.

#### Scenario: ActivityService uses annotation

- **GIVEN** the relevant schemas declare `x-openregister-notifications`
- **WHEN** an activity event fires
- **THEN** the notification SHALL fire via the annotation runtime
- **AND** no direct `setSubject()` call SHALL exist in
  `lib/Service/ActivityService.php:291`.

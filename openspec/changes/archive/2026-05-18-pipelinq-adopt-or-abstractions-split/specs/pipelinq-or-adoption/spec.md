# Capability — pipelinq-or-adoption

## ADDED Requirements

### Requirement: Register lookups go through RegisterResolverService

All eight call sites of `$appConfig->getValueString(APP_ID, 'register', '')` SHALL be
migrated to `RegisterResolverService::resolve(...)` per the OR-side
`register-resolver-service` spec.

#### Scenario: Queue service uses resolver

- **GIVEN** the OR `register-resolver-service` spec is satisfied
- **WHEN** `QueueService` resolves its register at lines 57, 145, 236, or 292
- **THEN** the resolution SHALL go through `RegisterResolverService::resolve('queue')`
- **AND** no `getValueString(APP_ID, 'register', '')` call SHALL exist in
  `lib/Service/QueueService.php`.

#### Scenario: Default queue service uses resolver

- **GIVEN** the resolver service is available
- **WHEN** `DefaultQueueService` reads its register at lines 122 or 179
- **THEN** the resolution SHALL go through `RegisterResolverService`.

#### Scenario: Contact vCard services use resolver

- **GIVEN** the resolver service is available
- **WHEN** `ContactVcardService` (line 102) or `ContactVcardWriterService` (line 139)
  reads its register
- **THEN** the resolution SHALL go through `RegisterResolverService::resolve('contact')`.

#### Scenario: No remaining direct register reads

- **GIVEN** the migration is applied
- **WHEN** a developer greps `lib/` for `getValueString(APP_ID, 'register', '')`
- **THEN** zero matches SHALL be found.

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

### Requirement: Lead-management computed fields use calculation annotation

Lead-management computed fields SHALL be declared as `x-openregister-calculations`
annotations on the lead schema. This covers the `lead-management` spec's qualification
score (line 1024 open question), staleness (line 505), aging (line 519), and lead-value
(line 924).

#### Scenario: Qualification score is a backend calculation

- **GIVEN** the lead schema declares
  `x-openregister-calculations.qualification_score`
- **WHEN** a frontend store reads a lead
- **THEN** the score SHALL be present in the response
- **AND** the score SHALL be computed by the calculation annotation, not by ad-hoc
  service code
- **AND** the spec at `openspec/specs/lead-management/spec.md:1024` SHALL be updated to
  remove the open question and cite this Requirement.

#### Scenario: Staleness, aging, lead-value are calculations

- **GIVEN** the lead schema declares calculation annotations for staleness, aging, and
  lead-value
- **WHEN** any of these fields is read
- **THEN** the value SHALL be derived from the calculation expression, not written by
  service code.

### Requirement: Contacts-sync consumes contacts-actions integration provider

`openspec/specs/contacts-sync/spec.md` SHALL be rewritten to consume OR's
`contacts-actions` integration provider (`ContactMatchingService`). The custom NC Contacts
sync SHALL be removed. Fallback behavior (provider not registered) SHALL be documented.

#### Scenario: Sync uses ContactMatchingService

- **GIVEN** OR registers `ContactMatchingService` via `pluggable-integration-registry`
- **WHEN** a pipelinq sync runs
- **THEN** matching SHALL be delegated to `ContactMatchingService`
- **AND** no bespoke matching/scoring logic SHALL exist in pipelinq's contact-sync code.

#### Scenario: Graceful degradation when provider absent

- **GIVEN** the `contacts-actions` provider is NOT registered
- **WHEN** a pipelinq sync runs
- **THEN** the sync SHALL log a warning and SHALL skip the matching step (not crash)
- **AND** the spec SHALL document this fallback behavior explicitly.

### Requirement: Tenant-tunable values move to admin-config

Hardcoded constants flagged in `.claude/audit-2026-05-03/04-hardcoded.md` SHALL move to
admin-config. Default values SHALL preserve current behavior.

#### Scenario: Background-job intervals are admin-config

- **GIVEN** an admin sets `pipelinq.task_expiry.poll_interval_seconds = 1800`
- **WHEN** `TaskExpiryJob` runs
- **THEN** the poll interval SHALL be 1800
- **AND** no `INTERVAL = 900` constant SHALL exist in `lib/BackgroundJob/TaskExpiryJob.php`.

#### Scenario: Business hours are tenant-tunable

- **GIVEN** an admin sets
  `pipelinq.task.business_hour_start = 9` and `pipelinq.task.business_hour_end = 18`
- **WHEN** `TaskService` evaluates whether a moment is within business hours
- **THEN** business hours SHALL be 09:00-18:00 in the tenant's configured timezone
- **AND** no `BUSINESS_HOUR_START = 8` or `BUSINESS_HOUR_END = 17` constant SHALL exist
  in `lib/Service/TaskService.php`.

#### Scenario: Third-party API base URLs are admin-config

- **GIVEN** an admin sets `pipelinq.kvk.api_base_url` to a regional endpoint
- **WHEN** `KvkApiClient` makes a request
- **THEN** the request SHALL go to the configured URL
- **AND** the constant `API_BASE` SHALL no longer exist in
  `lib/Service/KvkApiClient.php`.

#### Scenario: Defaults preserve current behavior

- **GIVEN** a fresh pipelinq install with no admin-config overrides
- **WHEN** any service reads a value migrated under Phase 7
- **THEN** the value SHALL equal the constant value listed in
  `.claude/audit-2026-05-03/04-hardcoded.md`.

### Requirement: pipelinq is the createObjectStore exemplar

The pipelinq frontend SHALL retain `src/store/modules/object.js` as the reference
implementation of the `createObjectStore` pattern with plugins
`[filesPlugin(), auditTrailsPlugin(), relationsPlugin(), registerMappingPlugin()]`. The
file SHALL NOT be migrated or rewritten. Future audits SHALL cite this Requirement and
SHALL NOT flag the file as needing rewrite.

#### Scenario: createObjectStore usage is preserved

- **GIVEN** this Requirement exists in the capability spec
- **WHEN** a future OR-abstraction audit reviews pipelinq
- **THEN** the auditor SHALL cite this Requirement and SHALL NOT flag
  `src/store/modules/object.js` as duplication.

#### Scenario: Other apps reference pipelinq for the pattern

- **GIVEN** the pipelinq manifest declares the exemplar role
- **WHEN** another app's openspec proposal seeks a `createObjectStore` reference
- **THEN** it SHALL cite pipelinq's `src/store/modules/object.js`.

### Requirement: pipelinq declares its manifest

pipelinq SHALL ship `openspec/manifest.yaml` declaring `tier: 3` (frontend exemplar),
`dependencies: ["openregister"]`, the consumed shared specs, the minimum OR version, and
its exemplar role.

#### Scenario: Manifest declares exemplar role

- **GIVEN** `openspec/manifest.yaml` declares
  `pipelinq.role: object-store-exemplar` (or equivalent key from `adopt-app-manifest`)
- **WHEN** Hydra coordination loads the manifest
- **THEN** it SHALL recognize pipelinq as the reference implementation.

#### Scenario: Manifest pins minimum OR version including contacts-actions

- **GIVEN** Phase 6 depends on the `contacts-actions` provider in OR
- **WHEN** the manifest declares minimum OR version
- **THEN** the version pin SHALL include the OR release that ships
  `contacts-actions`.

### Requirement: pipelinq consumes shared multi-tenancy + i18n specs

pipelinq SHALL consume `multi-tenancy-context`, `i18n-source-of-truth`, and
`i18n-api-language-negotiation`.

#### Scenario: createObjectStore receives tenant context explicitly

- **GIVEN** the nc-vue `multi-tenancy-context` composable is available
- **WHEN** `src/store/modules/object.js` invokes `createObjectStore('object', {...})`
- **THEN** the factory call SHALL pass the tenant context from `useTenantContext()`
  explicitly (formalising the implicit dependency).

#### Scenario: API respects Accept-Language

- **GIVEN** a client sends `Accept-Language: nl-NL` to pipelinq
- **WHEN** the response includes a translatable label or description
- **THEN** the field SHALL return the Dutch translation per OR's negotiation spec.

## See Also

- `openspec/specs/openregister-integration/spec.md` — CURRENT, exemplar; not rewritten.
- `openspec/specs/lead-management/spec.md` — minor edits per Phase 5; existing enums kept.
- `openspec/specs/contacts-sync/spec.md` — REWRITE per Phase 6.
- `openspec/changes/archive/.../adr-000` — already reframed by Phase 1 PR #315; cite, do
  not repeat.

---
status: proposed
change: pipelinq-adopt-or-abstractions
---

# pipelinq OR Adoption — Capability Specification

## Purpose

This specification declares the requirements for migrating pipelinq's backend from
ad-hoc patterns to OpenRegister abstractions, as identified by the 2026-05-03
OR-abstraction audit. It covers register-resolver consumption, lifecycle/notification/
calculation/archival annotations, magic-number migration to admin-config, the contacts-sync
spec rewrite, app manifest creation, and formal documentation of pipelinq's exemplar status.

**Audit citations:**
- `.claude/audit-2026-05-03/01-code-cleanup.md` — stream 1
- `.claude/audit-2026-05-03/02-spec-rewrite.md` — stream 2
- `.claude/audit-2026-05-03/04-hardcoded.md` — stream 4

**Standards:** ADR-022 (lifecycle), ADR-024 (archival), ADR-025 (notifications),
`openspec/architecture/adr-000-data-model.md`, `openspec/architecture/adr-001-international-first-dutch-mapping.md`

---

## Requirements

### REQ-POR-001: Register-resolver consumption [Phase 1]

All call sites that read register configuration via
`$appConfig->getValueString(APP_ID, 'register', '')` MUST be replaced with
`RegisterResolverService::resolve()`. After migration, no such literal string call MUST
remain in `lib/`.

**Affected files (8 call sites):**
- `lib/Service/QueueService.php` lines 57, 145, 236, 292
- `lib/Service/DefaultQueueService.php` lines 122, 179
- `lib/Service/ContactVcardService.php` line 102
- `lib/Service/ContactVcardWriterService.php` line 139

#### Scenario 1: Queue service resolves register via service abstraction
- GIVEN the pipelinq application initialises a queue operation
- WHEN `QueueService` needs to locate the `queue` register
- THEN it MUST call `RegisterResolverService::resolve('queue')` instead of
  `$appConfig->getValueString(APP_ID, 'register', '')`
- AND the resolved register reference MUST be identical in value to the previously
  hardcoded config value

#### Scenario 2: Contact vCard services resolve the contact register
- GIVEN `ContactVcardService` or `ContactVcardWriterService` prepares a vCard sync
- WHEN either service needs the register reference for a contact object
- THEN each MUST call `RegisterResolverService::resolve('contact')`
- AND the resolved value MUST match what was previously read from app config

#### Scenario 3: No remaining direct config reads after migration
- GIVEN the apply phase has completed Phase 1
- WHEN a grep for `getValueString(APP_ID, 'register', '')` is run against `lib/`
- THEN the grep MUST return zero matches

---

### REQ-POR-002: Kennisbank lifecycle annotation [Phase 2]

The `kennisartikel` schema (see ADR-000) MUST declare its status lifecycle via
`x-openregister-lifecycle` rather than relying on inline literal string writes in service
and job classes. The lifecycle MUST define exactly four states with named transitions.

The `visibility` field MUST remain a separate JSON-schema `enum` property with values
`openbaar` and `intern`. Visibility is NOT a lifecycle state (see design Decision 2).

#### Scenario 1: New article starts in `nieuw` state
- GIVEN an author creates a new kennisartikel object
- WHEN the object is persisted without an explicit status
- THEN the lifecycle annotation MUST initialise `status` to `nieuw`
- AND the object MUST NOT be returned by public article queries (status ≠ gepubliceerd)

#### Scenario 2: Valid lifecycle transition nieuw → in_review
- GIVEN a kennisartikel with `status = nieuw`
- WHEN an editor triggers the `submit_for_review` transition
- THEN the status MUST change to `in_review`
- AND OR's lifecycle runtime MUST record the transition in the audit trail

#### Scenario 3: Valid transition in_review → gepubliceerd fires notification
- GIVEN a kennisartikel with `status = in_review`
- WHEN an editor triggers the `publish` transition
- THEN the status MUST change to `gepubliceerd`
- AND the lifecycle `on_enter_gepubliceerd` hook MUST fire a `notify-subscribers` event

#### Scenario 4: Invalid direct transition nieuw → gepubliceerd is rejected
- GIVEN a kennisartikel with `status = nieuw`
- WHEN a caller attempts to set `status = gepubliceerd` directly (bypassing in_review)
- THEN OR's lifecycle runtime MUST reject the write with HTTP 422
- AND the status MUST remain `nieuw`

#### Scenario 5: Visibility is independent of status
- GIVEN a kennisartikel with `status = ingetrokken` and `visibility = openbaar`
- WHEN an agent views the article detail page
- THEN the article MUST remain visible (read-only public archive)
- AND no lifecycle validation error MUST occur due to the visibility value

---

### REQ-POR-003: Calendar-sync lifecycle annotation [Phase 2]

The `calendarLink` schema (see ADR-000) MUST declare a four-state lifecycle
(`scheduled → running → succeeded | failed`) via `x-openregister-lifecycle`. The
`CalendarSyncService:76` literal `'status' => 'scheduled'` MUST be replaced with a
lifecycle transition call.

#### Scenario 1: New calendar sync object starts in `scheduled` state
- GIVEN `CalendarSyncService` creates a new calendarLink object
- WHEN the object is first persisted
- THEN `status` MUST be `scheduled` (set by lifecycle annotation default, not by inline literal)

#### Scenario 2: Running sync transitions to succeeded on completion
- GIVEN a calendarLink with `status = running`
- WHEN the sync process completes without error
- THEN the status MUST transition to `succeeded`
- AND the `endDate` field MUST be populated with the completion timestamp

#### Scenario 3: Running sync transitions to failed on error
- GIVEN a calendarLink with `status = running`
- WHEN the sync process throws an unhandled exception
- THEN the status MUST transition to `failed`
- AND the error MUST be logged at error level

---

### REQ-POR-004: Callback lifecycle annotation [Phase 2]

The callback (request) schema (see ADR-000 `request` entity) MUST declare a four-state
lifecycle (`open → claimed → completed | cancelled`) via `x-openregister-lifecycle`. The
`CallbackController:302` literal `'status' => 'open'` MUST be replaced with a lifecycle
transition call.

#### Scenario 1: New callback starts in `open` state
- GIVEN `CallbackController` creates a new callback object
- WHEN the object is persisted
- THEN `status` MUST be `open` via lifecycle annotation initial state, not inline literal

#### Scenario 2: Agent claims an open callback
- GIVEN a callback with `status = open`
- WHEN an agent triggers the `claim` transition
- THEN `status` MUST change to `claimed`
- AND the `assignee` field MUST be set to the claiming agent's user UID

#### Scenario 3: Claimed callback is completed
- GIVEN a callback with `status = claimed`
- WHEN the agent triggers the `complete` transition
- THEN `status` MUST change to `completed`
- AND the `resolvedAt` field MUST be set to the current timestamp

---

### REQ-POR-005: Automation-run lifecycle annotation [Phase 2]

The `automationLog` schema (see ADR-000) MUST declare a five-state lifecycle
(`pending → running → succeeded | failed | skipped`) via `x-openregister-lifecycle`.
The `AutomationService:220,249` literal status writes MUST be replaced with lifecycle
transition calls.

#### Scenario 1: New automation log starts in `pending`
- GIVEN `AutomationService` creates a new automationLog object at trigger time
- WHEN the log object is first persisted
- THEN `status` MUST be `pending` via lifecycle annotation, not inline literal

#### Scenario 2: Automation that matches no conditions transitions to `skipped`
- GIVEN an automationLog with `status = running`
- WHEN trigger conditions are evaluated and none match
- THEN `status` MUST transition to `skipped` via lifecycle transition call
- AND the `error` field MUST record the non-match reason

#### Scenario 3: Failed automation records error and transitions to `failed`
- GIVEN an automationLog with `status = running`
- WHEN an action in the execution chain throws an exception
- THEN `status` MUST transition to `failed` via lifecycle transition call
- AND the `error` field MUST contain the exception message
- AND the `actionsExecuted` array MUST record which actions ran before failure

---

### REQ-POR-006: Notification annotation migration [Phase 3]

Direct calls to `notificationManager->notify()` in `NotificationService:405-412` and
`setSubject()` in `ActivityService:291` MUST be replaced with `x-openregister-notifications`
annotations on the relevant schemas (task, lead, request). No direct
`notificationManager` calls MUST remain for events that can be expressed as schema
lifecycle triggers.

#### Scenario 1: Task assignment notification fires via schema annotation
- GIVEN a task object is created or reassigned to a user
- WHEN OR processes the write and evaluates notification triggers
- THEN the `x-openregister-notifications` annotation on the `task` schema MUST dispatch
  a notification to `assigneeUserId`
- AND the notification MUST NOT be sent by a direct `notificationManager->notify()` call
  in `NotificationService`

#### Scenario 2: Lead stage-change activity fires via annotation
- GIVEN a lead object's `stage` field is updated
- WHEN OR processes the write
- THEN the notification annotation on the `lead` schema MUST create an activity feed entry
  for the assigned sales representative
- AND `ActivityService::setSubject()` MUST NOT be called directly for this event

#### Scenario 3: Callback open notification fires via annotation
- GIVEN a new callback (request) object is created with `status = open`
- WHEN OR processes the lifecycle initial state entry
- THEN the notification annotation MUST notify the agents assigned to the relevant queue
- AND the direct `notificationManager->notify()` call MUST be removed from
  `NotificationService:405-412`

---

### REQ-POR-007: Calculation annotation — lead [Phase 5]

The `lead` schema (see ADR-000) MUST declare four computed properties as
`x-openregister-calculations` annotations. The open question at
`openspec/specs/lead-management/spec.md:1024` regarding "frontend vs backend qualification
score" MUST be resolved as a backend calculation, with the frontend reading it as readonly.

#### Scenario 1: Qualification score is computed backend-side and read-only on frontend
- GIVEN a lead object exists in the pipelinq register
- WHEN the frontend reads the lead object
- THEN `qualificationScore` MUST be present as a computed field (0–100)
- AND the frontend form MUST render `qualificationScore` as read-only
- AND no frontend scoring logic MUST exist — the calculation runs backend-only

#### Scenario 2: Staleness calculation reflects days since last stage change
- GIVEN a lead whose `stage` has not changed for more than N days
- WHEN OR evaluates the `staleness` calculation annotation
- THEN `staleness` MUST be set to the number of days since the last stage transition
- AND the frontend pipeline view MAY use `staleness` to apply a visual indicator

#### Scenario 3: Lead-value calculation uses the leadProducts total
- GIVEN a lead with one or more linked `leadProduct` objects
- WHEN OR evaluates the `leadValue` calculation
- THEN `leadValue` MUST equal the sum of all `leadProduct.total` values
- AND updating any `leadProduct` quantity or unitPrice MUST trigger recalculation

#### Scenario 4: Aging calculation is distinct from staleness
- GIVEN a lead created more than 30 days ago
- WHEN OR evaluates the `aging` calculation annotation
- THEN `aging` MUST equal the number of days since `createdAt`
- AND `aging` MUST be independent of `staleness` (aging tracks total lead age, not stage age)

---

### REQ-POR-008: Contacts-sync via contacts-actions provider [Phase 6]

The contacts-sync feature MUST consume OR's `contacts-actions` integration provider
(`ContactMatchingService` from OR's `pluggable-integration-registry`) rather than bespoke
NC Contacts sync logic in `ContactVcardService` / `ContactVcardWriterService`. The
bespoke matching/scoring logic MUST be removed or reduced to a thin adapter over the
provider.

When the `contacts-actions` provider is not registered (e.g., on an OR version that
predates the provider), the sync feature MUST degrade gracefully — no hard dependency
deadlock, no uncaught exceptions propagated to the user.

#### Scenario 1: Write-back sync delegates to contacts-actions provider
- GIVEN a client (person type) is saved in pipelinq
- WHEN the contacts-sync write-back is triggered
- THEN the system MUST invoke `ContactMatchingService` from OR's integration registry
- AND the provider MUST handle the vCard creation/update in Nextcloud Contacts
- AND `ContactVcardService` MUST NOT duplicate the vCard write logic

#### Scenario 2: Graceful degradation when provider is absent
- GIVEN the `contacts-actions` integration provider is not registered in OR's registry
- WHEN the contacts-sync write-back is triggered
- THEN the system MUST log a debug message indicating the provider is unavailable
- AND the Pipelinq save operation MUST complete successfully without error
- AND no exception MUST propagate to the user

#### Scenario 3: Import via contacts-actions provider surfaces matched contacts
- GIVEN the user opens the contact import dialog
- WHEN `ContactMatchingService` is queried for contacts matching the search term
- THEN results MUST include name, email, phone, and organization from the provider
- AND already-linked contacts (matching `contactsUid`) MUST be flagged as such

---

### REQ-POR-009: Magic-number → admin-config [Phase 7]

All twelve hardcoded constants identified in `.claude/audit-2026-05-03/04-hardcoded.md`
MUST be migrated to `IAppConfig`-backed admin settings. Each setting MUST have a default
value equal to the current constant to preserve existing behaviour. Admin-config values
MUST be validated on write to prevent misconfiguration.

**Constants and their admin-config keys:**

| Constant | Admin-config key | Default |
|----------|-----------------|---------|
| `KennisbankReviewJob::DEFAULT_REVIEW_INTERVAL` | `pipelinq.kennisbank.review_interval_days` | 180 |
| `QueueOverflowJob::INTERVAL` | `pipelinq.queue_overflow.poll_interval_seconds` | 300 |
| `TaskExpiryJob::INTERVAL` | `pipelinq.task_expiry.poll_interval_seconds` | 900 |
| `TaskExpiryJob::ESCALATION_THRESHOLD` | `pipelinq.task_expiry.escalation_threshold_seconds` | 14400 |
| `TaskExpiryJob::IN_PROGRESS_GRACE` | `pipelinq.task_expiry.in_progress_grace_seconds` | 86400 |
| `TaskEscalationJob::ESCALATION_THRESHOLD_HOURS` | `pipelinq.task_escalation.threshold_hours` | 4 |
| `TaskService::BUSINESS_HOUR_START` | `pipelinq.task.business_hour_start` | 8 |
| `TaskService::BUSINESS_HOUR_END` | `pipelinq.task.business_hour_end` | 17 |
| `ProspectDiscoveryService::CACHE_TTL` | `pipelinq.prospect_discovery.cache_ttl_seconds` | 3600 |
| `KvkApiClient::API_BASE` | `pipelinq.kvk.api_base_url` | `https://api.kvk.nl/api/v1` |
| `OpenCorporatesApiClient::API_BASE` | `pipelinq.opencorporates.api_base_url` | `https://api.opencorporates.com/v0.4` |
| *(Dutch state literals removed by lifecycle migration — not admin-config)* | — | — |

#### Scenario 1: Default install preserves current behaviour
- GIVEN pipelinq is freshly installed on a Nextcloud instance
- WHEN no admin settings have been customised
- THEN all background jobs MUST run with intervals equal to the former constants
- AND business hours MUST default to 08:00–17:00 local time
- AND third-party API base URLs MUST be the production NL endpoints

#### Scenario 2: Tenant tunes kennisbank review interval
- GIVEN a tenant administrator sets
  `pipelinq.kennisbank.review_interval_days` to `90` via the admin settings UI
- WHEN `KennisbankReviewJob` next executes
- THEN it MUST use `90` days as the review threshold
- AND articles overdue for review MUST be flagged at the 90-day boundary

#### Scenario 3: Out-of-range interval value is rejected
- GIVEN an admin attempts to set `pipelinq.task_expiry.poll_interval_seconds` to `30`
- WHEN the settings form validates the value
- THEN the system MUST reject the value with an error stating the minimum is 60 seconds
- AND the stored value MUST remain unchanged

#### Scenario 4: EU tenant configures regional KVK endpoint
- GIVEN a tenant administrator sets `pipelinq.kvk.api_base_url` to a regional staging URL
- WHEN `KvkApiClient` makes an API call
- THEN it MUST use the configured URL as the base, not the hardcoded NL production URL

---

### REQ-POR-010: App manifest [Phase 8]

pipelinq MUST have an `openspec/manifest.yaml` file declaring its tier, dependencies,
consumed OR services, and role as `object-store-exemplar`. The manifest MUST conform to
the Hydra `adopt-app-manifest` schema once that change ships.

#### Scenario 1: Manifest declares correct tier
- GIVEN the `openspec/manifest.yaml` is parsed by Hydra tooling
- WHEN the `tier` field is read
- THEN it MUST equal `3` (frontend exemplar; backend Tier 2 findings addressed by this change)

#### Scenario 2: Manifest declares openregister dependency
- GIVEN the manifest is read
- WHEN the `dependencies` array is evaluated
- THEN it MUST include `openregister`
- AND the minimum OR version MUST encompass `register-resolver-service` and
  `contacts-actions` integration provider

#### Scenario 3: Manifest declares exemplar role
- GIVEN the manifest is read
- WHEN `pipelinq.role` (or equivalent key per `adopt-app-manifest` spec) is evaluated
- THEN it MUST be `object-store-exemplar`
- AND this field MUST be human-readable by other apps scanning for reference implementations

---

### REQ-POR-011: Multi-tenancy and i18n adoption [Phase 9]

pipelinq's Pinia object store (`src/store/modules/object.js`) already receives tenant
context implicitly via `createObjectStore`. This dependency MUST be declared explicitly.
Translatable fields on kennisartikel, lead, task, and callback schemas MUST adopt
`i18n-source-of-truth`. The pipelinq REST API MUST respect the `Accept-Language` header
on read responses.

#### Scenario 1: Store factory declares multi-tenancy dependency explicitly
- GIVEN the pipelinq Vue application initialises
- WHEN `createObjectStore` is called in `src/store/modules/object.js`
- THEN the store factory call MUST declare its dependency on `multi-tenancy-context`
  explicitly (e.g., via the options parameter), not just receive it implicitly

#### Scenario 2: Kennisartikel title is returned in the requested language
- GIVEN a kennisartikel has translations for both `nl` and `en` via `i18n-source-of-truth`
- WHEN a client sends `GET /api/kennisartikel/{id}` with `Accept-Language: en`
- THEN the response body MUST contain the English `title` and `summary`
- AND the response MUST include `Content-Language: en`

#### Scenario 3: Fallback to Dutch when requested language is unavailable
- GIVEN a kennisartikel has only a Dutch translation
- WHEN a client sends `Accept-Language: fr` (French)
- THEN the response MUST fall back to `nl` content
- AND the response MUST include `Content-Language: nl` to signal the fallback

---

### REQ-POR-012: createObjectStore exemplar documentation [Phase 10]

`src/store/modules/object.js` MUST be explicitly designated as the reference implementation
of the `createObjectStore` pattern. This designation MUST survive future audits without
re-investigation.

#### Scenario 1: Capability spec declares exemplar status
- GIVEN this specification is present in the pipelinq openspec
- WHEN a future OR-abstraction audit reviews pipelinq's frontend
- THEN the auditor MUST find this Requirement (REQ-POR-012) citing
  `src/store/modules/object.js` as the reference implementation
- AND the auditor SHALL NOT flag the file as needing migration or rewrite

#### Scenario 2: No modification of the exemplar file by this change
- GIVEN the apply phase executes all ten phases
- WHEN the apply phase completes
- THEN `src/store/modules/object.js` MUST be byte-for-byte identical to its state
  before the apply phase began
- AND no commit in the apply phase MUST touch that file

#### Scenario 3: Other apps reference the exemplar, not duplicate it
- GIVEN a developer building a new Conduction app needs a Pinia object store
- WHEN they consult the OR developer guide
- THEN the guide MUST link to `src/store/modules/object.js` in pipelinq as the
  canonical `createObjectStore` example
- AND the developer MUST NOT need to write bespoke Pinia store logic for CRUD operations

---

## Standards References

| Standard | Relevance |
|----------|-----------|
| `adr-000-data-model.md` | Entity definitions for kennisartikel, lead, task, automationLog, calendarLink, request, contact, client |
| `adr-001-international-first-dutch-mapping.md` | Dutch lifecycle state names (gepubliceerd, nieuw, ingetrokken) are kept as on-wire values; not translated to English |
| ADR-022 (Hydra) | x-openregister-lifecycle annotation runtime |
| ADR-024 (Hydra) | x-openregister-archival retention annotation |
| ADR-025 (Hydra) | x-openregister-notifications annotation |
| ADR-019 (company) | Integration registry — contacts-actions provider pattern |
| vCard RFC 6350 | Contact field conventions for contacts-sync |
| Schema.org Demand | lead entity mapping |
| Schema.org Action | task entity mapping |
| Schema.org Article | kennisartikel entity mapping |

## See Also

- `openspec/changes/pipelinq-adopt-or-abstractions/proposal.md`
- `openspec/changes/pipelinq-adopt-or-abstractions/design.md`
- `openspec/changes/pipelinq-adopt-or-abstractions/tasks.md`
- `openspec/specs/contacts-sync/spec.md` — to be rewritten (Phase 6.1)
- `openspec/specs/lead-management/spec.md` — to be annotated (Phase 6.2)
- `openspec/specs/openregister-integration/spec.md` — CURRENT exemplar (link only, do not edit)

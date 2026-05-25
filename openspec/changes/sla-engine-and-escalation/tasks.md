# Tasks: sla-engine-and-escalation

## 0. Deduplication Check

- [ ] 0.1 Verify OpenRegister `ObjectService`, `WebhookService`, and event dispatcher are available.
  - Check: `grep -r "class ObjectService\|interface WebhookService\|EventDispatcher" vendor/nextcloud/openregister lib/`
  - If not found, block this change until OpenRegister base services are in place.
  
- [ ] 0.2 Verify that target apps (`request-management`, `complaint-management`, `callback-management`) have OpenRegister schema definitions and emit `ObjectCreatedEvent` / `ObjectUpdatedEvent`.
  - Check: `find . -name "*_register.json" -exec grep -l "request\|klacht\|callback" {} \;`
  - Check: `grep -r "ObjectCreatedEvent\|ObjectUpdatedEvent" lib/Listener/`
  - If events are not available, block this change.

- [ ] 0.3 Search for any existing SLA or escalation engine implementation.
  - `grep -r "sla\|escalat\|deadline.*target\|breach.*event" lib/ src/ openspec/specs/`
  - If similar capability exists, document overlap. If it's from a merged change, propose extending it instead of starting fresh.

- [ ] 0.4 Confirm that the OpenRegister `importFromApp()` pipeline supports seed data loading.
  - Check: `grep -r "importFromApp\|components.objects" lib/ openregister/`
  - Verify that `@self` envelope and idempotent re-import by slug are supported.

- [ ] 0.5 Verify that Nextcloud background job framework (`IJobList`) is available for `SlaDeadlineSweepJob`.
  - Check: `grep -r "IJobList\|registerJob" lib/`

- [ ] 0.6 Search for any existing holiday-calendar or date-calculation library in the codebase.
  - `grep -r "holiday\|RRULE\|Feestdag" lib/ vendor/`
  - If a library like `nesbot/carbon` or custom holiday logic exists, reuse it instead of reinventing.

  **Findings:** _(document here after running checks)_

---

## 1. Schema: Create `sla_policy` and `sla_breach_event` schemas

- [ ] 1.1 Create `lib/Settings/sla-engine_register.json` with OpenRegister template
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md#REQ-001`
  - **files**: `lib/Settings/sla-engine_register.json`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - OpenRegister template with `x-openregister.type: "application"`
    - Two schemas: `sla_policy` and `sla_breach_event`
    - `sla_policy` properties: `id`, `name`, `description`, `appliesTo`, `customerTier`, `customerScope`, `targets` (array), `escalationChain` (array), `pauseConditions`, `holidayCalendar`, `priority`, `active`, `validFrom`, `validUntil`, `justification`, `status`
    - `targets` array: each element with `kind`, `duration`, `calendar`
    - `escalationChain` array: each element with `triggerAt`, `notify`, `channel`
    - `sla_breach_event` properties: `id`, `policyId`, `targetObjectType`, `targetObjectId`, `targetKind`, `breachedAt`, `consumedPercentage`, `escalationLevel`, `notifiedActors`, `acknowledged`, `acknowledgedAt`, `acknowledgedBy`, `resolvedAt`
    - All enums properly defined with allowed values
    - Schema version >= 1; version increments on changes

- [ ] 1.2 Create seed data in `lib/Settings/sla-engine_register.json`
  - **spec_ref**: Company ADR-001 (seed data requirement)
  - **files**: `lib/Settings/sla-engine_register.json`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - 4 baseline `sla_policy` objects: standaard-request, goud-tier-request, avg-datalek-klacht, callback-default (per design.md)
    - 1 example `sla_breach_event` object (REQ-008 style)
    - Each policy uses `@self` envelope: `{ "register": "sla", "schema": "sla_policy", "slug": "unique-slug" }`
    - All use realistic Dutch `name`, `description`, and `justification` values
    - Re-import with `force: false` MUST skip objects matched by slug
    - Baseline policies are `active: true` and `status: "published"`

---

## 2. Schema: Add `slaStatus` JSON column to tracked types

- [ ] 2.1 Extend `request` schema with `slaStatus` JSON column
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md#REQ-001`, `REQ-002`, `REQ-003`
  - **files**: `lib/Settings/pipelinq_register.json` (or appropriate register for requests)
  - **tier**: P0-must
  - **acceptance_criteria**:
    - `slaStatus` property added as optional JSON object
    - JSON schema documents sub-properties: `policyId`, `startedAt`, `pausedAt`, `totalPausedMs`, `targets` (array), `currentEscalationLevel`, `lastEvaluatedAt`
    - Migration handles existing requests (set `slaStatus: null` for pre-existing, will be populated on next update or by sweep job)
    - Register version incremented

- [ ] 2.2 Extend `klacht` (complaint) schema with `slaStatus` JSON column
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md`
  - **files**: Complaint register (path TBD)
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Same `slaStatus` JSON schema as request

- [ ] 2.3 Extend `callback` schema with `slaStatus` JSON column
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md`
  - **files**: Callback register (path TBD)
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Same `slaStatus` JSON schema as request
    - Note: future opt-in types (`return`, `incident-management`, etc.) will be added by their respective changes

---

## 3. Backend: Core SLA engine service

- [ ] 3.1 Create `lib/Service/SlaEngineService.php`
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md#REQ-001`, `REQ-002`, `REQ-003`, `REQ-004`, `REQ-006`
  - **files**: `lib/Service/SlaEngineService.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Constructor receives `ObjectService`, `IAppConfig`, `NotificationService`, `MailerService`, `EventDispatcher` via DI
    - **Method: `resolvePolicyForObject(string $objectType, string $objectId, array $metadata): ?array`**
      - Queries `sla_policy` objects with matching `appliesTo` and `customerTier`
      - Filters by `customerScope` (contract/org scope matching)
      - Tie-breaks on `priority` (lower), then `validFrom` (newest)
      - Returns matched policy array or null if no match
      - Logging: log tie-break decision for admin audit
    - **Method: `computeDeadlines(array $policy, \DateTimeImmutable $startTime, ?array $pausedState = null): array`**
      - For each target in `policy.targets`, compute `dueAt` using:
        - `duration` (ISO 8601 parsing: `PT4H`, `P1D`, `P3W`, etc.)
        - `calendar` (24x7, business-hours, extended-business-hours)
        - `holidayCalendar` (exclude holidays via tenant config or bundled calendar JSON)
        - Business-hours window (configurable, default 09:00-17:00 Mon-Fri)
        - Paused duration adjustment (if `pausedState` provided, extend deadline)
      - Returns array: `[{ kind: 'acknowledgement', dueAt: '...' }, ...]`
    - **Method: `evaluateTargets(array $currentTargets, \DateTimeImmutable $now): array`**
      - For each target, compute `consumedPercentage = elapsed / duration`
      - Set `status: 'on-track'` if <80%, `'at-risk'` if 80-99%, `'breached'` if >=100%, `'met'` if resolved before deadline
      - Returns updated targets array
    - **Method: `executeEscalations(string $policyId, string $objectType, string $objectId, array $newTargets, array $priorTargets): void`**
      - For each escalation step in `policy.escalationChain`:
        - Check if `triggerAt` threshold was just crossed (prior < threshold, new >= threshold)
        - Check if this `escalationLevel` has NOT already fired for this object (prevent duplicates)
        - Send notification via configured channel (email, nextcloud-notification, whatsapp, sms, webhook)
        - Create `sla_breach_event` record with actor, channel, and `notifiedActors` list
        - Update `slaStatus.currentEscalationLevel`
    - **Method: `pauseTimer(array $slaStatus): array`**
      - Set `pausedAt = now()`, maintain `totalPausedMs`
      - Return updated `slaStatus`
    - **Method: `resumeTimer(array $slaStatus, \DateTimeImmutable $now): array`**
      - Calculate elapsed paused time (respecting business-hours if applicable)
      - Extend all `targets[*].dueAt` by paused duration
      - Clear `pausedAt`
      - Return updated `slaStatus`

- [ ] 3.2 Create `lib/Service/HolidayCalendarService.php`
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md#REQ-010`
  - **files**: `lib/Service/HolidayCalendarService.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Constructor receives `IAppConfig` for tenant-level overrides
    - **Method: `loadCalendar(string $calendarName): array`**
      - Load from `lib/Resources/holidays/{locale}/{calendarName}.json` (e.g., `lib/Resources/holidays/nl/nl-feestdagen-rijksoverheid.json`)
      - Format: array of `{ "date": "2026-04-27", "name": "Koningsdag", "recurring": true/false, "lustrum": true/false }`
      - Support tenant overrides via `IAppConfig` (custom closures, exceptions to national holidays)
    - **Method: `isHoliday(string $calendarName, \DateTimeImmutable $date, ?int $lustrumYear = null): bool`**
      - Check if date is in the calendar
      - Handle `lustrum` flag (Bevrijdingsdag only in divisible-by-5 years) if applicable
      - Merge tenant overrides
    - **Method: `compositeCalendar(array $calendarNames): array`**
      - Union of multiple calendars (e.g., `["nl-feestdagen-rijksoverheid", "be-feestdagen"]`)

- [ ] 3.3 Create `lib/Service/BusinessHoursCalculator.php`
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md#REQ-002`
  - **files**: `lib/Service/BusinessHoursCalculator.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Constructor receives `HolidayCalendarService` and `IAppConfig`
    - **Method: `addDuration(string $calendarType, \DateTimeImmutable $startTime, \DateInterval $duration, string $holidayCalendar, ?array $holidays = null): \DateTimeImmutable`**
      - Supported calendar types: `24x7`, `business-hours`, `extended-business-hours`
      - For `24x7`: simple `$startTime->add($duration)` (wall-clock)
      - For `business-hours`: calculate elapsed business hours, skip weekends and holidays
      - Return computed end time
    - **Method: `getBusinessHoursWindow(): array`**
      - Returns `{ start: '09:00', end: '17:00' }` (configurable per tenant via IAppConfig)
    - **Method: `elapsedBusinessHours(\DateTimeImmutable $startTime, \DateTimeImmutable $endTime, string $holidayCalendar): float`**
      - Calculate business hours elapsed (respecting weekends, holidays, business-hours window)

---

## 4. Backend: Event listeners

- [ ] 4.1 Create `lib/Listener/ObjectCreatedListener.php`
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md#REQ-001`, `REQ-007`
  - **files**: `lib/Listener/ObjectCreatedListener.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Implements `OCP\EventDispatcher\IEventListener`
    - Constructor receives `SlaEngineService`, `ObjectService`, logger via DI
    - `handle(Event $event)` method:
      - Extracts `objectType`, `objectId`, customer metadata from event
      - Calls `SlaEngineService::resolvePolicyForObject()`
      - If policy found:
        - Computes deadlines via `computeDeadlines()`
        - Creates `slaStatus` sub-object with policy, targets, `startedAt: now()`, `lastEvaluatedAt: now()`
        - Calls `ObjectService::saveObject()` with updated object (before API response)
      - On exception: log error, do NOT throw (fail-safe; REQ-007: listener failure is non-blocking)

- [ ] 4.2 Create `lib/Listener/ObjectUpdatedListener.php`
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md#REQ-003`, `REQ-004`, `REQ-007`
  - **files**: `lib/Listener/ObjectUpdatedListener.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Implements `OCP\EventDispatcher\IEventListener`
    - Constructor receives `SlaEngineService`, `ObjectService`, logger via DI
    - `handle(Event $event)` method:
      - Extracts changed fields from `ObjectUpdatedEvent`
      - If `status` field changed:
        - Check if new status is in `policy.pauseConditions`
        - If pausing: call `pauseTimer()`, persist
        - If resuming (old status was pause-condition): call `resumeTimer()`, extend deadlines, persist
      - Re-evaluate all targets via `evaluateTargets()`
      - If any target status changed: execute escalations via `executeEscalations()`
      - Call `ObjectService::saveObject()` with updated `slaStatus`
      - On exception: log, do NOT throw (fail-safe)

- [ ] 4.3 Register event listeners in `lib/AppInfo/Application.php`
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md#REQ-007`
  - **files**: `lib/AppInfo/Application.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - In `register()` method (or `boot()` if appropriate):
      - `$dispatcher->addServiceListener(ObjectCreatedEvent::class, ObjectCreatedListener::class)`
      - `$dispatcher->addServiceListener(ObjectUpdatedEvent::class, ObjectUpdatedListener::class)`
    - Ensure listeners are configured to fire on request, klacht, and callback object types (possibly via event type filtering or listener condition logic)

---

## 5. Backend: Scheduled deadline sweep job

- [ ] 5.1 Create `lib/Job/SlaDeadlineSweepJob.php`
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md#REQ-008`
  - **files**: `lib/Job/SlaDeadlineSweepJob.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Extends `OCP\BackgroundJob\TimedJob`
    - Constructor receives `SlaEngineService`, `ObjectService`, logger via DI
    - `run($argument)` method:
      - Query all in-flight objects with `slaStatus.targets[*].status in ['on-track', 'at-risk']` and `slaStatus.targets[*].dueAt < now()`
      - Batch-process (100 objects per iteration) to stay under 60-second budget
      - For each object:
        - Reload fresh `slaStatus` from DB
        - Re-evaluate targets via `evaluateTargets()`
        - Execute escalations if thresholds crossed
        - Collect updates into batch
      - Call `ObjectService::saveBatch()` with all updates
      - Log completion time and stats (objects processed, escalations fired)
      - On partial failure: log failures, allow job to exit gracefully (retry on next run)
      - Idempotent: escalations already recorded in `sla_breach_event` will not duplicate (checked by escalation level tracking)

- [ ] 5.2 Register sweep job schedule in `lib/AppInfo/Application.php`
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md#REQ-008`
  - **files**: `lib/AppInfo/Application.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - `$jobList->scheduleJob(SlaDeadlineSweepJob::class, null, 5 * 60)` (default 5-minute interval)
    - Interval is configurable via `IAppConfig` (REQ-008, admin settings)

---

## 6. Backend: Admin settings

- [ ] 6.1 Create or extend admin settings panel
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md#REQ-008`, `REQ-010`
  - **files**: `lib/Settings/Admin.php`, admin template Vue component
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Admin settings response includes:
      - `sweepJobInterval` (in seconds, default 300, min 60, max 1800)
      - `defaultBusinessHoursStart` (time string, default '09:00')
      - `defaultBusinessHoursEnd` (time string, default '17:00')
      - `defaultHolidayCalendar` (select: nl-feestdagen-rijksoverheid, be-feestdagen, none)
      - `customHolidayCalendar` (textarea for RFC 5545 `VEVENT` + `RRULE`)
    - Settings are persisted via `IAppConfig`
    - Form validation: sweep interval must be integer, time fields must be valid HH:MM format
    - All labels translated (i18n keys defined in task 7)

---

## 7. Backend: i18n

- [ ] 7.1 Add i18n keys for SLA engine
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md` (all REQs reference user-visible strings)
  - **files**: `l10n/en.json`, `l10n/nl.json`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - All user-facing strings (policy names, status labels, escalation messages, etc.) are translatable
    - 30+ keys total (see design.md i18n table)
    - Keys follow ADR-007: sentence case, English string as key
    - Dutch translations use natural Dutch terminology (Brons, Zilver, Goud, Platina; SLA-doel bereikt, SLA overschreden, etc.)
    - Admin panel labels: "Serviceniveauovereenkomst", "Beleid", "Klantenniveau", "Pauzevoorwaarden", "Feestdagenkalender", "Behaald percentage", "Inbreukgebeurtenis", "Escalatieketen"

---

## 8. Frontend: SLA policy CRUD UI

- [ ] 8.1 Add policy list view (standard `CnIndexPage`)
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md#REQ-005`, `REQ-009`
  - **files**: Vue component for policy list (path TBD based on app structure)
  - **tier**: P0-must
  - **acceptance_criteria**:
    - `CnIndexPage` with `CnDataTable` showing: `name`, `appliesTo`, `customerTier`, `priority`, `status`, `validFrom`, `validUntil`, actions (edit, publish/archive, delete)
    - Filter sidebar: by `appliesTo`, `status`, `active`
    - Columns sortable by `name`, `priority`, `status`

- [ ] 8.2 Add policy detail / edit view
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md#REQ-009`
  - **files**: Vue component for policy detail (path TBD)
  - **tier**: P0-must
  - **acceptance_criteria**:
    - `CnDetailPage` with form auto-generated from `sla_policy` schema (via `CnFormDialog`)
    - CRITICAL: `justification` field MUST be required and visible in the form (non-nullable, large textarea)
    - Edit form shows before/after diff when modifying existing policy
    - Audit trail sidebar tab: shows history of all policy edits with actor, timestamp, diff, justification
    - Actions: Publish (draft → published), Archive (published → archived), Unarchive, Delete

---

## 9. Frontend: Customer tier assignment

- [ ] 9.1 Add tier selector to organisation detail
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md#REQ-005`
  - **files**: Organisation detail component (from pipelinq or appropriate app)
  - **tier**: P0-must
  - **acceptance_criteria**:
    - SLA sidebar section with dropdown: select `bronze`, `silver`, `gold`, `platinum`, or null (unset, defaults to bronze)
    - Label: `t('sla', 'Customer tier')`
    - Persisted via `ObjectService::saveObject()` on change
    - Hint text: "Tier changes only affect new objects; in-flight objects retain their original policy."

- [ ] 9.2 Add tier selector to contract detail
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md#REQ-005`
  - **files**: Contract detail component (from docudesk or contract management app)
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Same tier dropdown as organisation
    - Contract-level tier overrides organisation-level tier

---

## 10. Frontend: SLA status on tracked object lists

- [ ] 10.1 Add `slaStatus` badge column to request list
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md#REQ-006`
  - **files**: Request list component (from request-management app)
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Column header: `t('sla', 'SLA status')`
    - Badge rendering (per target's `status`):
      - `on-track` → green "✓ In progress"
      - `at-risk` → yellow "⚠ At risk (80%+)"
      - `breached` → red "✗ Breached"
      - `met` → teal "✓ Completed"
      - null → grey "–" (no SLA assigned)
    - Badge is sortable; rows filterable by status

- [ ] 10.2 Add `slaStatus` column to complaint list
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md`
  - **files**: Complaint list component
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Same badge scheme as requests

- [ ] 10.3 Add `slaStatus` column to callback list
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md`
  - **files**: Callback list component
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Same badge scheme as requests

- [ ] 10.4 Add `slaStatus` facet to list sidebar
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md`
  - **files**: Request/complaint/callback list components
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Facet field: `slaStatus`
    - Options: `on-track`, `at-risk`, `breached`, `met`, `null` (as "No SLA")
    - Selected facets persist in URL query params

---

## 11. Frontend: SLA status on tracked object detail views

- [ ] 11.1 Add "Service Level Agreement" sidebar section to request detail
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md#REQ-001`, `REQ-004`, `REQ-006`
  - **files**: Request detail component
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Section header: `t('sla', 'Service Level Agreement')`
    - Show: policy name (linked to policy detail), current tier (if applicable)
    - Target countdown table:
      - Columns: Kind (Acknowledgement, First Response, Resolution), Due At (countdown clock), Status (badge: on-track / at-risk / breached / met)
      - Clock shows time remaining if on-track; "Overdue by X hours" if breached
    - Escalation progress: "Escalation level: 2 of 3" (show which levels have fired)
    - Escalation log: expandable table listing past `sla_breach_event` records (when, who notified, channel, acknowledged?)

- [ ] 11.2 Add same SLA section to complaint detail
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md`
  - **files**: Complaint detail component
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Identical to request detail section

- [ ] 11.3 Add same SLA section to callback detail
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md`
  - **files**: Callback detail component
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Identical to request detail section

---

## 12. Frontend: Attainment dashboard widget

- [ ] 12.1 Create attainment dashboard endpoint
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md#REQ-006`
  - **files**: `lib/Controller/SlaAttainmentController.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Endpoint: `GET /api/sla/attainment`
    - Query params:
      - `policy` (UUID, optional): filter by policy
      - `groupBy` (string: policy, customer, tier, team; optional, default: policy)
      - `bucket` (string: day, week, month, quarter; required)
      - `quarter` (string: YYYY-Qn, e.g., "2026-Q2"; if bucket=quarter, required)
      - `month` (string: YYYY-MM, if bucket=month, required)
      - `week` (string: YYYY-Wnn ISO 8601, if bucket=week, required)
      - `date` (string: YYYY-MM-DD, if bucket=day, required)
    - Response:
      ```json
      {
        "attainment": 0.92,
        "total": 100,
        "met": 92,
        "breached": 8,
        "inFlightBreached": 3,
        "closedBreached": 5,
        "details": {
          "byTarget": {
            "acknowledgement": { "attainment": 0.95, "breached": 5 },
            "resolution": { "attainment": 0.88, "breached": 12 }
          },
          "byGroup": [
            {
              "groupKey": "policy-uuid-1",
              "groupName": "Standaard request-SLA",
              "attainment": 0.91,
              "total": 50,
              "met": 45,
              "breached": 5
            }
          ]
        }
      }
      ```
    - Computation:
      - Query all `sla_breach_event` records in the time bucket
      - Group by `groupBy` field
      - For each group: count objects that met all targets vs. breached at least one
      - Separate in-flight (still open) vs. closed breached
      - Per-target accounting: a request that met acknowledgement but breached resolution counts as met (1.0) for acknowledgement, breached (0.0) for resolution

- [ ] 12.2 Create attainment dashboard Vue widget
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md#REQ-006`
  - **files**: Vue component for mydash or standalone dashboard
  - **tier**: P0-must
  - **acceptance_criteria**:
    - KPI card: shows quarterly attainment percentage + trend (↑ ↓ —)
    - Time bucket selector: day / week / month / quarter (default: quarter)
    - Group selector: policy / customer / tier / team (default: policy)
    - Drilldown table: policy/customer/tier/team name, total, met, breached, in-flight-breached, % attainment
    - Rows sortable by attainment, total, breached
    - Export button: download as CSV (policy/customer, attainment, total, met, breached)

---

## 13. Testing and validation

- [ ] 13.1 Unit tests: `SlaEngineService`
  - **spec_ref**: All REQs
  - **files**: `tests/Unit/Service/SlaEngineServiceTest.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Test policy resolution: tie-breaking by priority, validFrom, customerTier, customerScope
    - Test deadline computation: business-hours, holidays, pause/resume, ISO 8601 durations
    - Test escalation logic: trigger thresholds, idempotency, multi-level fires
    - Test edge cases: no matching policy, paused across holiday, DST transition
    - PHPUnit with mocked `ObjectService`, `HolidayCalendarService`, `BusinessHoursCalculator`
    - Coverage: >90% of SlaEngineService

- [ ] 13.2 Unit tests: `HolidayCalendarService` and `BusinessHoursCalculator`
  - **spec_ref**: REQ-002, REQ-010
  - **files**: `tests/Unit/Service/HolidayCalendarServiceTest.php`, `tests/Unit/Service/BusinessHoursCalculatorTest.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Test holiday loading (bundled calendars, tenant overrides, composite calendars)
    - Test business-hours math: weekends, holidays, window transitions
    - Test DST edge cases (if applicable to local timezone)
    - Coverage: >90%

- [ ] 13.3 Integration tests: event listeners
  - **spec_ref**: REQ-007, REQ-001, REQ-003
  - **files**: `tests/Integration/Listener/ObjectCreatedListenerTest.php`, `tests/Integration/Listener/ObjectUpdatedListenerTest.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Create a request object via OpenRegister API; verify `slaStatus` is populated synchronously before API response
    - Update request status to pause-condition; verify timer pauses
    - Update status back to in-progress; verify timer resumes and deadlines extend
    - Verify listener exception does not block object save (fail-safe)

- [ ] 13.4 Integration tests: scheduled job
  - **spec_ref**: REQ-008
  - **files**: `tests/Integration/Job/SlaDeadlineSweepJobTest.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Create 50 in-flight requests with expired acknowledgement deadlines (no events since creation)
    - Run sweep job; verify all 50 escalations fire with correct `sla_breach_event` records
    - Verify job completes in <5 seconds (well under 60s budget)
    - Run job again; verify no duplicate escalations fire (idempotent)

- [ ] 13.5 Integration tests: policy CRUD with audit trail
  - **spec_ref**: REQ-009
  - **files**: `tests/Integration/Controller/SlaPolicyControllerTest.php`
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Create policy without justification; verify HTTP 400 error
    - Create policy with justification; verify saved and audit trail created
    - Edit policy targets; verify before/after diff in audit trail and justification recorded
    - Verify in-flight objects do NOT retroactively recompute deadlines (policy immutability)

- [ ] 13.6 E2E tests: admin policy creation and attainment reporting
  - **spec_ref**: REQ-005, REQ-006, REQ-009
  - **files**: `tests/E2E/SlaAdminWorkflow.spec.js` (or similar framework)
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Admin creates gold-tier policy via policy detail form
    - Admin assigns customer to gold tier
    - Admin creates request for that customer; verify gold-tier policy is resolved
    - Request meets acknowledgement but breaches resolution
    - Admin views attainment dashboard; verify correct per-target accounting

---

## 14. Documentation and schema sync

- [ ] 14.1 Update CLAUDE.md or project documentation
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md` (all)
  - **files**: `.claude/docs/architecture/sla-engine.md` or similar
  - **tier**: P1-should
  - **acceptance_criteria**:
    - High-level diagram: object creation → policy resolution → deadline computation → event listener → escalation chain
    - Links to spec.md and design.md
    - Operator guide: how to create policies, assign tiers, configure holidays, troubleshoot escalation failures

- [ ] 14.2 Add ADR (Architecture Decision Record) for SLA engine design
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md`
  - **files**: `.claude/openspec/architecture/adr-sla-engine.md` (if company-wide) or `openspec/architecture/adr-sla-engine.md` (if app-specific)
  - **tier**: P1-should
  - **acceptance_criteria**:
    - Decision: why SLA is implemented as a cross-cutting listener (not embedded in each workstream app)
    - Alternatives considered: polling, webhook-based, embedded SLA logic
    - Consequences: reduced duplication, but requires event integration contract from all workstream apps

---

## 15. Deployment and rollout

- [ ] 15.1 Migration: create repair step to initialize `slaStatus` on existing objects
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md#REQ-001`
  - **files**: `lib/Migration/InitSlaStatus.php` (implement `IRepairStep`)
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Repair step runs on app install or upgrade
    - Queries all existing request/klacht/callback objects with `slaStatus: null`
    - For each object: calls `SlaEngineService::resolvePolicyForObject()`, computes deadlines, sets `slaStatus`
    - Batches updates to avoid timeout
    - Logs completion stats (objects initialized, policies resolved)
    - Idempotent: running twice does not duplicate or overwrite

- [ ] 15.2 Seed data import
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md`
  - **files**: Repair step or `SettingsLoadService` integration
  - **tier**: P0-must
  - **acceptance_criteria**:
    - Baseline policies are imported from `lib/Settings/sla-engine_register.json` on app install
    - Re-importing with `force: false` skips policies matched by slug (idempotent)
    - Admin can re-import from admin settings if needed

---

## 16. Final validation

- [ ] 16.1 Code review checklist
  - **spec_ref**: All
  - **acceptance_criteria**:
    - All classes have `@spec` PHPDoc tags linking to openspec/changes/sla-engine-and-escalation/specs.md
    - No hardcoded strings; all user-facing text via `t()`
    - No direct DB access outside mappers; use `ObjectService` for CRUD
    - No polling; all state changes driven by events or scheduled job
    - All public methods documented
    - No secrets in logs

- [ ] 16.2 Automated test suite passes
  - **spec_ref**: All
  - **acceptance_criteria**:
    - `npm run test` (or `phpunit`) returns exit code 0
    - `npm run lint` (or `phpcs`) returns exit code 0
    - Coverage reports generated (>80% overall)

- [ ] 16.3 Manual smoke test
  - **spec_ref**: All REQs
  - **acceptance_criteria**:
    - Create a request; verify `slaStatus` is populated with policy and deadlines
    - Verify SLA badge appears in request list
    - Verify SLA section appears in request detail
    - Create a gold-tier customer and request; verify gold-tier policy is selected
    - Pause/resume request; verify deadlines extend by paused time
    - Wait for/simulate deadline crossing; verify escalation notification is sent and `sla_breach_event` is created
    - View attainment dashboard; verify correct stats
    - Edit a policy with justification; verify audit trail recorded

- [ ] 16.4 Performance validation
  - **spec_ref**: REQ-008
  - **acceptance_criteria**:
    - Sweep job on 10,000 in-flight objects: <60 seconds on reference sizing
    - Policy resolution on request creation: <100ms (no perceptible API latency)
    - Attainment endpoint on 1M breach events: <5 seconds response time
    - Database queries are indexed (policy lookup, breach event aggregation)


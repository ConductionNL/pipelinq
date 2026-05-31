# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.18] - 2026-05-31

### Added

- Calculation annotations (`x-openregister-calculations`) on the `lead` schema,
  declaring derived/computed fields per ADR-031 instead of service code:
  `qualificationScore` (backend score 0-100, `materialise: true`, mirrors the
  lead-management default scoring criteria — resolves the spec's frontend-vs-backend
  open question in favour of a backend calculation), `daysSinceActivity`
  (staleness, virtual, diffs `@self.updated` against `now`), `daysInStage`
  (aging, virtual, diffs `stageEnteredAt`/`@self.created` against `now`), and
  `weightedValue` (`value * probability / 100` for the Pipeline Value KPI).
  Validated clean against OpenRegister's `CalculationAnnotationValidator`.
- Backing lead properties: `qualificationScore` (integer), `stageEnteredAt`
  (date-time, aging input), and `description` (scoring input).
- Archival retention annotations (`x-openregister-archival.retention`) on
  `kennisartikel` (`P7Y` archived knowledge-base versions), `task` (`P2Y`
  completed task / callback history), and `contactmoment` (`P2Y` resolved
  client contact log). Each carries condition-based rules with reasons;
  DPO sign-off on exact periods is pending. Validated clean against
  OpenRegister's `ArchivalAnnotationValidator`.
- Unit tests asserting both annotation families are well-formed
  (`RegisterAnnotationsTest::testCalculationAnnotationsAreWellFormed` and
  `testArchivalAnnotationsAreWellFormed`).

### Changed

- `lead-management` spec: the Lead Qualification Scoring requirement now states
  the score is a backend `x-openregister-calculations.qualificationScore`
  materialised on save, read by the frontend.
- Moved eleven hardcoded magic-number/URL constants to admin-config (Phase 7 of
  OR-adoption), all with behavior-preserving defaults: background-job timing
  (`pipelinq.queue_overflow.poll_interval_seconds`,
  `pipelinq.task_expiry.poll_interval_seconds` / `.escalation_threshold_seconds`
  / `.in_progress_grace_seconds`, `pipelinq.task_escalation.threshold_hours`),
  business hours (`pipelinq.task.business_hour_start` / `.business_hour_end`),
  prospect-discovery cache TTL (`pipelinq.prospect_discovery.cache_ttl_seconds`),
  and the KVK / OpenCorporates API base URLs (`pipelinq.kvk.api_base_url`,
  `pipelinq.opencorporates.api_base_url`) so EU/regional tenants can point at
  alternate endpoints. The API-URL keys are admin-only (written via the
  `#[AuthorizedAdminSetting]`-gated SettingsController), so no SSRF surface is
  introduced. `SettingsService` gained a `TUNABLE_DEFAULTS` registry plus typed
  `getIntValue`/`getStringValue` getters. (The audit's cited
  `KennisbankReviewJob` review-interval constant no longer exists — kennisbank
  was migrated to XWiki — so nothing was migrated for it.)
- `contacts-sync` spec rewritten to consume OpenRegister's `contacts-actions`
  integration provider (`ContactMatchingService`, registered via
  `pluggable-integration-registry`) for contact matching/scoring instead of a
  bespoke algorithm; added a graceful-degradation requirement for when the
  provider is absent (matching is skipped, write-back/import still complete).
- `lead-management` spec: cited the `x-openregister-calculations.weightedValue`
  annotation on the Pipeline Value reporting scenario and resolved the stale
  "frontend vs backend scoring" open question in favour of the OR calculation
  engine; field-table enums (`source`, `priority`) intentionally retained.
- `pipelinq-or-adoption` change spec: declared `src/store/modules/object.js` as
  the canonical `createObjectStore` exemplar (no code change — the live file
  already matches the declared plugin list), cross-linked the
  `openregister-integration` exemplar spec, and corrected the `adr-000`
  reference to its real path `openspec/architecture/adr-000-data-model.md`.
- Archived openspec change `pipelinq-or-spec-rewrites`
  (`openspec/changes/archive/2026-05-31-pipelinq-or-spec-rewrites/`).

## [0.2.17] - 2026-05-31

### Added

- Lifecycle annotations (`x-openregister-lifecycle`) on the `lead`, `request`,
  `complaint`, `calendarLink` and `kennisartikel` schemas (the `task` schema
  already carried one). Each declares `field` / `initial` / `final` plus
  per-transition `from` / `to`, a human-readable `description` (state-transition
  rule) and an `authorization` block (assignee/author field + optional sales
  group). The kennisartikel `visibility` enum (`intern` / `openbaar`) stays a
  separate orthogonal field, not part of the lifecycle.
- Notification annotations (`x-openregister-notifications`) with `transition`
  triggers on `lead` (`win` → leadWon, `lose` → leadLost), `task`
  (`complete` → taskCompleted, `expire` → taskExpired), `request`
  (`complete` → requestCompleted) and `complaint` (`resolve` →
  complaintResolved). Every `trigger.action` key equals a lifecycle transition
  **name** (not a destination state) so OpenRegister's
  AnnotationNotificationDispatcher matches it against
  `ObjectTransitionedEvent::getAction()`.
- `RegisterAnnotationsTest` — asserts every lifecycle annotation is well-formed
  (field/initial/transitions resolve to declared enum states, each transition
  documented) and that every notification transition `action` resolves to a
  declared transition name and never to a destination state.

### Notes

- Behaviour-preserving: on-wire status values are unchanged and the existing
  imperative `NotificationService::send()` and `ActivityService` paths are
  retained. The new transition-triggered notification rules stay **dormant**
  until pipelinq routes its status changes through OpenRegister's
  `TransitionEngine` (today status is written directly via `saveObject`, which
  does not dispatch `ObjectTransitionedEvent`). `ActivityService::setSubject()`
  is intentionally not migrated — it feeds the Nextcloud Activity stream, a
  surface the notification-annotation runtime does not replace.
- Implements openspec change `pipelinq-or-lifecycle-notification` (Phase 2
  lifecycle + Phase 3 notification annotation migration).

## [0.2.16] - 2026-05-31

### Changed

- Introduced `RegisterResolverService` and migrated the eight
  `$appConfig->getValueString(APP_ID, 'register', '')` call sites in
  `QueueService` (4), `DefaultQueueService` (2), `ContactVcardService` (1) and
  `ContactVcardWriterService` (1) to `RegisterResolverService::resolve(...)`.
  Behavior-preserving: every logical name resolves to the same instance-scoped
  `register` config value, now request-scoped memoised. Phase 1 of the
  pipelinq OR-abstractions adoption (openspec change
  `pipelinq-or-register-resolver`). 16 register reads in 11 other files remain
  for a follow-up slice.
- PHPMD burn-down: cleared all 36 above-baseline PHPMD violations via
  behavior-preserving refactors — extracted methods to cut CyclomaticComplexity /
  NPathComplexity / ExcessiveMethodLength across PublicSurveyController,
  PublicFormController, SchedulesController, ActivityTimelineService,
  RoutingService and ScheduledTaskService; renamed short variables; added missing
  `use` imports; reshaped `if/else` to early-returns; converted
  `neutralizeCsvCell` array-callables to first-class callables to clear the
  UnusedPrivateMethod false positive; documented residual class-level
  complexity/coupling with `@SuppressWarnings`. `composer phpmd` now exits 0
  above baseline.

### Notes

- The PHPMD baseline (`phpmd.baseline.xml`) is intentionally retained: it still
  suppresses 21 pre-existing violations in files outside this slice's scope.
  Deleting the baseline and dropping `--baseline-file` is deferred to a follow-up
  change `pipelinq-quality-phpmd-baseline-empty`.

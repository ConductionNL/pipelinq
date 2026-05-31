# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.16] - 2026-05-31

### Changed

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

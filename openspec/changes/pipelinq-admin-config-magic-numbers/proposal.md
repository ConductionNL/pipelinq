# pipelinq: move hardcoded constants to admin-config

## Why

Split from the parent `pipelinq-adopt-or-abstractions` change per ADR-032
(spec-sizing cap: ≤20 unchecked tasks per change). See
`openspec/changes/archive/2026-05-18-pipelinq-adopt-or-abstractions-split/`
for the original bundled proposal and design.

This slice covers Phase 7 only: 12 hardcoded constants (timing intervals,
business hours, third-party API base URLs, cache TTL, default review intervals)
that must become admin-config for multi-tenant deployments. Audit citation:
`.claude/audit-2026-05-03/04-hardcoded.md`.

Defaults preserve current behavior (zero-behavior-change install).

## What Changes

1. Eight background-job timing constants → admin-config keys
   (`pipelinq.kennisbank.review_interval_days`,
   `pipelinq.queue_overflow.poll_interval_seconds`, three for `task_expiry`,
   `pipelinq.task_escalation.threshold_hours`).
2. Two `TaskService` business-hours constants → admin-config in the tenant's
   configured timezone (Europe/Amsterdam default).
3. One prospect-discovery cache TTL constant → admin-config.
4. Two third-party API base URLs (`KvkApiClient`, `OpenCorporatesApiClient`) →
   admin-config so EU/UK tenants can point at regional endpoints.
5. Confirm Dutch state literals are gone (cross-check with the
   lifecycle+notification slice).

## Affected Projects

- pipelinq (consumer)

## Impact

- Affected code (apply-phase hints, NOT changed here):
  `lib/BackgroundJob/KennisbankReviewJob.php`,
  `lib/BackgroundJob/QueueOverflowJob.php`,
  `lib/BackgroundJob/TaskExpiryJob.php`,
  `lib/BackgroundJob/TaskEscalationJob.php`,
  `lib/Service/TaskService.php`,
  `lib/Service/ProspectDiscoveryService.php`,
  `lib/Service/KvkApiClient.php`,
  `lib/Service/OpenCorporatesApiClient.php`.
- Affected specs: new `pipelinq-or-adoption` capability slice (delta only).
- Breaking changes: none — defaults preserve current behavior.

## See Also

- `openspec/changes/archive/2026-05-18-pipelinq-adopt-or-abstractions-split/design.md`
  (Decision 5: third-party API URLs become admin-config; Decision 6: defaults
  preserve behavior; Decision 8: tenant-tunable timing).
- `.claude/audit-2026-05-03/04-hardcoded.md` — audit source.

# Split per ADR-032

This change was archived on 2026-05-18 because its tasks.md held 30 unchecked
items — over Hydra ADR-032's spec-sizing cap (≤20 unchecked tasks per change).

The original `proposal.md` and `tasks.md` remain in this archive folder for
reference; sub-changes reference back here for the parent proposal.

## Split into

- `pipelinq-quality-phpcs-burndown` (9 tasks) — Phase 1 inventory/planning
  + Phase 2 PHPCS exclude-pattern burn-down.
- `pipelinq-quality-phpmd-burndown` (9 tasks) — Phase 3 PHPMD burn-down +
  PHPMD-specific Phase 5 baseline cleanup.
- `pipelinq-quality-phpstan-burndown` (11 tasks) — Phase 4 PHPStan burn-down +
  Phase 5 CI integration + Phase 6 documentation.

Total: tasks regrouped across 3 sub-changes. Each sub-change is ≤20 unchecked
tasks. Mirrors the `openconnector-legacy-quality-cleanup` pattern used in the
companion split.

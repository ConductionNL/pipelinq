# pipelinq: PHPCS burn-down + inventory

## Why

Split from the parent `pipelinq-legacy-quality-cleanup` change per ADR-032
(spec-sizing cap: ≤20 unchecked tasks per change). See
`openspec/changes/archive/2026-05-18-pipelinq-legacy-quality-cleanup-split/`
for the original bundled proposal.

This slice carves off the inventory/planning bits (Phase 1 of the parent) and
the PHPCS-specific burn-down (Phase 2). pipelinq has 3 phpcs.xml
exclude-patterns to clear; this is the smallest of the three gates and a
clean first PR.

## What Changes

- Capture baseline counts for phpcs, phpmd, phpstan as a one-shot inventory.
  Outputs feed the PHPMD and PHPStan burn-down slices.
- Decide per gate: fix-outright (if <50 violations) or capture a fresh
  baseline.
- Confirm CI runs `composer check:strict` on every PR.
- Burn down the 3 phpcs.xml exclude-patterns one file at a time, dropping the
  legacy-debt block from phpcs.xml when zero excludes remain.

## Affected Projects

- pipelinq (consumer)

## Impact

- Affected files: `phpcs.xml` (exclude-pattern removals + legacy-debt block
  removal), per-file PHPCS fixes under `lib/`.
- Breaking changes: none.

## See Also

- `openspec/changes/archive/2026-05-18-pipelinq-legacy-quality-cleanup-split/proposal.md`
  — parent bundled proposal.
- `.claude/audit-2026-05-03/03-repo-hygiene.md` — audit source (in
  openregister).
- `phpcs.xml` (the legacy-debt baseline section).

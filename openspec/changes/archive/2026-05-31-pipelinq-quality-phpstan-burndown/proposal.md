# pipelinq: PHPStan burn-down + CI integration + docs

## Why

Split from the parent `pipelinq-legacy-quality-cleanup` change per ADR-032
(spec-sizing cap: ≤20 unchecked tasks per change). See
`openspec/changes/archive/2026-05-18-pipelinq-legacy-quality-cleanup-split/`
for the original bundled proposal.

This slice covers PHPStan-specific burn-down (Phase 4 of the parent), the CI
integration consolidation (Phase 5 minus the PHPMD-specific cleanup carved off
to the PHPMD slice), and the documentation pass (Phase 6).

## What Changes

- Burn down PHPStan errors by file/type. Common patterns: missing
  return/param types, mixed types, possibly-null dereferences.
- Once the PHPStan baseline reaches 0 lines (or was never created): confirm
  the gate runs clean against current code.
- Wire `composer check:strict` into CI on every PR; add a weekly cron on
  `development`.
- Update README quality-gates section and `app-config.json` note.

## Affected Projects

- pipelinq (consumer)

## Impact

- Affected files: `phpstan-baseline.neon` (deletion when empty),
  CI workflow file under `.github/workflows/`, `README.md`,
  `app-config.json`, per-file PHPStan fixes under `lib/`.
- Breaking changes: none.

## See Also

- `openspec/changes/archive/2026-05-18-pipelinq-legacy-quality-cleanup-split/proposal.md`
  — parent bundled proposal.
- `pipelinq-quality-phpcs-burndown` — sibling slice that captures the PHPStan
  baseline.
- `.claude/audit-2026-05-03/03-repo-hygiene.md` — audit source (in
  openregister).

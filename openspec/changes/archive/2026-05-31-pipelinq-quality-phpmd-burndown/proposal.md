# pipelinq: PHPMD burn-down

## Why

Split from the parent `pipelinq-legacy-quality-cleanup` change per ADR-032
(spec-sizing cap: ≤20 unchecked tasks per change). See
`openspec/changes/archive/2026-05-18-pipelinq-legacy-quality-cleanup-split/`
for the original bundled proposal.

This slice covers PHPMD-specific burn-down (Phase 3 of the parent + the
PHPMD-cleanup portion of Phase 5). Contingent on the PHPMD baseline captured
in the PHPCS+inventory slice.

## What Changes

- Burn down PHPMD violations by category (ElseExpression,
  CyclomaticComplexity / NPathComplexity, MissingImport, StaticAccess,
  variable-naming).
- Once the PHPMD baseline reaches 0 lines: delete `phpmd.baseline.xml` and
  drop `--baseline-file` from `composer.json`'s phpmd script.

> **Scope note (implementation):** this slice drove the **36 above-baseline**
> violations to 0 via genuine refactors (method extraction, first-class
> callables, naming, imports, documented class-level `@SuppressWarnings`). The
> baseline still legitimately suppresses **21 pre-existing** violations in files
> outside this slice's scope (BackgroundJob/* unused params,
> `Notifier::applyNotificationSubject` length, several `MissingImport`,
> `ElseExpression`, `NotificationService` TooManyPublicMethods,
> `EmailSyncService` ExcessiveParameterList, `TaskService::validateTask` CC/NPath).
> Because the baseline is NOT empty, the "delete baseline" steps stay deferred to
> a dedicated follow-up change **`pipelinq-quality-phpmd-baseline-empty`** rather
> than expanding this slice past the ADR-032 sizing cap.

## Affected Projects

- pipelinq (consumer)

## Impact

- Affected files: `phpmd.baseline.xml` (deletion when empty),
  `composer.json` (script edit), per-file PHPMD fixes under `lib/`.
- Breaking changes: none.

## See Also

- `openspec/changes/archive/2026-05-18-pipelinq-legacy-quality-cleanup-split/proposal.md`
  — parent bundled proposal.
- `pipelinq-quality-phpcs-burndown` — sibling slice that captures the PHPMD
  baseline.
- `.claude/audit-2026-05-03/03-repo-hygiene.md` — audit source (in
  openregister).

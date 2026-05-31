> SUPERSEDED 2026-05-31: this umbrella was split per ADR-032 into child slices that are now all implemented and archived. Coverage: Phases 1-2 (inventory + PHPCS burn-down) → `pipelinq-quality-phpcs-burndown`; Phase 3 (PHPMD) → `pipelinq-quality-phpmd-burndown`; Phases 4-6 (PHPStan + CI integration + docs) → `pipelinq-quality-phpstan-burndown`. All implementation scope is covered and the gate runs clean (`composer check:strict`). NOTE: the parent's `quality-gates` delta spec (REQ-QG-001..006) was authored here but never synced to `openspec/specs/` — the children referenced this parent's tasks rather than re-declaring the spec. The requirements it describes are all satisfied; the spec remains in this archived change as the canonical record.

# Proposal: Pipelinq Legacy Quality Cleanup

## Problem

Pipelinq's quality gates carry a small amount of legacy debt absorbed as
exclude-patterns in `phpcs.xml`. Three files are excluded from PHPCS checks
because they predate the current quality conventions. Additionally, PHPMD and
PHPStan have never been run as a unified gate — no baselines exist for either
tool. This means the `composer check:strict` gate cannot reliably catch
regressions introduced by new work.

The OR-abstraction audit (2026-05-03, stream 3) flagged this gap. With only
3 exclude-patterns, the cleanup effort is small enough to track and burn down
in a single sprint.

## Solution

Phase-by-phase gate hardening:

1. **Inventory** — Run all three tools from scratch, count violations, decide
   per tool whether to fix outright (< 50 violations) or capture a fresh
   baseline.
2. **PHPCS burn-down** — For each of the 3 excluded files: add proper
   docblocks, audit named-parameter call sites, fix remaining sniff violations,
   then drop the `<exclude-pattern>` entry.
3. **PHPMD burn-down** — Fix or baseline the violations surfaced on first run.
   Target categories: ElseExpression, CyclomaticComplexity, MissingImport,
   StaticAccess, variable-naming rules.
4. **PHPStan burn-down** — Fix or baseline the errors surfaced on first run.
   Target categories: missing return/param types, mixed types, possibly-null
   dereferences.
5. **CI integration** — Confirm `composer check:strict` runs on every PR and
   add a weekly cron smoke-test on `development`.
6. **Documentation** — Update README quality-gates section and close the
   burn-down tracking issue.

## Scope

### In Scope

- `phpcs.xml` — remove all 3 legacy `<exclude-pattern>` entries after
  fixing each file; drop the legacy-debt block when empty
- The 3 legacy-excluded PHP files — add docblocks, fix named-parameter calls,
  resolve sniff violations
- `phpmd.xml` / `phpmd.baseline.xml` — first-run execution, then baseline
  capture or fix-outright
- `phpstan.neon` / `phpstan-baseline.neon` — first-run execution, then
  baseline capture or fix-outright
- `composer.json` `check:strict` script — confirm phpcs, phpmd, phpstan are
  all included
- CI configuration — verify `composer check:strict` runs on every PR
- README quality-gates section — document the gate setup

### Out of Scope

- Refactoring beyond what the quality sniffs require
- OR-abstraction adoption (owned by separate spec change)
- New features of any kind
- Additional test coverage (separate test-coverage spec change if needed)

## Affected Files

- `phpcs.xml` — MODIFY (drop 3 exclude-patterns + legacy-debt block)
- Up to 3 legacy PHP files (identified in Phase 1) — MODIFY (docblocks + sniff fixes)
- `phpmd.xml` — MODIFY if needed (baseline path config)
- `phpmd.baseline.xml` — CREATE or DELETE depending on Phase 1 volume
- `phpstan.neon` — MODIFY if needed (baseline path config)
- `phpstan-baseline.neon` — CREATE or DELETE depending on Phase 1 volume
- `composer.json` — MODIFY if `check:strict` is incomplete
- CI configuration file — MODIFY if `check:strict` is not wired
- `README.md` (quality-gates section) — MODIFY

## Estimated Effort

1–2 PRs over 1 sprint.

## See Also

- Canonical audit: `openregister/.claude/audit-2026-05-03/03-repo-hygiene.md`
- `phpcs.xml` legacy-debt baseline section
- Hydra ADR-022 — apps consume OR abstractions; quality conventions
- `composer.json` `check:strict` script (unified gate target)

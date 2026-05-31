# Pipelinq Legacy Quality Cleanup

## Why

The OR-abstraction audit (2026-05-03, stream 3 + the quality-gates
cleanup at session start) flagged that pipelinq's quality gates
have a small amount of legacy debt absorbed via exclude patterns.
Burning these down keeps PR diffs honest — gates catch real
regressions rather than silently absorbing already-broken code.

Pipelinq has only 3 phpcs.xml exclude-patterns and no PHPMD or
PHPStan baseline. The bulk of the quality work for pipelinq lives in
the per-app OR-abstraction adoption spec; this change is a thin
tracking change for the remaining gate hardening.

This is a tracking change so the burn-down can be picked up later.
It is spec-only; no code changes are proposed in this change.

## What Changes

- Inventory and clear the 3 phpcs.xml exclude-patterns. For each:
  add proper docblocks + named-parameter call audits, then drop
  the exclude.
- Run PHPMD for the first time as a unified gate (phpmd.xml is
  configured but no baseline exists). Capture surfacing violations
  as a baseline OR fix outright depending on volume.
- Run PHPStan for the first time as a unified gate. Same trade-off:
  baseline vs fix-outright.
- Wire phpcs/phpmd/phpstan into CI as the unified quality gate.

## Problem

Exclude-patterns exist because the audit captured legacy files that
predated the current quality conventions. The 3-pattern count is
near-zero — the work in this change is mostly about hardening the
gate so it doesn't drift back into having larger exclude lists.

PHPMD/PHPStan baselines don't exist yet because the gates haven't
been run as a unified `check:strict` block. The audit recommended
running them and capturing the result before adoption work.

Most of the actual code-shape work for pipelinq is owned by the
OR-abstraction adoption spec (separate change), not by this gate-
hardening change. This proposal only covers the mechanical gate
burn-down.

## Proposed Solution

File-by-file cleanup. Because the exclude-pattern count is 3,
Phase 2 is three checkboxes. Phases 3-4 are contingent on what
surfaces when PHPMD / PHPStan run unified.

Estimated effort: 1-2 PRs over 1 sprint.

## Out of scope

- Refactoring beyond what the sniff requires
- New features (separate adoption-spec changes own those)
- Feature work — the OR-abstraction adoption spec owns it
- Test additions (separate test-coverage spec change if needed)

## See also

- The canonical audit lives in openregister at
  `.claude/audit-2026-05-03/03-repo-hygiene.md`. Pipelinq references
  it from there.
- `phpcs.xml` (the legacy-debt baseline section)
- Hydra ADR-022 (apps consume OR abstractions) — quality conventions
- `composer.json` `check:strict` script (the unified gate target)



## Tasks

# Tasks: Pipelinq Legacy Quality Cleanup

## Phase 1 — Inventory + planning

- [ ] Run `composer phpcs` and capture current baseline error count
      (target: starting from 3 exclude-patterns in phpcs.xml)
- [ ] Run `composer phpmd` for the first time as a unified gate
      and capture violation count + categories
- [ ] Run `composer phpstan` for the first time as a unified gate
      and capture error count + categories
- [ ] Decide per gate: fix-outright (if <50 violations) or capture
      a fresh baseline (if larger)
- [ ] Confirm CI runs `composer check:strict` on every PR before
      starting burn-down work

## Phase 2 — PHPCS burn-down (per excluded file)

For each file: fix errors, remove the phpcs.xml `<exclude-pattern>`
entry, verify gate stays green.

- [ ] Excluded file 1 — fix sniffs + drop exclude
- [ ] Excluded file 2 — fix sniffs + drop exclude
- [ ] Excluded file 3 — fix sniffs + drop exclude
- [ ] Once all excludes are gone, drop the legacy-debt block from
      phpcs.xml entirely

## Phase 3 — PHPMD burn-down

Contingent on Phase 1's first-run output. If volume is small, this
phase collapses to a single fix-outright PR.

- [ ] If baseline captured: ElseExpression — re-shape `if/else` to
      early-return
- [ ] If baseline captured: CyclomaticComplexity / NPathComplexity —
      extract methods
- [ ] If baseline captured: MissingImport — add `use` statements
- [ ] If baseline captured: StaticAccess — replace with DI
- [ ] If baseline captured: variable-naming sniffs (Long/Short/
      Undefined/UnusedFormalParameter)
- [ ] Once baseline reaches 0 lines: delete phpmd.baseline.xml and
      drop `--baseline-file` from composer.json's phpmd script

## Phase 4 — PHPStan burn-down

Contingent on Phase 1's first-run output. If volume is small, this
phase collapses to a single fix-outright PR.

- [ ] Inventory phpstan errors by file/type
- [ ] Common patterns to fix:
  - [ ] Missing return-type / param-type declarations
  - [ ] Mixed types (specify generic / union)
  - [ ] Possibly-null dereferences
- [ ] Once baseline reaches 0 lines (or never created): confirm
      gate runs clean against current code

## Phase 5 — CI integration

- [ ] Verify `composer check:strict` runs in CI on every PR
- [ ] Once all baselines are empty:
  - [ ] Delete `phpmd.baseline.xml` (if it was created)
  - [ ] Delete `phpstan-baseline.neon` (if it was created)
  - [ ] Drop the legacy-debt section from `phpcs.xml`
- [ ] Add a smoke-test cron that runs `composer check:strict`
      weekly on `development`

## Phase 6 — Documentation

- [ ] Update README quality-gates section
- [ ] Note in `app-config.json` that legacy quality cleanup is done
- [ ] Close the burn-down tracking issue once the last baseline
      line is removed
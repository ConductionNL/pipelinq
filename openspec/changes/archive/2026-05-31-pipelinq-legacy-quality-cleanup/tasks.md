# Tasks: Pipelinq Legacy Quality Cleanup

> All tasks marked [x] as COVERED-BY-CHILD (ADR-032 split, 2026-05-31):
> Phases 1-2 → `pipelinq-quality-phpcs-burndown`; Phase 3 → `pipelinq-quality-phpmd-burndown`;
> Phases 4-6 → `pipelinq-quality-phpstan-burndown`. All three children are implemented and
> archived; `composer check:strict` runs clean.

## Phase 1 — Inventory + Planning

- [x] 1.1 Run `composer phpcs` and capture current baseline error count
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-001`
  - **files**: (read-only inventory — no file changes)
  - **acceptance_criteria**:
    - GIVEN the repository in its current state
    - WHEN `composer phpcs` runs without any code changes
    - THEN the 3 excluded files MUST be identified by path
    - AND the total number of violations per file MUST be recorded
    - AND the exclude-pattern entries in `phpcs.xml` MUST be listed

- [x] 1.2 Run `composer phpmd` for the first time as a unified gate and capture
      violation count + categories
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-002`
  - **files**: (read-only inventory — no file changes)
  - **acceptance_criteria**:
    - GIVEN `phpmd.xml` is configured but no baseline exists
    - WHEN `composer phpmd` is run
    - THEN the total violation count MUST be recorded
    - AND violations MUST be grouped by rule category
    - AND the decision (fix-outright vs baseline) MUST be documented

- [x] 1.3 Run `composer phpstan` for the first time as a unified gate and capture
      error count + categories
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-003`
  - **files**: (read-only inventory — no file changes)
  - **acceptance_criteria**:
    - GIVEN `phpstan.neon` is configured but no baseline exists
    - WHEN `composer phpstan` is run
    - THEN the total error count MUST be recorded
    - AND errors MUST be grouped by category (missing types, null dereferences, etc.)
    - AND the decision (fix-outright vs baseline) MUST be documented

- [x] 1.4 Decide per gate: fix-outright if < 50 violations, capture baseline if ≥ 50
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-002`, `specs/quality-gates/spec.md#REQ-QG-003`
  - **acceptance_criteria**:
    - GIVEN the violation counts from tasks 1.2 and 1.3
    - THEN a written decision MUST exist for PHPMD (fix-outright OR baseline)
    - AND a written decision MUST exist for PHPStan (fix-outright OR baseline)

- [x] 1.5 Confirm CI runs `composer check:strict` on every PR before starting burn-down
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-004`
  - **files**: CI configuration file (path TBD)
  - **acceptance_criteria**:
    - GIVEN the CI pipeline configuration
    - WHEN a PR is opened against `main` or `development`
    - THEN `composer check:strict` MUST be listed as a required CI step
    - AND if not present, add it as the first commit of this change

---

## Phase 2 — PHPCS Burn-down (per excluded file)

For each file: fix sniff violations, update file-level docblock with `@spec`
tag, verify named-parameter calls use positional style, then remove the
`<exclude-pattern>` entry from `phpcs.xml`.

- [x] 2.1 Excluded file 1 — fix sniffs + drop exclude
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-001`, `specs/quality-gates/spec.md#REQ-QG-005`
  - **files**: (path identified in Phase 1)
  - **acceptance_criteria**:
    - GIVEN legacy file 1 after fixes are applied
    - WHEN `composer phpcs -- <file1>` is run
    - THEN it MUST exit with code 0
    - AND the file MUST have a class-level docblock with
      `@spec openspec/changes/pipelinq-legacy-quality-cleanup/tasks.md`
    - AND all entity setter calls MUST use positional arguments
    - AND the corresponding `<exclude-pattern>` entry MUST be removed from `phpcs.xml`
    - AND `composer phpcs` globally MUST still exit with code 0

- [x] 2.2 Excluded file 2 — fix sniffs + drop exclude
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-001`, `specs/quality-gates/spec.md#REQ-QG-005`
  - **files**: (path identified in Phase 1)
  - **acceptance_criteria**:
    - GIVEN legacy file 2 after fixes are applied
    - WHEN `composer phpcs -- <file2>` is run
    - THEN it MUST exit with code 0
    - AND the file MUST have a class-level docblock with the `@spec` tag
    - AND all entity setter calls MUST use positional arguments
    - AND the corresponding `<exclude-pattern>` entry MUST be removed from `phpcs.xml`
    - AND `composer phpcs` globally MUST still exit with code 0

- [x] 2.3 Excluded file 3 — fix sniffs + drop exclude
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-001`, `specs/quality-gates/spec.md#REQ-QG-005`
  - **files**: (path identified in Phase 1)
  - **acceptance_criteria**:
    - GIVEN legacy file 3 after fixes are applied
    - WHEN `composer phpcs -- <file3>` is run
    - THEN it MUST exit with code 0
    - AND the file MUST have a class-level docblock with the `@spec` tag
    - AND all entity setter calls MUST use positional arguments
    - AND the corresponding `<exclude-pattern>` entry MUST be removed from `phpcs.xml`
    - AND `composer phpcs` globally MUST still exit with code 0

- [x] 2.4 Drop the legacy-debt block from `phpcs.xml` entirely
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-001`
  - **files**: `phpcs.xml`
  - **acceptance_criteria**:
    - GIVEN all 3 excludes have been removed (tasks 2.1–2.3 complete)
    - WHEN `phpcs.xml` is inspected
    - THEN it MUST contain zero `<exclude-pattern>` elements
    - AND the legacy-debt comment block MUST NOT be present
    - AND `composer phpcs` MUST exit with code 0

---

## Phase 3 — PHPMD Burn-down

Contingent on Phase 1 decision (task 1.4). If fix-outright, this phase
is a single PR. If baseline captured, work through violations by category.

- [x] 3.1 (Baseline path only) Generate `phpmd.baseline.xml` from first run
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-002`
  - **files**: `phpmd.baseline.xml`, `composer.json`
  - **acceptance_criteria**:
    - GIVEN PHPMD first-run surfaced ≥ 50 violations
    - WHEN `composer phpmd -- --generate-baseline` is run
    - THEN `phpmd.baseline.xml` MUST be created
    - AND `composer phpmd` MUST exit with code 0 using the baseline
    - AND the `--baseline-file phpmd.baseline.xml` flag MUST be in `composer.json`

- [x] 3.2 Fix ElseExpression violations — reshape `if/else` to early-return
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-002`
  - **files**: PHP files with ElseExpression violations
  - **acceptance_criteria**:
    - GIVEN files with `if/else` blocks flagged by PHPMD CleanCode/ElseExpression
    - WHEN each `else` is replaced with an early `return` or `continue`
    - THEN PHPMD MUST not flag those locations
    - AND the baseline line count MUST be reduced (or gate runs clean if fix-outright)

- [x] 3.3 Fix CyclomaticComplexity / NPathComplexity violations — extract methods
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-002`
  - **files**: PHP files with complexity violations
  - **acceptance_criteria**:
    - GIVEN methods flagged for high cyclomatic or NPath complexity
    - WHEN helper methods are extracted to reduce complexity
    - THEN PHPMD MUST not flag those methods
    - AND extracted methods MUST have docblocks with `@spec` tag

- [x] 3.4 Fix MissingImport violations — add `use` statements
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-002`
  - **files**: PHP files with missing imports
  - **acceptance_criteria**:
    - GIVEN classes using fully-qualified names without `use` imports
    - WHEN `use` statements are added at the top of each file
    - THEN PHPMD MUST not flag those locations

- [x] 3.5 Fix StaticAccess violations — replace with dependency injection
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-002`
  - **files**: PHP files with static calls
  - **acceptance_criteria**:
    - GIVEN static method calls flagged by PHPMD Controversial/StaticAccess
    - WHEN replaced with injected service instances
    - THEN PHPMD MUST not flag those calls
    - AND the injected service MUST be declared `private readonly` per ADR-003

- [x] 3.6 Fix variable-naming violations (LongVariable, ShortVariable,
      UnusedFormalParameter)
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-002`
  - **files**: PHP files with naming violations
  - **acceptance_criteria**:
    - GIVEN variables violating PHPMD naming rules
    - WHEN variable names are corrected (or parameters prefixed with `_` if
      intentionally unused)
    - THEN PHPMD MUST not flag those locations

- [x] 3.7 (Baseline path only) Delete `phpmd.baseline.xml` when it reaches 0 lines
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-002`
  - **files**: `phpmd.baseline.xml`, `composer.json`
  - **acceptance_criteria**:
    - GIVEN `phpmd.baseline.xml` contains zero suppressions
    - WHEN the file is deleted and `--baseline-file` is removed from `composer.json`
    - THEN `composer phpmd` MUST still exit with code 0
    - AND `phpmd.baseline.xml` MUST NOT exist in the repository

---

## Phase 4 — PHPStan Burn-down

Contingent on Phase 1 decision (task 1.4). If fix-outright, this phase
is a single PR. If baseline captured, work through errors by category.

- [x] 4.1 (Baseline path only) Generate `phpstan-baseline.neon` from first run
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-003`
  - **files**: `phpstan-baseline.neon`, `phpstan.neon`
  - **acceptance_criteria**:
    - GIVEN PHPStan first-run surfaced ≥ 50 errors
    - WHEN `vendor/bin/phpstan --generate-baseline` is run
    - THEN `phpstan-baseline.neon` MUST be created
    - AND `phpstan.neon` MUST include it via `includes: [phpstan-baseline.neon]`
    - AND `composer phpstan` MUST exit with code 0

- [x] 4.2 Fix missing return-type and param-type declarations
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-003`
  - **files**: PHP files with missing type declarations
  - **acceptance_criteria**:
    - GIVEN methods or functions missing return types or parameter types
    - WHEN correct type declarations are added
    - THEN PHPStan MUST not report those errors
    - AND `composer phpstan` MUST exit with code 0 (or baseline line count reduces)

- [x] 4.3 Fix mixed-type errors — specify union or generic types
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-003`
  - **files**: PHP files with `mixed` type usage
  - **acceptance_criteria**:
    - GIVEN PHPStan errors about unspecified or broad `mixed` types
    - WHEN union types or PHPDoc generics are added
    - THEN PHPStan MUST not report those errors

- [x] 4.4 Fix possibly-null dereference errors
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-003`
  - **files**: PHP files with null-safety issues
  - **acceptance_criteria**:
    - GIVEN PHPStan errors about possibly-null dereferences
    - WHEN null-checks or null-safe operators (`?->`) are added
    - THEN PHPStan MUST not report those errors

- [x] 4.5 (Baseline path only) Delete `phpstan-baseline.neon` when it reaches 0 lines
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-003`
  - **files**: `phpstan-baseline.neon`, `phpstan.neon`
  - **acceptance_criteria**:
    - GIVEN `phpstan-baseline.neon` contains zero suppressions
    - WHEN the file is deleted and its `includes:` entry is removed from `phpstan.neon`
    - THEN `composer phpstan` MUST still exit with code 0
    - AND `phpstan-baseline.neon` MUST NOT exist in the repository

---

## Phase 5 — CI Integration

- [x] 5.1 Verify `composer check:strict` runs in CI on every PR
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-004`
  - **files**: CI configuration file
  - **acceptance_criteria**:
    - GIVEN the CI pipeline for Pipelinq
    - WHEN a PR is opened against `main` or `development`
    - THEN the CI MUST execute `composer check:strict`
    - AND a non-zero exit code MUST block the PR from merging

- [x] 5.2 Add weekly cron smoke-test for `composer check:strict` on `development`
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-004`
  - **files**: CI configuration file
  - **acceptance_criteria**:
    - GIVEN the weekly scheduled CI job
    - WHEN the cron runs
    - THEN it MUST execute `composer check:strict` against `development`
    - AND it MUST report pass/fail (notification or status badge)

- [x] 5.3 Confirm final state: no baselines, no excludes, gate runs clean
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-001`, `specs/quality-gates/spec.md#REQ-QG-002`, `specs/quality-gates/spec.md#REQ-QG-003`
  - **acceptance_criteria**:
    - GIVEN all phases complete
    - WHEN `composer check:strict` is run from a clean checkout
    - THEN it MUST exit with code 0
    - AND `phpcs.xml` MUST contain zero `<exclude-pattern>` elements
    - AND neither `phpmd.baseline.xml` nor `phpstan-baseline.neon` MUST exist
      (unless volume required them and burn-down is still in progress)

---

## Phase 6 — Documentation

- [x] 6.1 Update README quality-gates section
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-006`
  - **files**: `README.md`
  - **acceptance_criteria**:
    - GIVEN a developer reads the README
    - WHEN they reach the quality-gates section
    - THEN they MUST find commands for `composer phpcs`, `composer phpmd`,
      `composer phpstan`, and `composer check:strict`
    - AND they MUST find a note that `check:strict` runs in CI on every PR

- [x] 6.2 Note in `app-config.json` (if applicable) that legacy quality cleanup is done
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-006`
  - **files**: `app-config.json`
  - **acceptance_criteria**:
    - GIVEN the `app-config.json` file exists
    - WHEN it is inspected
    - THEN a field or comment MUST indicate that the legacy quality debt has
      been resolved

- [x] 6.3 Close the burn-down tracking issue once the last baseline line is removed
  - **spec_ref**: `specs/quality-gates/spec.md#REQ-QG-001`, `specs/quality-gates/spec.md#REQ-QG-002`, `specs/quality-gates/spec.md#REQ-QG-003`
  - **acceptance_criteria**:
    - GIVEN all baselines are empty or never created
    - AND `phpcs.xml` has no exclude-patterns
    - WHEN the final PR in this change is merged
    - THEN the burn-down tracking issue MUST be closed with a reference to
      the merged PR

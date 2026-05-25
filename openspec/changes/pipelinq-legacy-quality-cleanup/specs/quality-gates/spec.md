# Quality Gates — Delta Spec

## Purpose

Define the acceptance criteria for Pipelinq's unified quality gate pipeline
after legacy debt has been burned down. The gate covers PHPCS code style,
PHPMD design-quality rules, and PHPStan static analysis. Once this change is
complete, `composer check:strict` MUST run clean against the full codebase
with no exclude-patterns or baseline suppressions.

**Change:** `pipelinq-legacy-quality-cleanup`
**Feature tier:** Infrastructure / quality hardening

---

## Requirements

### REQ-QG-001: PHPCS Gate — No Exclude Patterns

The `phpcs.xml` configuration MUST NOT contain any `<exclude-pattern>` entries
after this change is complete. All PHP files in the project MUST pass PHPCS
without exclusion.

#### Scenario: phpcs.xml legacy-debt block is removed

- GIVEN the `phpcs.xml` file in the repository
- WHEN the change is merged
- THEN `phpcs.xml` MUST NOT contain any `<exclude-pattern>` elements
- AND `phpcs.xml` MUST NOT contain the legacy-debt comment block
- AND `composer phpcs` MUST exit with code 0

#### Scenario: Previously-excluded file 1 passes PHPCS

- GIVEN legacy file 1 (identified in Phase 1 inventory)
- WHEN `composer phpcs -- <file1>` is run
- THEN the command MUST exit with code 0
- AND the file MUST have a valid class-level docblock
- AND all method calls to entity setters MUST use positional arguments
  (no named-parameter syntax such as `setFoo(foo: $bar)`)

#### Scenario: Previously-excluded file 2 passes PHPCS

- GIVEN legacy file 2 (identified in Phase 1 inventory)
- WHEN `composer phpcs -- <file2>` is run
- THEN the command MUST exit with code 0
- AND the file MUST have a valid class-level docblock
- AND all method calls to entity setters MUST use positional arguments

#### Scenario: Previously-excluded file 3 passes PHPCS

- GIVEN legacy file 3 (identified in Phase 1 inventory)
- WHEN `composer phpcs -- <file3>` is run
- THEN the command MUST exit with code 0
- AND the file MUST have a valid class-level docblock
- AND all method calls to entity setters MUST use positional arguments

---

### REQ-QG-002: PHPMD Gate — Gate Runs Clean

PHPMD MUST run as part of `composer check:strict` and MUST either produce
zero violations or have all remaining violations suppressed by an explicit
baseline file. The baseline MUST be shrinking over time and MUST be deleted
when empty.

#### Scenario: PHPMD gate included in check:strict

- GIVEN the `composer.json` `check:strict` script
- WHEN `composer check:strict` is run
- THEN it MUST invoke `composer phpmd` as one of its steps
- AND `composer phpmd` MUST exit with code 0

#### Scenario: PHPMD runs with no baseline (fix-outright path)

- GIVEN PHPMD first-run surfaced fewer than 50 violations
- AND all violations have been fixed outright
- WHEN `composer phpmd` is run
- THEN it MUST exit with code 0
- AND `phpmd.baseline.xml` MUST NOT exist in the repository

#### Scenario: PHPMD baseline is captured (high-volume path)

- GIVEN PHPMD first-run surfaced 50 or more violations
- WHEN `composer phpmd` is run with `--baseline-file phpmd.baseline.xml`
- THEN it MUST exit with code 0
- AND `phpmd.baseline.xml` MUST exist and MUST NOT be empty at the point of capture
- AND subsequent PRs MUST reduce the baseline line count (no new violations
  may be added)

#### Scenario: PHPMD baseline reaches zero and is deleted

- GIVEN all violations in `phpmd.baseline.xml` have been resolved
- WHEN the last baseline entry is removed
- THEN `phpmd.baseline.xml` MUST be deleted from the repository
- AND the `--baseline-file` flag MUST be removed from the phpmd script in
  `composer.json`
- AND `composer phpmd` MUST still exit with code 0

---

### REQ-QG-003: PHPStan Gate — Gate Runs Clean

PHPStan MUST run as part of `composer check:strict` and MUST either produce
zero errors or have all remaining errors suppressed by an explicit baseline
file. The baseline MUST be shrinking over time and MUST be deleted when empty.

#### Scenario: PHPStan gate included in check:strict

- GIVEN the `composer.json` `check:strict` script
- WHEN `composer check:strict` is run
- THEN it MUST invoke `composer phpstan` as one of its steps
- AND `composer phpstan` MUST exit with code 0

#### Scenario: PHPStan runs with no baseline (fix-outright path)

- GIVEN PHPStan first-run surfaced fewer than 50 errors
- AND all errors have been fixed outright
- WHEN `composer phpstan` is run
- THEN it MUST exit with code 0
- AND `phpstan-baseline.neon` MUST NOT exist in the repository

#### Scenario: PHPStan baseline is captured (high-volume path)

- GIVEN PHPStan first-run surfaced 50 or more errors
- WHEN `vendor/bin/phpstan --generate-baseline` is run
- THEN `phpstan-baseline.neon` MUST be created and included in `phpstan.neon`
- AND subsequent PRs MUST reduce the baseline error count (no new errors
  may be introduced)

#### Scenario: PHPStan baseline reaches zero and is deleted

- GIVEN all errors in `phpstan-baseline.neon` have been resolved
- WHEN the last baseline entry is removed
- THEN `phpstan-baseline.neon` MUST be deleted from the repository
- AND the `includes:` reference in `phpstan.neon` MUST be removed
- AND `composer phpstan` MUST still exit with code 0

---

### REQ-QG-004: CI Gate — check:strict Runs on Every PR

The `composer check:strict` script MUST be executed automatically in CI
for every pull request targeting `main` or `development`. The PR MUST
be blocked if `check:strict` exits non-zero.

#### Scenario: PR is blocked when check:strict fails

- GIVEN a pull request is opened against `main` or `development`
- AND `composer check:strict` exits with a non-zero code
- THEN the CI job MUST be marked as failed
- AND merging the PR MUST be blocked until the gate passes

#### Scenario: PR passes CI when check:strict is clean

- GIVEN a pull request is opened
- AND all of phpcs, phpmd, phpstan return exit code 0
- WHEN `composer check:strict` runs in CI
- THEN the CI job MUST be marked as passed
- AND the gate requirement MUST be satisfied

#### Scenario: Weekly cron smoke-test on development

- GIVEN the `development` branch
- WHEN the weekly scheduled CI job runs
- THEN it MUST execute `composer check:strict`
- AND the result MUST be reported (pass or fail notification)

---

### REQ-QG-005: @spec Traceability on Modified Files

Every PHP file modified by this change MUST carry a `@spec` PHPDoc tag in
its file-level docblock linking back to this change's tasks file.

#### Scenario: Modified file has @spec docblock

- GIVEN any PHP file modified as part of this change
- WHEN the file's class or file-level docblock is inspected
- THEN it MUST contain at least one tag of the form:
  `@spec openspec/changes/pipelinq-legacy-quality-cleanup/tasks.md`
- AND the tag MUST appear in the file-level or class-level docblock
  (not only in a method docblock)

---

### REQ-QG-006: Documentation — README Quality Gates Section

The project README MUST document the quality gate setup after this change
lands, so new contributors know how to run checks locally.

#### Scenario: README documents how to run quality gates

- GIVEN a developer clones the repository
- WHEN they read the README quality-gates section
- THEN they MUST find instructions for running each gate individually:
  - `composer phpcs`
  - `composer phpmd`
  - `composer phpstan`
  - `composer check:strict` (all three together)
- AND they MUST find a note that `check:strict` runs in CI on every PR

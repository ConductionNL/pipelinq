# Design: Pipelinq Legacy Quality Cleanup

## Overview

This change hardens the Pipelinq quality gate pipeline by eliminating the 3
legacy PHPCS exclude-patterns and establishing unified PHPMD and PHPStan gates.
No new entities or API endpoints are introduced — this is a pure quality
infrastructure change.

```
composer check:strict
    ├── composer phpcs   ← removes 3 exclude-patterns from phpcs.xml
    ├── composer phpmd   ← first-run baseline capture or fix-outright
    └── composer phpstan ← first-run baseline capture or fix-outright
         ↓
    CI pipeline (every PR)
    + weekly cron smoke-test on development
```

---

## Phase Breakdown

### Phase 1 — Inventory

Run each tool independently from a clean checkout before touching any code.
Record the raw violation count and categories.

| Gate    | Command                    | Decision threshold |
|---------|----------------------------|--------------------|
| PHPCS   | `composer phpcs`           | Fix outright (3 excluded files) |
| PHPMD   | `composer phpmd`           | < 50 violations → fix outright; ≥ 50 → capture baseline |
| PHPStan | `composer phpstan`         | < 50 errors → fix outright; ≥ 50 → capture baseline |

The inventory output determines whether Phases 3 and 4 are "fix-outright"
one-PR efforts or multi-PR baseline burn-downs.

---

### Phase 2 — PHPCS Burn-down

The `phpcs.xml` file contains exactly 3 `<exclude-pattern>` entries under
a `<!-- legacy debt -->` comment block. Each entry maps to one PHP file.

**Per-file process:**

1. Run `composer phpcs -- <file>` to list all current violations.
2. Add missing class/method/property docblocks (per PSR-5).
3. Audit all method calls in the file — replace any named-parameter style
   calls (`setFoo(foo: $bar)`) with positional calls (`setFoo($bar)`), per
   ADR-003 (entity setters use positional args only; `__call` passes `$args[0]`).
4. Fix remaining sniff violations (spacing, visibility, etc.).
5. Remove the corresponding `<exclude-pattern>` line from `phpcs.xml`.
6. Run `composer phpcs` globally — gate MUST stay green.

Once all 3 excludes are removed, delete the entire legacy-debt comment block
from `phpcs.xml`.

---

### Phase 3 — PHPMD Burn-down (contingent on Phase 1)

`phpmd.xml` is already configured but no baseline exists. The first run will
surface violations across Pipelinq's PHP classes.

**Expected violation categories (from audit):**

| Rule set               | Expected violations                             |
|------------------------|-------------------------------------------------|
| `CleanCode`            | ElseExpression — reshape `if/else` to early return |
| `Design`               | CyclomaticComplexity, NPathComplexity — extract methods |
| `Controversial`        | StaticAccess — replace with DI                  |
| `Naming`               | LongVariable, ShortVariable, UnusedFormalParameter |
| `Unused`               | MissingImport — add `use` statements            |

**If fix-outright** (< 50 violations): single PR resolves all violations,
no baseline file created.

**If baseline captured** (≥ 50 violations):
- `phpmd.baseline.xml` is generated via `composer phpmd -- --generate-baseline`
- Subsequent PRs burn down baseline line by line
- When `phpmd.baseline.xml` reaches 0 entries: delete it and drop
  `--baseline-file` from the `composer.json` phpmd script

---

### Phase 4 — PHPStan Burn-down (contingent on Phase 1)

`phpstan.neon` is already configured (level TBD from first run). No baseline
exists.

**Expected error categories:**

| Category                          | Fix approach                              |
|-----------------------------------|-------------------------------------------|
| Missing return-type declarations  | Add `: void` / `: string` / etc.          |
| Missing param-type declarations   | Add typed params                          |
| Mixed types                       | Specify union / generic types             |
| Possibly-null dereferences        | Add null-checks or use null-safe operator |

**If fix-outright** (< 50 errors): single PR, no baseline file created.

**If baseline captured** (≥ 50 errors):
- `phpstan-baseline.neon` is generated via `vendor/bin/phpstan --generate-baseline`
- Burn down line by line over subsequent PRs
- When baseline reaches 0 entries: delete it and remove `includes:` reference
  from `phpstan.neon`

---

### Phase 5 — CI Integration

Verify that `composer check:strict` in CI covers all three gates.

**CI gate definition (target state):**

```json
"check:strict": [
    "@phpcs",
    "@phpmd",
    "@phpstan"
]
```

CI MUST run `composer check:strict` on every pull request targeting
`main` or `development`. A weekly cron job MUST also run `check:strict`
against the `development` branch to catch any drift.

---

### Phase 6 — Documentation

- `README.md` quality-gates section: document the three tools, how to run
  them locally, and what `composer check:strict` covers.
- `app-config.json` (if applicable): note that legacy quality cleanup is done.
- Close the burn-down tracking issue once the last baseline line is removed.

---

## File Changes

| File | Action | Phase | Description |
|------|--------|-------|-------------|
| `phpcs.xml` | MODIFY | 2 | Remove 3 `<exclude-pattern>` entries + legacy-debt block |
| Legacy file 1 (TBD) | MODIFY | 2 | Docblocks + positional args + sniff fixes |
| Legacy file 2 (TBD) | MODIFY | 2 | Docblocks + positional args + sniff fixes |
| Legacy file 3 (TBD) | MODIFY | 2 | Docblocks + positional args + sniff fixes |
| `phpmd.xml` | MODIFY (maybe) | 3 | Baseline path config if baseline is captured |
| `phpmd.baseline.xml` | CREATE or SKIP | 3 | Generated baseline (if ≥ 50 violations) |
| `phpstan.neon` | MODIFY (maybe) | 4 | Add `includes: phpstan-baseline.neon` if captured |
| `phpstan-baseline.neon` | CREATE or SKIP | 4 | Generated baseline (if ≥ 50 errors) |
| `composer.json` | MODIFY (maybe) | 5 | Ensure `check:strict` includes all three gates |
| CI config file | MODIFY (maybe) | 5 | Wire `composer check:strict` on every PR |
| `README.md` | MODIFY | 6 | Update quality-gates documentation section |

---

## Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| First PHPMD/PHPStan run surfaces > 200 violations | Medium | Capture baseline immediately; burn down incrementally over multiple sprints |
| Positional-arg refactors break runtime behaviour | Low | Run existing test suite after each file fix; `composer check:strict` must stay green |
| CI not running `check:strict` today | Low | Verify in Phase 1 before any code changes; fix CI config as first commit |
| Named-parameter calls in excluded files break OR mapper | Medium | Follow ADR-003: entity setters MUST use positional args only (`$args[0]`) |

---

## ADR Compliance Notes

- **ADR-003 (backend):** Entity setter calls in the 3 legacy files MUST use
  positional arguments only. `setFoo(foo: $bar)` style is forbidden — the
  `__call` magic passes `$args[0]`, not named keys.
- **ADR-003 (`@spec` traceability):** Any PHP file modified in this change
  MUST have its file-level docblock updated with `@spec openspec/changes/pipelinq-legacy-quality-cleanup/tasks.md`.
- **ADR-008 (testing):** All modified files must continue to pass
  `composer check:strict`. No new test files are introduced by this change.
- **ADR-021 (bounded fix scope):** Fixes are scoped to what the quality
  sniffs require. No opportunistic refactoring of surrounding code.

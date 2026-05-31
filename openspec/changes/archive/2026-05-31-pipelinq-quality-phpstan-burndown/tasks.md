# Tasks: pipelinq PHPStan burn-down + CI integration + docs

ADR-032 cap respected (≤20 unchecked tasks).

## Phase 4 — PHPStan burn-down

Contingent on the PHPCS+inventory slice's first-run output. If volume is small, this
phase collapses to a single fix-outright PR.

- [x] Inventory phpstan errors by file/type
      — Baseline held 29 entries; live run surfaced 22 firing (7 stale).
        Firing set: 14 "never read, only written" forward-compat DI stubs +
        8 `AuthorizedAdminSetting` class-string false-positives (ocp stub).
- [x] Fix missing return-type / param-type declarations
      — None outstanding; level-5 run reports zero type-declaration errors.
- [x] Fix mixed types (specify generic / union)
      — None outstanding at level 5.
- [x] Fix possibly-null dereferences
      — None outstanding at level 5.
- [x] Once baseline reaches 0 lines (or never created): confirm
      gate runs clean against current code
      — Gate runs clean (`[OK] No errors`). Baseline is NOT empty: 22 entries
        are intentional, documented tracked debt (issue #496) — forward-compat
        DI stubs + one ocp stub false-positive. Resynced the baseline to drop
        7 stale entries (constants now wired up, CsrfTokenManager invalid-type,
        two logic warnings) that no longer fire; behavior unchanged.

## Phase 5 — CI integration

- [x] Verify `composer check:strict` runs in CI on every PR
      — Delegated to shared workflow `ConductionNL/.github/.github/workflows/quality.yml@main`
        via `.github/workflows/code-quality.yml` (`pull_request` trigger).
        phpcs/phpmd/psalm/phpstan all default-true there; pinned explicitly in
        the caller now so the strict posture is self-documenting.
- [x] Delete `phpstan-baseline.neon` if it was created and is now empty
      — Not applicable: baseline is non-empty intentional tracked debt (22
        entries, issue #496). Kept and resynced rather than deleted.
- [x] Confirm the legacy-debt section was dropped from `phpcs.xml` by the
      PHPCS slice (cross-check)
      — Confirmed: phpcs.xml has no legacy-debt/baseline suppression section;
        `composer phpcs` exits 0.
- [x] Add a smoke-test cron that runs `composer check:strict`
      weekly on `development`
      — Added `schedule: cron "0 6 * * 1"` to `code-quality.yml`. Scheduled
        runs use the default branch (development) workflow + ref, re-running the
        full strict gate suite weekly.

## Phase 6 — Documentation

- [x] Update README quality-gates section
      — Added psalm/phpstan/check:strict commands, the PR + weekly-cron CI note,
        and the "legacy cleanup complete" statement; Tech Stack quality row now
        lists Psalm + PHPStan.
- [x] Note in `app-config.json` that legacy quality cleanup is done
      — No `app-config.json` exists in pipelinq (audit-era artifact never
        created). Recorded the "legacy quality cleanup complete" note in the
        README Code-quality section instead (the canonical existing location).

# Tasks: pipelinq PHPStan burn-down + CI integration + docs

ADR-032 cap respected (≤20 unchecked tasks).

## Phase 4 — PHPStan burn-down

Contingent on the PHPCS+inventory slice's first-run output. If volume is small, this
phase collapses to a single fix-outright PR.

- [ ] Inventory phpstan errors by file/type
- [ ] Fix missing return-type / param-type declarations
- [ ] Fix mixed types (specify generic / union)
- [ ] Fix possibly-null dereferences
- [ ] Once baseline reaches 0 lines (or never created): confirm
      gate runs clean against current code

## Phase 5 — CI integration

- [ ] Verify `composer check:strict` runs in CI on every PR
- [ ] Delete `phpstan-baseline.neon` if it was created and is now empty
- [ ] Confirm the legacy-debt section was dropped from `phpcs.xml` by the
      PHPCS slice (cross-check)
- [ ] Add a smoke-test cron that runs `composer check:strict`
      weekly on `development`

## Phase 6 — Documentation

- [ ] Update README quality-gates section
- [ ] Note in `app-config.json` that legacy quality cleanup is done

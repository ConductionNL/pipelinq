---
kind: code
---

## Why

Pipelinq's PHPUnit unit suite is isolated from the real OpenRegister app: it mocks a
deliberately-simplified OpenRegister surface supplied by stub classes under `tests/Stubs/`
(e.g. `ObjectService::find()` returning an `array`, `ObjectEntity::getSchema()` / `getUuid()`
as real **declared** methods, `saveObject()` returning an `array`). These stubs are mapped via
the `autoload-dev` PSR-4 prefix `OCA\OpenRegister\ => tests/Stubs/`. The deep **integration**
tier (`tests/e2e/workflows` + Newman, run by `.forgejo/workflows/tests-live.yml` against a
live, OpenRegister-loaded Nextcloud) is where the suite exercises the **real** OpenRegister.

The default `phpunit -c phpunit.xml` run (bare host, no Nextcloud) passes: **1561 tests, 13
skipped, 0 errors**. But when the same `phpunit.xml` suite is run **inside a Nextcloud that has
OpenRegister enabled** (e.g. the bind-mounted dev container, or the GitHub mirror's
`code-quality.yml` PHPUnit job which adds `openregister` as an `additional-app`), it breaks with
**~65 errors / 11 failures plus a hard fatal**. Two distinct root causes:

1. **Declaration-compatibility fatal.** `tests/Unit/Service/QueryPushdownBatch3Test.php`
   declares an anonymous class `extends \OCA\OpenRegister\Service\ObjectService` whose
   `findAll(array $config = [])` override drifted from the real OR signature, which is now
   `findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true)`. When the real
   OR class wins autoloading, PHP fatals at class-declaration time
   (`Declaration … must be compatible with …`), aborting the entire run.

2. **Stub-vs-real API divergence.** Once the fatal is past, the real OR classes resolve first
   (NC's app autoloader registers `OCA\OpenRegister\*` before pipelinq's `autoload-dev` stub
   mapping). The real classes' stricter API — `find(): ?ObjectEntity`, `saveObject():
   ObjectEntity`, and `ObjectEntity` with **no declared** `getSchema()` (it is a magic
   `Entity::__call` getter) — is incompatible with the unit suite's stub-shaped mocks, yielding
   `CannotUseOnlyMethodsException: … getSchema`, `IncompatibleReturnValueException` on
   `find`/`saveObject`, and the handful of downstream assertion failures they cascade into.
   These are **harness artifacts, not real regressions** — every one of the 65 errors / 11
   failures is a stub-vs-real shape mismatch in a test that is, by design, a unit test against
   the stub.

No gating CI actually depends on the failing mode: pipelinq's canonical CI is Codeberg/Forgejo,
where `pre-merge-check-strict` runs lint + phpcs + Hydra gates (no PHPUnit) and `tests-live.yml`
runs Playwright e2e + Newman (no PHPUnit). The PHPUnit suite is run bare (mode A) only. Still,
the divergence is a real harness defect that any maintainer running the suite in an OR-loaded
container (or the GitHub mirror) hits, so we fix it rather than leave it latent.

## What Changes

- **Restore the harness invariant** that the unit suite resolves the OpenRegister **stub**
  classes deterministically, whether or not a real OpenRegister app is loaded in-process. The
  test bootstrap (`tests/bootstrap.php`) now eagerly pre-declares the two stub classes that the
  divergence hinges on — `OCA\OpenRegister\Db\ObjectEntity` and
  `OCA\OpenRegister\Service\ObjectService` — **before** the Nextcloud bootstrap registers the
  real OpenRegister namespace. Once a stub class is declared, PHP will not load the real
  same-named class, so the unit suite sees the stub surface in both run modes. Non-stubbed OR
  classes (mappers, aggregation, etc.) still fall through to the real app, so the real OR is not
  poisoned. Each pre-load is fault-tolerant (a stub whose dependency is unavailable is skipped,
  never fatal).
- **Fix the drifted override signature** in `QueryPushdownBatch3Test.php` so the anonymous
  `ObjectService` subclass's `findAll()` is declaration-compatible with the real OR signature
  (`array $config = [], bool $_rbac = true, bool $_multitenancy = true`) — variance-safe against
  both the stub and the real class, so it can never re-trigger the compatibility fatal.

No production (`lib/`) code changes; no test coverage is weakened or deleted — the same 1561
assertions run, now consistently green in both modes.

## Impact

- Affected specs: `test-harness` (ADDED capability documenting the two-mode stub-precedence
  invariant).
- Affected code: `tests/bootstrap.php`, `tests/Unit/Service/QueryPushdownBatch3Test.php`
  (test-only).
- Before: mode A (bare host) `1561 / 0 errors`; mode B (OR-loaded container) **fatal +
  65 errors / 11 failures**. After: **both modes `1561 tests, 4441 assertions, 13 skipped,
  0 errors, 0 failures`**.

# Tasks

## 1. Fix the drifted override signature (declaration-compatibility fatal)

- [x] 1.1 In `tests/Unit/Service/QueryPushdownBatch3Test.php`, change the anonymous
  `ObjectService` subclass's `findAll(array $config = [])` to
  `findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true)` so it is
  declaration-compatible with the real OR `ObjectService::findAll()` AND the stub (adding
  trailing optional params is a valid covariant override against both). Document why in a
  PHPDoc note.

## 2. Make the stub resolution deterministic in both run modes

- [x] 2.1 In `tests/bootstrap.php`, eagerly `require_once` the `Db/ObjectEntity.php` and
  `Service/ObjectService.php` stub files **before** the Nextcloud bootstrap block, so the stub
  classes are declared before NC registers the real OpenRegister namespace. Wrap each require in
  a `try/catch (\Throwable)` so a stub whose dependency is missing is skipped, never fatal.
- [x] 2.2 Keep the existing lower `class_exists`/`interface_exists`-guarded stub requires as
  defensive no-ops (already satisfied by the eager preload in both modes). Document the
  invariant in a bootstrap comment.
- [x] 2.3 Verify only `ObjectEntity` + `ObjectService` are pre-declared (not `Db/Schema`,
  mappers, etc.) so the real OR internal classes that reference those types are not poisoned.
- [x] 2.4 Make the `Db/ObjectEntity` stub a CONCRETE class (not abstract) with trivial method
  bodies, so a real OpenRegister class that `extends ObjectEntity` and is eagerly loaded by NC
  (e.g. `Service\Notification\SystemEntityObjectAdapter`) does not become "contains abstract
  methods and must be declared abstract" and fatal. Keep `getSchema`/`getUuid` as declared
  methods for `onlyMethods()` mocking. Verified stable across repeated container runs (no
  class-load-ordering flakiness).

## 3. Verify both run modes are consistent

- [x] 3.1 Mode A (bare host): `phpunit -c phpunit.xml` → 1561 tests, 0 errors, 0 failures
  (unchanged baseline).
- [x] 3.2 Mode B (OR-loaded container): `phpunit -c phpunit.xml` run inside the
  OpenRegister-enabled Nextcloud → same 1561 tests, 0 errors, 0 failures (was: fatal +
  65 errors / 11 failures).
- [x] 3.3 Confirm the stub classes win autoloading in mode B (reflection: `ObjectService` and
  `ObjectEntity` resolve to `tests/Stubs/...`, `getSchema` is a declared method).

## 4. Quality

- [x] 4.1 `php -l` clean on both changed files.
- [x] 4.2 `composer phpcs` (gated, `lib/` only) unchanged — 0 errors (test files are outside the
  gated scope; `phpcs.xml` scopes `<file>lib</file>`).
- [x] 4.3 No `lib/` changes; default-mode passing count not regressed (still 1561).

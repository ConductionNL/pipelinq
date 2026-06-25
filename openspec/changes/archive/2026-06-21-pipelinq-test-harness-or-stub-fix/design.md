# Design — Pipelinq PHPUnit harness OpenRegister-stub fix

## Root cause

Pipelinq owns no database tables; it consumes OpenRegister's `ObjectService` /
`ObjectEntity`. The unit suite mocks a **deliberately-simplified** OpenRegister surface via
stub classes under `tests/Stubs/`, mapped by the `autoload-dev` PSR-4 prefix
`OCA\OpenRegister\ => tests/Stubs/`. Two things make the suite pass on a bare host but break
inside an OpenRegister-loaded Nextcloud:

### Mode A — bare host (default `phpunit -c phpunit.xml`)

`tests/bootstrap.php` tries to `require ../../../lib/base.php`; on a bare host that throws and
is caught, so the real OpenRegister namespace is never registered. The `autoload-dev` PSR-4
mapping resolves `OCA\OpenRegister\*` to `tests/Stubs/`, so the **stub** classes win. The suite
asserts against the stub surface (`find(): array|object|null`, `saveObject(): array`,
`ObjectEntity::getSchema()` declared) and passes: **1561 tests, 13 skipped, 0 errors**.

### Mode B — OpenRegister-loaded Nextcloud (same `phpunit.xml`, run in-container)

`lib/base.php` loads, and `OC_App::loadApps()` / `loadApp('pipelinq')` cause Nextcloud's app
autoloader to register `OCA\OpenRegister\*` → the real OpenRegister `lib/`. That autoloader
resolves first, so the **real** OR classes win. Their stricter API diverges from the stubs:

| Member | Stub (unit surface) | Real OpenRegister |
| --- | --- | --- |
| `ObjectService::find()` | `: array\|object\|null` | `: ?ObjectEntity` |
| `ObjectService::findAll()` | `(array $config = [])` | `(array $config = [], bool $_rbac = true, bool $_multitenancy = true)` |
| `ObjectService::saveObject()` | `: array` | `: ObjectEntity` |
| `ObjectEntity::getSchema()` | declared `abstract` method | magic `Entity::__call` getter (NOT declared) |

This produces:

1. A hard **fatal** at class-declaration time: `QueryPushdownBatch3Test.php` declares an
   anonymous class `extends \OCA\OpenRegister\Service\ObjectService` with the **old** 2-param
   `findAll()`; against the real 3-param signature PHP raises
   `Declaration of … findAll(array $config = []) must be compatible with …`, aborting the run
   (exit 255) before any "65 errors" can even be reported.
2. Once the fatal is removed, the run **completes** but reports **65 errors / 11 failures**, all
   stub-vs-real shape mismatches: `CannotUseOnlyMethodsException` (`onlyMethods(['getSchema'])`
   on a class with no declared `getSchema`), `IncompatibleReturnValueException` /
   `TypeError` (mocking `find`/`saveObject` to return an `array` against a `?ObjectEntity` /
   `ObjectEntity` return type), plus a handful of assertion failures those cascade into.

## Why "fix" and not "document as non-issue"

No gating CI runs mode B (Codeberg/Forgejo `pre-merge-check-strict` = lint + phpcs + Hydra
gates; `tests-live.yml` = Playwright e2e + Newman — neither runs PHPUnit; the PHPUnit suite is
run bare). But the GitHub mirror's `code-quality.yml` configures its PHPUnit job with
`additional-apps: [openregister@development]` — i.e. that job IS mode B and would fail if/when
Actions run there. Independently, any maintainer running the suite in the bind-mounted dev
container hits it. So the divergence is a real, reproducible harness defect; per the project's
"always fix pre-existing problems" rule, we fix it.

## Fix

Two surgical, test-only changes — no `lib/` change, no test deleted or weakened.

### 1. Declaration-compatibility (the fatal)

`QueryPushdownBatch3Test`'s anonymous `ObjectService` subclass override becomes
`findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array`. Adding two
trailing **optional** params is a valid covariant override against **both** the real OR class
and the stub, so it is correct whichever wins — the fatal can never recur. The flags are ignored
by the fake.

### 2. Deterministic stub precedence (the 65/11 divergence)

`tests/bootstrap.php` eagerly `require_once`s exactly the two stub files the divergence hinges
on — `tests/Stubs/Db/ObjectEntity.php` and `tests/Stubs/Service/ObjectService.php` — **before**
the Nextcloud bootstrap registers the real OpenRegister namespace. PHP will not load a real
class whose name is already declared, so the unit suite sees the stub surface in **both** modes.

Scope is the minimum that works: only these two classes are pre-declared. Pre-declaring more
(e.g. `Db/Schema`) **poisons** real OR internal classes that NC eagerly loads and that reference
those types — empirically, pre-declaring the non-`Entity` `Schema` stub made the real
`SchemaMapper::delete(): Schema` incompatible with `QBMapper::delete(): Entity` and re-introduced
a fatal. `ObjectEntity` and `ObjectService` are safe to pre-declare because the unit tests never
trigger the real OR mappers/services that would consume them, so nothing real-OR references the
stubbed types in-process. Each `require_once` is wrapped in `try/catch (\Throwable)` so it is
fault-tolerant.

One further poisoning vector had to be closed: the `ObjectEntity` stub was originally an
**abstract** class with abstract method declarations. When a real OpenRegister class that
`extends ObjectEntity` (e.g. `Service\Notification\SystemEntityObjectAdapter`, registered as a
listener at app boot) is eagerly loaded by NC against the abstract stub, PHP fatals with
"contains abstract methods and must be declared abstract" — an **ordering-dependent** failure
(it only fired when that adapter happened to load). The fix is to make the stub a **concrete**
class with trivial method bodies (`getObject(): []`, `getUuid(): ''`, `getSchema(): null`,
`jsonSerialize(): []`); the methods stay declared so `onlyMethods(['getSchema', …])` mocking
still works, and PHPUnit's `createMock()` overrides the bodies anyway. With the concrete stub the
container run is stable across repeated executions.

## Two-mode behaviour after the fix

| Run mode | Command | Result |
| --- | --- | --- |
| A — bare host | `phpunit -c phpunit.xml` | 1561 tests, 4441 assertions, 13 skipped, **0 errors, 0 failures** |
| B — OR-loaded container | same, run as `www-data` in the NC container | 1561 tests, 4441 assertions, 13 skipped, **0 errors, 0 failures** |

Both modes now resolve the OpenRegister **stub** classes and produce identical results. The deep
integration tier (`tests/e2e/workflows` + Newman) remains the place that exercises the real
OpenRegister against a live instance — that separation is unchanged.

## Risks / alternatives considered

- **Align the stubs to the real OR API instead** (drop the declared `getSchema`/`getUuid`, make
  `find`/`saveObject` return `?ObjectEntity`/`ObjectEntity`): rejected — ~50 tests rely on the
  simplified stub surface (`onlyMethods(['getSchema'])`, mocking `find` → array); reconciling
  them would rewrite real assertions and make them no-longer-unit tests. High-risk, no benefit.
- **Prepend a narrow SPL autoloader for `OCA\OpenRegister\*`**: rejected — the stub files
  self-guard with `if (class_exists(X) === false)`, which re-enters the autoload stack and lets
  the real class win mid-resolution; eager pre-declaration before NC registers OR is the
  reliable mechanism.
- **Pre-declare the full stub set**: rejected — poisons real OR internal classes (see Fix §2).

# test-harness Specification

## Purpose
TBD - created by archiving change pipelinq-test-harness-or-stub-fix. Update Purpose after archive.
## Requirements
### Requirement: Deterministic OpenRegister-stub precedence in the unit suite

@e2e exclude both scenarios are about THE PHPUNIT SUITE'S OWN CLASS-LOADING —
which file `OCA\OpenRegister\Service\ObjectService` resolves to inside a
`phpunit` process, on a bare host versus inside a Nextcloud with the real
OpenRegister enabled. That is a property of a test run, observed from inside
that test run; a browser is not present in either mode and has no way to see
which autoloader won. The stubs the scenarios name were confirmed to exist
(`tests/Stubs/Db/ObjectEntity.php`, `tests/Stubs/Service/`), and BOTH run modes
are genuinely exercised in CI: the `PHPUnit` matrix legs run inside a Nextcloud
with OpenRegister installed via `additional-apps`, which is the second scenario,
and the suite's own bootstrap is what the first one asserts. The honest
enforcement here is the suite passing in both modes — which is what the matrix
already measures — not a Playwright test.

The pipelinq PHPUnit unit suite SHALL resolve its OpenRegister **stub** classes
(`OCA\OpenRegister\Db\ObjectEntity`, `OCA\OpenRegister\Service\ObjectService`) deterministically,
producing the same result whether or not a real OpenRegister app is autoloadable in-process. The
test bootstrap SHALL pre-declare these stub classes before the Nextcloud bootstrap registers the
real OpenRegister namespace, so the unit suite sees the simplified stub surface in both run
modes. Pre-declaration SHALL be limited to stub classes that the suite mocks AND that no real
OpenRegister class loaded in-process references as a parameter/return type, so the real
OpenRegister app is not poisoned; each pre-load SHALL be fault-tolerant (a stub whose dependency
is unavailable is skipped, never fatal).

#### Scenario: Bare-host run resolves the stubs and passes

- **GIVEN** the suite is run with `phpunit -c phpunit.xml` on a host with no installed Nextcloud
- **WHEN** the suite runs
- **THEN** `OCA\OpenRegister\Service\ObjectService` and `OCA\OpenRegister\Db\ObjectEntity` SHALL
  resolve to the stub files under `tests/Stubs/`
- **AND** the suite SHALL report 0 errors and 0 failures (1561 tests, 13 skipped)

#### Scenario: OpenRegister-loaded run resolves the same stubs and passes identically

- **GIVEN** the suite is run with the same `phpunit.xml` inside a Nextcloud that has the real
  OpenRegister app enabled and autoloadable
- **WHEN** the suite runs
- **THEN** `OCA\OpenRegister\Service\ObjectService` and `OCA\OpenRegister\Db\ObjectEntity` SHALL
  still resolve to the stub files (the pre-declaration wins over Nextcloud's app autoloader)
- **AND** the suite SHALL report the same 0 errors and 0 failures as the bare-host run
- **AND** the real OpenRegister classes NOT stubbed by the suite SHALL remain loadable

### Requirement: OpenRegister stub override signatures stay declaration-compatible

@e2e exclude this scenario asserts a PHP DECLARATION-COMPATIBILITY property of a
test double — that an anonymous class extending `ObjectService` declares
`findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array`.
It is enforced by the language itself: an incompatible override is a fatal
error at class-declaration time, so the suite cannot load, let alone pass. A
browser test cannot observe a signature, and there is no running instance
involved — the failure this guards against happens before any request exists.
The `PHPUnit` matrix legs run with the real OpenRegister autoloadable, which is
exactly the configuration in which an incompatible override would fatal, so the
guard is live in CI.

Any test double that `extends` a real OpenRegister class (rather than using a PHPUnit mock) SHALL
declare method overrides whose signatures are compatible with BOTH the OpenRegister stub and the
current real OpenRegister class, so the suite never fatals with a "Declaration must be
compatible" error in an OpenRegister-loaded run.

#### Scenario: ObjectService subclass findAll override is compatible with the real signature

- **GIVEN** a test fake declared as `new class … extends \OCA\OpenRegister\Service\ObjectService`
- **WHEN** it overrides `findAll()`
- **THEN** the override SHALL declare
  `findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array`, matching
  the real OpenRegister `ObjectService::findAll()` while remaining a valid covariant override of
  the stub
- **AND** the suite SHALL load without a class-declaration fatal whether the stub or the real
  class wins autoloading


# pipelinq quality-gate inventory (baseline)

Captured 2026-05-31 against `origin/development` (branch
`opsx/pipelinq-quality-phpcs-burndown`). Tooling run from the symlinked
`vendor/bin/*` of the main checkout. This inventory feeds the sibling PHPMD
and PHPStan burn-down slices.

## PHPCS

Command: `./vendor/bin/phpcs --standard=phpcs.xml`

- **Errors: 0**
- **Warnings: 174** — all `CustomSniffs.Commenting.SpecTag`:
  - 94 × "Spec tag missing method spec"
  - 80 × "Spec tag missing class spec"
- Warnings are emitted as `<type>warning</type>` per ADR-003 (hydra SpecTag
  sniff) and `ignore_warnings_on_exit=1` is set, so they surface in CI logs
  without failing the build.
- **No legacy-debt block / no per-file `<exclude-pattern>` for `lib/`.** The
  only excludes are infrastructure (`vendor`, `vendor-bin`, `node_modules`,
  `composer-setup.php`, `lib/Resources/template/*`). The 3 per-file excludes
  the parent proposal anticipated were already removed by commits `de4fd36`
  and `b091e1e`.
- **Gate verdict: GREEN. Decision = fix-outright (already at 0 errors).**

## PHPMD

Command: `./vendor/bin/phpmd lib text phpmd.xml --baseline-file phpmd.baseline.xml`

- Raw exit 0 (all violations currently covered by `phpmd.baseline.xml`).
- **36 violations** reported above the baseline listing, categories:
  - CyclomaticComplexity
  - NPathComplexity
  - ExcessiveClassComplexity
  - CouplingBetweenObjects
  - UnusedPrivateMethod (e.g. `neutralizeCsvCell` in IntakeFormService /
    ReportingService)
  - ShortVariable
  - ExcessiveMethodLength
- Hotspots: `lib/Service/RoutingService.php`,
  `lib/Service/ScheduledTaskService.php`.
- **Gate verdict: baselined. Out of scope for this PHPCS slice — handed to the
  PHPMD burn-down slice.**

## PHPStan

Command: `./vendor/bin/phpstan analyse --memory-limit=1G`

- **0 errors** (`[OK] No errors`). Level per `phpstan.neon`.
- (Informational: PHPStan 2.x upgrade is available; PHPStan 1.x in use.)
- **Gate verdict: GREEN.**

## CI confirmation

`.github/workflows/code-quality.yml` delegates to the shared reusable workflow
`ConductionNL/.github/.github/workflows/quality.yml@main` (the canonical
lint + phpcs + phpmd + psalm + phpstan + phpunit runner) on `pull_request`
to `main`/`beta`/`development`. Quality gates therefore run on every PR.

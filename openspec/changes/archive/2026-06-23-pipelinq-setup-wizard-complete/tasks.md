# Tasks — pipelinq setup wizard, completed

## Phase 1: Manifest

- [x] Extend `src/manifest.json` `setup.steps` from 3 to 6: add `provision` (run-action), `organisation` (config-fields), `integrations` (config-fields); refresh `welcome`/`done` copy. Keep `currency` required.
- [x] Validate the manifest against the v2 schema (`node tests/validate-manifest.js`) and `scripts/check-manifest.js`.

## Phase 2: Backend

- [x] `SetupController::status()` reports done state for `currency`, `provision`, `organisation`, `integrations`; only `currency` flips `setup_completed_version`.
- [x] `SetupController::runAction('provision-register')` → `provisionRegister()` delegates to `SettingsService::loadSettings(false)` + `createDefaultPipelines/Queues/Skills()`; idempotent; guards on OpenRegister installed.
- [x] Inject `SettingsService`, `IAppManager`, `LoggerInterface`; reuse existing routes (no new routes).

## Phase 3: Verify

- [x] Build green (webpack), vitest at baseline (only pre-existing `recurringRevenue` orphan fails), `phpcs --warning-severity=0` clean on `SetupController.php`.
- [x] Live (:8080): reset setup config → wizard re-shows all 6 steps → currency persists (USD), provision action returns success, organisation + integrations persist, summary recaps values, Finish flips the gate and the dashboard renders. 0 new console errors.

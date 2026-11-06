# Tasks — pipelinq first-time setup

> Read on development 2e99ad425 (12 Oct): the central change shipped and later changes built this one (`pipelinq-setup-wizard-review`, `setup-wizard-close-on-server`, both archived). Each task below names the file that holds it. The change stays open for the live pass only.

## Phase 1: Manifest + config

- [x] Add a `setup` block to `src/manifest.json`: `welcome`, `currency` (required), `register-mapping` (optional), `done`; `completionConfigKey: setup_completed_version`.
  - Built: `src/manifest.json` `setup` (steps `welcome`, `demo-data`, `currency`, `organisation`, `done`). The optional `register-mapping` step is gone on purpose: provisioning is an admin settings action since `pipelinq-setup-wizard-review`. Test: `SetupControllerCurrencyTest::testTheCurrencyIsTheOnlyRequiredStepOfTheManifest`.
- [x] Add a `currency` app-config key (ISO-4217, default `EUR`) to `SettingsService`; provide it via initial-state so dashboard widgets/reports read it.
  - Built: `SettingsService` lists `currency`; `Application` provides the `config` initial state; `src/utils/pageAppConfig.js` seeds it into dashboard and detail pages. Tests: `tests/vitest/pageAppConfig.spec.js`, `tests/vitest/reportingCurrency.spec.js`.

## Phase 2: Wire currency into rendering + status

- [x] Replace hard-coded `currency: "EUR"` in the commercial dashboard `stat`/`chart` widgets with the configured `currency` value.
  - Built: the commercial widgets read `@config.currency` (revenue, won value, average deal size, weighted forecast, MRR, pipeline coverage, pipeline value, and the contact and lead tiles). Five till tiles keep `EUR` (refund amount, refund VAT, opening float, day total, day VAT): the till books in euro and is no commercial dashboard widget.
- [x] Add `lib/Controller/SetupController.php` — `status()` (GET) + `runAction()` (POST), admin-only, CSRF. `status()` reports `currency.done`. (No required server-side seed.)
  - Built: `lib/Controller/SetupController.php`. Tests: `SetupControllerCurrencyTest` (the wizard waits for the currency; choosing one completes it and stores `setup_completed_version`), `SetupControllerStatusContractTest`, `SetupControllerDemoDataTest`, `SetupControllerDismissTest`.
- [x] Register routes in `appinfo/routes.php` with auth attributes.
  - Built: `setup#status`, `setup#saveConfig`, `setup#runAction`, each `#[AuthorizedAdminSetting(AdminSettings::class)]`.

## Phase 3: Verify

- [ ] (live pass, decision 139) Live: fresh enable → CnAppRoot gates on `currency`; pick a currency → app usable + dashboard formats amounts in that currency; both modals open from the admin page.

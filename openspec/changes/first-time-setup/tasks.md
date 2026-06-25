# Tasks — pipelinq first-time setup

> Blocked on the central change (`nextcloud-vue` `cn-setup-wizard` + manifest `setup` schema). Phase A here is the SPEC only; implementation runs in Phase D.

## Phase 1: Manifest + config

- [ ] Add a `setup` block to `src/manifest.json`: `welcome`, `currency` (required), `register-mapping` (optional), `done`; `completionConfigKey: setup_completed_version`.
- [ ] Add a `currency` app-config key (ISO-4217, default `EUR`) to `SettingsService`; provide it via initial-state so dashboard widgets/reports read it.

## Phase 2: Wire currency into rendering + status

- [ ] Replace hard-coded `currency: "EUR"` in the commercial dashboard `stat`/`chart` widgets with the configured `currency` value.
- [ ] Add `lib/Controller/SetupController.php` — `status()` (GET) + `runAction()` (POST), admin-only, CSRF. `status()` reports `currency.done`. (No required server-side seed.)
- [ ] Register routes in `appinfo/routes.php` with auth attributes.

## Phase 3: Verify

- [ ] Live: fresh enable → CnAppRoot gates on `currency`; pick a currency → app usable + dashboard formats amounts in that currency; both modals open from the admin page.

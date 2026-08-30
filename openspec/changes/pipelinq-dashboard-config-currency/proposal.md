---
kind: code
---

## Why

The setup wizard captures a reporting **currency** (`EUR` / `USD` / `GBP` / `CHF`)
and persists it as the pipelinq `currency` app-config key (`SetupController` /
`SettingsService`). But every currency-formatted dashboard KPI hard-codes
`format: { style: "currency", currency: "EUR" }` in the manifest, so the wizard's
choice has no effect on how revenue, forecast, pipeline and recurring-revenue
figures render — a USD-configured instance still shows `€`.

`@conduction/nextcloud-vue` already ships the `@config.<key>` token (resolved by
`CnStatWidget` against the `cnAppConfig` inject that `CnDashboardPage` provides
from its `appConfig` prop), and the manifest renderer is expected to seed that
prop from `loadState(appId, 'config', {})`. pipelinq never wired the seed, so the
token had no source. This change feeds the configured currency to the SPA and
switches the dashboard's currency KPIs from the literal `EUR` to
`@config.currency` (default `EUR` when unset).

## What Changes

- **Backend**: `Application::boot()` provides a `config` initial state carrying the
  reporting `currency` (read from `IAppConfig`, default `EUR`), serialized as
  `initial-state-pipelinq-config`.
- **Frontend wiring**: `main.js` reads `loadState('pipelinq', 'config', {})` and
  seeds it onto every `type: "dashboard"` page's `config.appConfig`. `CnPageRenderer`
  forwards `config.*` to the dispatched page's props, so it lands on
  `CnDashboardPage.appConfig` — the source the `@config.<key>` resolver reads.
- **Manifest**: the seven currency-formatted dashboard KPIs switch
  `format.currency` from `"EUR"` to `"@config.currency"` (Commercial overview
  Revenue / Won Value / Weighted Forecast / Recurring revenue, Operational
  Pipeline Value, the Commercial gauge Pipeline coverage, and the Klantbeeld-360
  Open Pipeline Value). Non-currency formats (number / percent) and `type: "index"`
  table-column currency formats (which do not run through the dashboard `@config`
  resolver) are left untouched.

No nextcloud-vue change: the `@config` token and `appConfig` prop already ship.

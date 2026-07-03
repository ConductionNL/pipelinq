## 1. Backend — provide the reporting currency to the SPA

- [x] 1.1 In `lib/AppInfo/Application.php::boot()`, after `dependency_statuses`, provide a `config` initial state: `{ currency: IAppConfig::getValueString('pipelinq', 'currency', 'EUR') }` (default `EUR` when the wizard has not run). Namespaced as `initial-state-pipelinq-config`.
- [x] 1.2 Reuse the already-imported `OCP\IAppConfig` and the `currency` key the setup wizard persists (`SetupController` / `SettingsService`).

## 2. Frontend — seed CnDashboardPage.appConfig

- [x] 2.1 In `src/main.js`, import `loadState` from `@nextcloud/initial-state`.
- [x] 2.2 Add `seedDashboardAppConfig(manifest)`: read `loadState('pipelinq', 'config', {})` and set `page.config = { appConfig, ...page.config }` on every `type: "dashboard"` page (explicit per-page `config.appConfig` wins). Apply it to the merged manifest before building routes.
- [x] 2.3 Confirm `CnPageRenderer` forwards `config.appConfig` to `CnDashboardPage.appConfig`, which provides `cnAppConfig` to descendant `CnStatWidget`s (no nc-vue change).

## 3. Manifest — switch currency KPIs to `@config.currency`

- [x] 3.1 In `src/manifest.json`, change `format.currency` from `"EUR"` to `"@config.currency"` on the six currency KPIs: `revenue`, `won-value`, `weighted-forecast`, `mrr`, `pipeline-coverage` (gauge), `pipeline-value`.
- [x] 3.2 In `src/manifest.d/60-klantbeeld-360.json`, switch the `open-pipeline-value` KPI the same way; update its `_note` to reflect the reporting currency.
- [x] 3.3 Leave non-currency formats (number/percent) and `type:"index"` table-column currency formats (`budgetAmount`, `price`) untouched — they do not run through the dashboard `@config` resolver.

## 4. Verify

- [x] 4.1 `rm -rf node_modules/.cache && npm run build` is green.
- [x] 4.2 vitest ≥ baseline (recurringRevenue orphan ignored); lint clean; phpcs clean (PHP touched).
- [x] 4.3 Live (`:8080`): set `currency=USD`, reload Commercial dashboard → KPIs format as `$`/USD; set `EUR` → `€`. 0 new console errors. Screenshots of both states. Restore to `EUR`.

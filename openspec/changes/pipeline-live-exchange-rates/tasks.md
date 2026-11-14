# Tasks: pipeline-live-exchange-rates

- [x] 1.1 `ExchangeRateService::resolve()` with the order of D1 and cross rates through EUR
  - Verify: PHPUnit `tests/Unit/Service/ExchangeRateServiceTest.php`: override wins, shillinq ecb beats shillinq manual, ECB source used without shillinq, table used without both, USD to GBP via EUR
- [x] 1.2 shillinq `FxRate` read through a lazy resolve; no hard dependency in `appinfo/info.xml`
  - Verify: PHPUnit with the container returning a reader and returning nothing
- [ ] 1.3 ECB rates through OpenRegister from the integriq source `ecb-eurofxref-daily`, skipped cleanly when the source is absent
  - Verify: PHPUnit with a 200 body, a 404 (no source) and a 503
  - Open: needs an `ecb-eurofxref-daily` source in integriq and a way to read it through OpenRegister; neither exists yet, so the ECB step is not in the resolution order
- [x] 1.4 `convert()` returning the metadata; `ForecastRollupService` uses it
  - Verify: PHPUnit on the roll-up asserting rate, source and date per currency
- [x] 1.5 Stale flag with `forecast_max_rate_age_days`
  - Verify: PHPUnit at the boundary day
- [ ] 1.6 Forecast view and LeadDetail show the rate line and stale flag
  - Verify: Playwright `tests/e2e/deal-exchange-rates.spec.ts` with a seeded shillinq-less instance and a manual rate shows source manual and its date
  - Built: LeadDetail `lead-exchange-rate` widget (`LeadExchangeRate.vue`) and the forecast rate lines, covered by Vitest `tests/vitest/dealExchangeRates.spec.js`. Open: the Playwright run on a live instance
- [x] 1.7 `ForecastSettings.vue`: source and date per currency, override with reason
  - Verify: Vitest on the settings component; `npm run test:l10n` exit 0

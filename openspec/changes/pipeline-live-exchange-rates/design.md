# Design: pipeline-live-exchange-rates

## Context (read at pipelinq development cfe0a0a51, shillinq development)

- **pipelinq.** `lib/Service/ExchangeRateService.php` keeps
  `RATES_KEY = 'forecast_exchange_rates'` in app config, a map of currency to
  "units of reporting currency per 1 unit"; `getReportingCurrency()` (:92) reads
  app config `currency`; `toReportingCurrency()` (:113) multiplies by
  `rateFor()`. Its only caller is `lib/Service/ForecastRollupService.php:76`.
  `src/components/admin/ForecastSettings.vue` edits the table (pipelinq#2040).
- **shillinq.** `FxRate` (`lib/Settings/register.d/add-shillinq-multi-currency-t4.json:11`)
  holds `transactionCurrency`, `baseCurrency`, `date`, `source` (ecb, manual,
  bank-feed), `rate`, `inverseRate`. Its design for `banking-fx-at-booking`
  (`FxAtBookingResolver`, D1) prefers ecb over bank-feed over manual and refuses
  rates older than `fx_max_rate_age_days`, and states "Fetching rates
  (integriq)" as a non-goal. `lib/BackgroundJob/FxRateImportJob.php:128` is
  dormant today.
- **integriq.** No ECB source exists: its seeded connectors
  (`lib/Settings/register.d/*-connector.json`) cover TenderNed, the Nextcloud app
  store and the DPG registry. shillinq's workflow points at
  `openconnector://ecb-eurofxref-daily`, which nothing seeds.

## Decisions

### D1. Resolution order, newest usable rate wins

`ExchangeRateService::resolve(currency)` returns `{rate, source, date}` from the
first of:
1. an administrator override in the pipelinq table marked as override;
2. shillinq `FxRate` for the pair (currency to reporting currency), newest date,
   preferring `ecb`, then `bank-feed`, then `manual`, when shillinq is installed
   (lazy container resolve, as the shillinq readers in pipelinq already do);
3. the ECB daily reference rate read through OpenRegister from integriq's
   `ecb-eurofxref-daily` source, when that source exists;
4. the pipelinq table as today.
ECB rates are quoted per euro; a non-euro reporting currency is converted by
cross rate (currency to EUR to reporting currency), computed in one place.

### D2. The conversion carries its metadata

`toReportingCurrency()` keeps its signature for existing callers, and a new
`convert(amount, currency)` returns `{amount, rate, source, rateDate}`.
`ForecastRollupService` uses `convert()` and passes the metadata to the forecast
view, which shows the rate line under a converted total.

### D3. Stale rates are flagged, not refused

A rate older than app config `forecast_max_rate_age_days` (default 7) is still
used for the forecast, which is an estimate, but LeadDetail and the forecast show
it as stale with its date. Refusing, as shillinq does for bookings, would hide a
pipeline total over a missing rate.

### D4. Settings show where each rate comes from

`ForecastSettings.vue` lists each currency with its current source and date and
lets an administrator set an override with a reason; the plain table becomes the
fallback column.

## Risks

- Without shillinq and without an integriq ECB source, nothing changes for the
  user except the source labels. That is honest: the settings page then says
  rates are typed by hand.
- Cross rates add rounding. The rate is kept at six decimals and only the
  converted amount is rounded to cents, as `toReportingCurrency()` does today.

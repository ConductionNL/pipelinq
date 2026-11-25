---
kind: code
depends_on: []
---

# Proposal: pipeline-live-exchange-rates

## Summary

A deal in dollars or pounds is converted at a rate that arrives by itself, and
you can see which rate was used, from where and of which day. Today an
administrator types every rate into Forecast settings, so "the current rate" is
only as current as the last time someone typed it.

## Motivation

One row of the pipelinq capability matrix (`openspec/parity/capabilities.json`,
compared 2026-09-24), decided `build` by the OpenSpec pass of 2026-09-27. It is
in the core area of the matrix (pipeline).

**`pipeline-multi-currency`**, "Record a deal in a foreign currency and see it
converted at the current rate". Rated partial, built.state built. Matrix
evidence: "lib/Settings/register.d/50-forecast.json declares lead.currency
(three-letter code), src/views/leads/LeadForm.vue edits it next to the value,
and lib/Service/ForecastRollupService.php:76 converts it through
lib/Service/ExchangeRateService.php at the rate table that
src/components/admin/ForecastSettings.vue now edits (pipelinq#2040). The rates
are kept by hand, not fetched, so 'the current rate' is only as current as the
admin keeps it". Demand: changelog, https://github.com/espocrm/espocrm/issues/3544.
Two competitors rate it yes:

- pipedrive: https://support.pipedrive.com/en/article/how-is-currency-converted-in-pipedrive,
  "Currency conversion in reporting is calculated through our partner Open
  Exchange Rates. Pipedrive automatically converts the value of deals won in
  different currencies".
- espocrm: source read,
  `application/Espo/Modules/Crm/Resources/metadata/entityDefs/Opportunity.json:8`
  `amount` of type currency and `:15` `amountConverted`; rates are
  `CurrencyRecordRate` entities since 9.3.0.

The missing half is where the rates come from. Currency on a deal and the
conversion already work.

## What changes

- `ExchangeRateService` reads rates from the fleet's rate store before its own
  table: shillinq's `FxRate` records when shillinq is installed, otherwise the
  European Central Bank daily reference rates through integriq's source when it
  is configured.
- The hand-kept table stays, as a fallback and as an override an administrator
  can set on purpose.
- Every conversion knows the rate, its source (shillinq, ECB, manual) and its
  date. The forecast and LeadDetail show them, and a rate older than a set number
  of days is flagged.

## Out of scope

- Fetching rates inside pipelinq. Fetching is integriq's job (shillinq change
  `banking-fx-at-booking` lists "Fetching rates (integriq)" as a non-goal for the
  same reason). No ECB source is seeded in integriq today; that dependency is
  named in the hand-back of the OpenSpec pass.
- Historic conversion at the close date of a won deal. This change converts at
  the newest rate, as the row asks.

## Impact

- `lib/Service/ExchangeRateService.php` (rate resolution order, rate metadata).
- `lib/Service/ForecastRollupService.php` (carries the rate metadata).
- `src/components/admin/ForecastSettings.vue` (source per currency, override).
- LeadDetail value widget (rate line).

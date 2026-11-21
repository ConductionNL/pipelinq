# Tasks: forecast-slip-reason

## 1. Data
- [x] 1.1 Add `slip_reason` (string), `slipped_from` (date) and `slipped_at` (datetime) to the deal (lead) schema in `lib/Settings/register.d/50-forecast.json`.
  - done: in a late fragment, `lib/Settings/register.d/99-zzzzzzzz-forecast-slip-reason.json` (lead 1.8.0), so the lead version moves past the other lead fragments; `slipped_from` and `slipped_at` are read only. Schema strings in `l10n/en.json` and `l10n/nl.json`.

## 2. Validation
- [x] 2.1 In `ForecastDealService::validateTransition()`, return `forecast.error.slip_reason_required` when an open deal in `commit` or `best_case` gets an `expectedCloseDate` after the end of the fiscal period that held its old close date, and `slip_reason` is shorter than `JUSTIFICATION_MIN_LENGTH`.
  - done: `ForecastDealService::validateTransition()` via `ForecastSlipRule` (the quarter of the old close date, from `FiscalPeriodService`). `DealUpdatedListener` undoes a refused slip by restoring the old close date, not the category.
- [x] 2.2 On a valid slip, set `slipped_from` to the old close date and `slipped_at` to now.
  - done: `ForecastDealService::applySlip()`, saved by `DealUpdatedListener`'s deferred pass; recorded once per slip.

## 3. Interface
- [x] 3.1 On the lead detail (PqLead), saving a slipped close date opens a reason dialog in its own file under `src/modals/`; the save does not finish without a reason.
  - done: `src/modals/SlipReasonModal.vue`, opened by `src/dialogs/LeadEditDialog.vue` in LeadDetail's `form-dialog` slot (the library's own schema form saved through the page's `confirm`). `src/services/forecastSlipCheck.js` mirrors `ForecastSlipRule` (calendar quarter of the old close date, open `commit` or `best_case` deals). A cancelled reason saves nothing and says why. `tests/vitest/forecastSlipReasonDialog.spec.js`. Owed: the same check in a live browser.
- [x] 3.2 In the forecast trend (PqForecast), the list of deals that moved out of commit or best case shows each deal's slip reason.
  - done: the trend's card "Deals that slipped out of this quarter" lists each lead whose `slipped_from` lies in the quarter, with where it slipped from, its close date now and the slip reason (`src/services/forecastSlips.js`, `ForecastTrend.vue`). The week-over-week movements stay per seller, read from the snapshots, which carry no deals.

## 4. Tests
- [x] 4.1 PHPUnit for 2.1: a slipped commit deal without a reason is rejected; with a reason it saves; a close date moved within the same period, or a deal in pipeline, saves without one.
  - done: `ForecastDealServiceTest` (+3) and `DealUpdatedListenerTest` (+2).
- [x] 4.2 Unit test for the trend list showing the reason.
  - done: `tests/vitest/forecastSlipReason.spec.js` (period bounds, row filter and order, the OpenRegister query, a failed read, the rendered list and its empty state).

## 5. Capability row
- [ ] 5.1 When 1 to 4 are done, add the slip evidence to `forecast-category` in `openspec/parity/capabilities.json` and archive this change.

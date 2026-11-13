# Tasks: forecast-slip-reason

## 1. Data
- [ ] 1.1 Add `slip_reason` (string), `slipped_from` (date) and `slipped_at` (datetime) to the deal (lead) schema in `lib/Settings/register.d/50-forecast.json`.

## 2. Validation
- [ ] 2.1 In `ForecastDealService::validateTransition()`, return `forecast.error.slip_reason_required` when an open deal in `commit` or `best_case` gets an `expectedCloseDate` after the end of the fiscal period that held its old close date, and `slip_reason` is shorter than `JUSTIFICATION_MIN_LENGTH`.
- [ ] 2.2 On a valid slip, set `slipped_from` to the old close date and `slipped_at` to now.

## 3. Interface
- [ ] 3.1 On the lead detail (PqLead), saving a slipped close date opens a reason dialog in its own file under `src/modals/`; the save does not finish without a reason.
- [ ] 3.2 In the forecast trend (PqForecast), the list of deals that moved out of commit or best case shows each deal's slip reason.

## 4. Tests
- [ ] 4.1 PHPUnit for 2.1: a slipped commit deal without a reason is rejected; with a reason it saves; a close date moved within the same period, or a deal in pipeline, saves without one.
- [ ] 4.2 Unit test for the trend list showing the reason.

## 5. Capability row
- [ ] 5.1 When 1 to 4 are done, add the slip evidence to `forecast-category` in `openspec/parity/capabilities.json` and archive this change.

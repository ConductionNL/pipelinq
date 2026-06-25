## 1. Confirm the aggregation contract is reachable

- [x] 1.1 DI-resolve `OCA\OpenRegister\Service\Aggregation\AggregationRunner` on `:8080` and smoke `runAdhocByRef` a `count` (STEP 0)

## 2. CashShiftService::sumConfirmedSales

- [x] 2.1 Replace fetch-all + PHP window-sum with ungrouped `SUM(total)` via `runAdhocByRef`, filter `status IN (confirmed,settled)` + `confirmedAt` `gte`/`lte`; keep the `money()` round and the OR-unavailable → `0.0` degrade
- [x] 2.2 Add a `getAggregationRunner()` DI helper mirroring `getObjectService()`

## 3. PointsLedgerService::getAccountBalance

- [x] 3.1 Replace history-sum with ungrouped `SUM(aantal)` filtered by `accountId`; empty → `0`; add `getAggregationRunner()` helper
- [x] 3.2 Confirm the windowed legs (`getLedgerHistory`, `countAlreadyEarned`) STAY in PHP — string-compared timestamp window diverges from OR `gte` (proven live); document inline

## 4. LoyaltyReportingService::getTierReport

- [x] 4.1 Replace hydrate-and-bucket with grouped `COUNT` by `currentTierId`; fold the `null`/`''` group into the `unassigned` bucket in PHP; add a PHP fallback path on OR failure
- [x] 4.2 Add `getAggregationRunner()` + `accountConfig()` helpers
- [x] 4.3 Confirm `getLiabilitySnapshot` / `getKpis` outstanding-points STAY in PHP — per-row `max(0,…)` floor; document inline

## 5. PosStaffReportService::staffSalesReport

- [x] 5.1 Replace the per-row signed reduce with grouped `COUNT` (3 final statuses) + signed split `SUM` (`SUM(non-refunded) − SUM(refunded)`) for `total` and `totalTax`, grouped by `staffMemberId`; drop the empty-staff bucket; keep the display-name resolve + cent rounding; add a PHP fallback path
- [x] 5.2 Add `getAggregationRunner()` helper + the `groupedAgg()` flattening helper

## 6. Stubs + tests

- [x] 6.1 Add `tests/Stubs/Service/Aggregation/AggregationQuery.php` + `AggregationRunner.php` stubs (class_exists guarded; faithful `AggregationQuery::create` validation)
- [x] 6.2 Add `tests/Unit/Service/FakeAggregationRunner.php` computing count/sum (grouped + ungrouped) over an in-memory row set with OR-matching filter operators (`in`/`gte`/`lte`, date-aware compare)
- [x] 6.3 Add `QueryPushdownBatch2Test` asserting each pushed-down method equals the prior PHP reduce; re-wire `CashShiftServiceTest`'s container to return the fake runner over its live store

## 7. Quality + live verification

- [x] 7.1 `composer lint` + `phpcs --warning-severity=0` clean on the four changed `lib/` files
- [x] 7.2 Candidate + new unit tests green (full suite baseline unchanged: pre-existing stub-vs-real-OR return-type errors are not a regression)
- [x] 7.3 LIVE on `:8080`: run the real `sumConfirmedSales` / `getAccountBalance` / `getTierReport` / `staffSalesReport` against seeded objects and confirm each total equals the PHP computation

## 1. Delete dead background job

- [x] 1.1 Grep-confirm `ComplaintSlaJob` is unregistered (absent from `appinfo/info.xml` `<background-jobs>` and `lib/AppInfo/Application.php`) and that `SlaDeadlineSweepJob` covers `complaint_schema` SLA work
- [x] 1.2 Delete `lib/BackgroundJob/ComplaintSlaJob.php` and `tests/Unit/BackgroundJob/ComplaintSlaJobTest.php`

## 2. QueueService depth bug-fix + pushdown

- [x] 2.1 Replace `getQueueDepth` `findAll(['limit' => 1])`+`count()` with `ObjectService::count(['filters' => [...]])`
- [x] 2.2 Un-skip and rewrite `QueueServiceTest::testGetQueueDepthReturnsItemCount` to mock `count()` → 3 and assert depth 3

## 3. PosRoleService / PosStaffService

- [x] 3.1 `countActiveStaffForRole`: push the `posRole` filter to OR via `findAll(config:)`; keep the `isActive`-default count in PHP (missing flag = active)
- [x] 3.2 Fix the latent OR-signature bug in `PosRoleService::listRoles` and `PosStaffService::listStaff` (`findAll(register:, schema:, limit:)` → `findAll(config: ['filters' => ...])`)
- [x] 3.3 Add `PosRoleServiceTest` proving the count incl. missing-flag preservation; update the `PosStaffServiceTest` fake to the real `findAll(array $config)` + add `count`

## 4. PosCustomerLinkService (stays PHP)

- [x] 4.1 Confirm `getCustomerHistory` keeps the draft/parked NOT-IN exclude and the computed-`createdAt` sort in PHP (only `customer` equality is server-side); document why

## 5. BlastService pagination

- [x] 5.1 Add `countObjects` / `countDeliveries`; give `loadObjects` / `loadDeliveries` optional `limit`/`offset`; fix their `findAll(register:, schema:)` named-arg bug to `findAll(config:)`
- [x] 5.2 Rewire `listBlasts` / `listDeliveriesForBlast` to `count()` total + paged loader (replace `array_slice`)
- [x] 5.3 Update the `BlastServiceTest` fake to `findAll(array $config)` + `count`

## 6. RoutingService

- [x] 6.1 Push the open-leads leg of `getAgentWorkload` to `ObjectService::count()`; keep the open-requests NOT-IN leg in PHP with a comment
- [x] 6.2 Add `RoutingServiceWorkloadTest` proving requests(PHP)+leads(count) sum

## 7. ForecastService

- [x] 7.1 Add optional `sort`/`limit` to `findObjects`; `latestSnapshot`/`latestOverride` push `sort DESC` + `limit 1` and drop the `usort`+`[0]`

## 8. ForecastExportService

- [x] 8.1 Add optional `sort`/`limit`/`offset` to `findObjects` + a `countObjects`; `exportSnapshots` pushes `sort ASC` + page window + `count()` total (drop `usort`+`array_slice`); keep `childOwners` dedupe in PHP
- [x] 8.2 Add `ForecastExportServiceTest` proving server-side total + ASC-sorted page window

## 9. Stub + quality + verification

- [x] 9.1 Add `count(array $config)` to `tests/Stubs/Service/ObjectService.php`
- [x] 9.2 `composer lint` + `phpcs --warning-severity=0` clean on changed `lib/`; full PHPUnit suite green
- [x] 9.3 LIVE smoke-test on :8080: `getQueueDepth` returns the true count (> 1) and `countActiveStaffForRole` preserves the isActive default

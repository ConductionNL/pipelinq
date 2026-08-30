# Design — Query Pushdown Batch 1

## The anti-pattern

```php
// BEFORE — fetch every matching row, then count/sort/slice in PHP
$rows  = $objectService->findAll(['filters' => [...], 'limit' => 999]);
$total = count($rows);                 // or a PHP loop counting a sub-predicate
usort($rows, fn ($a, $b) => ...);      // sort on a stored field
$page  = array_slice($rows, $offset, $limit);
```

```php
// AFTER — push count / sort / page into OpenRegister's query engine
$total = $objectService->count(['filters' => [...]]);
$page  = $objectService->findAll(['filters' => [...], 'sort' => ['field' => 'DESC'], 'limit' => $limit, 'offset' => $offset]);
```

## OpenRegister capabilities reachable from pipelinq's call path

Pipelinq resolves the shared `OCA\OpenRegister\Service\ObjectService` from the DI container and calls
its public methods. Confirmed reachable and used here:

- `count(array $config)` — `COUNT(*)` over `$config['filters']` (register/schema + field equality).
- `findAll(array $config)` — `filters` (equality; arrays → `IN`; associative `field => ['gte'|'lte'|'gt'|'lt'|'ne' => v]` → comparison; date range via `gte`/`lte`), `sort` (`['field' => 'ASC'|'DESC']`), `limit`, `offset`.

Confirmed **NOT** reachable (so those legs stay in PHP):

- No `SUM` / `AVG` aggregation facet — only `terms` / `range` / `date_histogram` facets exist.
- No `NOT IN` operator.
- No sort on a computed/coalesced value; no cheap `DISTINCT` via the simple filter call path.

## The QueueService bug

`getQueueDepth()` called `findAll(['filters' => [...], 'limit' => 1])` then `return count($results)`.
With `limit: 1` the result array never holds more than one row, so the reported depth was capped at
**1**. `processOverflow()` / `isAtCapacity()` therefore could not detect a queue that was over
capacity by more than one item. The fix replaces the call with `count(['filters' => [...]])`, which
returns the true number of queue items.

## Behavior-preservation notes

- **count() vs findAll()+count():** identical result for an unbounded count; the only change is the
  query engine does the counting. Filters are unchanged.
- **sort + limit 1 vs usort + [0]:** for a single stored ISO-date sort key (`as_of_date`,
  `created_at`) the OR DESC sort selects the same top row as the PHP `strcmp` DESC sort; ties are
  arbitrary under both, so "latest" semantics are preserved.
- **limit/offset paging vs array_slice:** the BlastService loaders add no sort, so OR's default order
  is the same order `array_slice` walked; the page window is identical and the total now comes from
  a server-side `count()` rather than `count()` of the full fetched array.
- **PosRole isActive default:** the original counts a row with a *missing* `isActive` field as active
  (`(bool) ($staff['isActive'] ?? true)`). Pushing `isActive == true` to OR would wrongly exclude
  missing-flag rows, so only the unambiguous `posRole` equality is pushed down; the `isActive` default
  stays in PHP.

## Tests

- Un-skip + rewrite `QueueServiceTest::testGetQueueDepthReturnsItemCount` to mock `count()` → 3 and
  assert depth 3 (regression guard for the `limit: 1` cap).
- New `PosRoleServiceTest`, `RoutingServiceWorkloadTest`, `ForecastExportServiceTest` proving the
  pushdown results equal the prior PHP results (incl. the isActive-default preservation and the
  server-side total).
- Update the BlastService and PosStaff test fakes from the obsolete `findAll(register, schema, limit)`
  signature to the real `findAll(array $config)` + add `count(array $config)`.
- Add `count(array $config)` to `tests/Stubs/Service/ObjectService.php`.

---
kind: code
---

## Why

This is **Seam 1, Batch 2** of the OpenRegister query-pushdown work: the money / reporting paths that
implement a **fetch-all-then-PHP-`SUM`/`AVG`/group** anti-pattern. They call
`ObjectService::findAll(...)` with a large `limit`, hydrate every matching row into PHP, and then
sum / count / group in application code. Batch 1 could not move these because OpenRegister exposed no
`SUM`/`AVG` aggregation facet. That gap is now closed: OpenRegister ships an **ad-hoc aggregation
contract** — `OCA\OpenRegister\Service\Aggregation\AggregationRunner::runAdhocByRef($registerRef,
$schemaRef, AggregationQuery)` — that computes `count`/`sum`/`avg`/`min`/`max`, optionally grouped,
under the same `list` RBAC and `_organisation` tenant scoping as `findAll`. Pushing the aggregation
down honours ADR-022 (apps consume OR abstractions rather than re-deriving them) and removes the
unbounded row hydrate.

This is a **strictly behavior-preserving** refactor. Every preserved number was proven identical to
the prior PHP result both by a unit test (driven by an in-memory fake aggregation runner over the
same rows) and by a live run of the real service method against seeded objects on the `:8080`
instance. Where a domain rule cannot be expressed by a single server-side aggregate — a per-row
`max(0, …)` floor, a per-transaction sign flip before `SUM`, a nested-array breakdown, a
string-compared timestamp window, a coalesced/defaulted bucket, or a case-folded `NOT IN` — that leg
stays in PHP and the reason is documented inline.

## The aggregation contract used

```php
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;

$query  = AggregationQuery::create(
    metric: 'sum',                 // count | sum | avg | min | max
    field:  'total',               // required for non-count metrics
    filter: ['status' => ['in' => ['confirmed', 'settled']], 'confirmedAt' => ['gte' => $from, 'lte' => $to]],
    groupBy: ['field' => 'staffMemberId'],   // optional
);
$result = $this->getAggregationRunner()->runAdhocByRef(registerRef: $reg, schemaRef: $sch, query: $query);
// ungrouped: ['value' => float|int|null, 'backend' => …, 'cached' => …]   (null on empty set)
// grouped:   ['groups' => [['key' => …, 'value' => …], …], 'backend' => …]
```

The runner is resolved from the DI container the same way `ObjectService` is, via a new
`getAggregationRunner()` helper on each touched service. No constructor signatures change.

## What Changes — pushed down

- **CashShiftService::sumConfirmedSales** → ungrouped `SUM(total)` with `status IN (confirmed, settled)`
  and a `confirmedAt` `gte`/`lte` window. The prior PHP path bounded the window via `strtotime()`
  integer comparison, which OpenRegister's native date-range path reproduces identically (verified at
  window boundaries). Degrades to `0.0` when OR is unavailable, mirroring the prior catch.
- **PointsLedgerService::getAccountBalance** → ungrouped `SUM(aantal)` for the account. No date window,
  so the SQL `SUM` equals the prior full-history PHP sum (empty ledger → `null` → `0`).
- **LoyaltyReportingService::getTierReport** → grouped `COUNT` by `currentTierId`. A missing/empty tier
  key comes back as a `null`/`''` group and is folded into the `unassigned` bucket in PHP to preserve
  the original `?? 'unassigned'` default. Falls back to the original PHP bucketing on OR failure.
- **PosStaffReportService::staffSalesReport** → grouped `COUNT` over the three final statuses plus a
  signed split `SUM`: `total(staff) = SUM(total | confirmed,settled) − SUM(total | refunded)` (and the
  same for `totalTax`). This reconstructs the prior per-row sign flip (refunded → −1) that a single
  grouped `SUM` cannot express. The empty-`staffMemberId` bucket is dropped to match the prior skip.
  Falls back to the original PHP reduce on OR failure.

## What Changes — STAYS in PHP (with reason)

- **LoyaltyReportingService::getLiabilitySnapshot** and the `getKpis` `outstandingPoints` / tier /
  active legs — the liability `SUM` carries a **per-account `max(0, …)` floor** applied before summing,
  which a server `SUM` cannot express. In `getKpis` the floor already forces a full account hydrate, so
  the co-located tier-distribution and active-account counts ride along in the same loop for free.
- **PointsLedgerService::getLedgerHistory** / **LoyaltyEngineService::countAlreadyEarned** — the date
  window is a **PHP string comparison** against a stored timestamp OpenRegister normalises to a
  space-separated `Y-m-d H:i:s` form. Pushing a `gte` on `timestamp` would include same-day rows the
  string compare currently drops, **changing the number** (proven live: 50 → 150). The windowed legs
  stay in PHP; only the un-windowed full-ledger balance is pushed.
- **PosTransactionService::buildTaxReport** and **PosBookkeepingService::aggregateTransactions** per-rate
  BTW — the rate breakdown lives in a **nested `invoiceBreakdown`/`taxBreakdown` array** inside each
  transaction, with a per-transaction sign, not a top-level scalar column; OR groups on a column, so
  this is not expressible.
- **PosBookkeepingService::fetchTransactionsForDate** — the date match is on
  `COALESCE(settledAt, confirmedAt)` truncated to a **day**, not a single-column range.
- **RapportageService** (`getSourcePerformance`, `getAgingBuckets`, `getWinLossAnalysis`) — these mix a
  `_dateCreated` ↦ `@self.created` **fallback** date, a per-row conditional before the `won` sum, a
  default `'unknown'` source bucket, and fixed aging-range bucketing on a derived day-count; none are a
  single server-side aggregate.
- **RoutingService::getAgentWorkload** open-requests leg — "non-terminal" is a **case-folded** `NOT IN`
  (`strtolower($status)`); OpenRegister's `notIn` is exact, so pushing it would diverge on any
  mixed-case stored status. Stays a PHP count (as in Batch 1).

## Verification

- Unit: `QueryPushdownBatch2Test` asserts each pushed-down method returns the SAME number the prior PHP
  reduce produced over identical rows, via an in-memory `FakeAggregationRunner` mirroring OR's
  count/sum/group + filter semantics; `CashShiftServiceTest` re-points its in-memory store through the
  fake runner and keeps all window/boundary/exclusion assertions green.
- Live (`:8080`): the real `sumConfirmedSales` (350), `getAccountBalance` (70), `getTierReport`
  (gold=2, unassigned=1), and `staffSalesReport` (sx1 count 3 / total 120 / tax 25.2 with refund
  netting; sx2 count 1 / total 200) were run against seeded objects and matched the PHP computation.

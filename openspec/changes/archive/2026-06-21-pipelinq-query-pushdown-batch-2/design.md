# Design — Query Pushdown Batch 2 (Ad-Hoc Aggregation)

## The anti-pattern

```php
// BEFORE — hydrate every matching row, then SUM / group in PHP
$rows = $objectService->findAll(['filters' => [...], 'limit' => 10000]);
$sum  = 0.0;
foreach ($rows as $r) {
    if (in_array($r['status'], ['confirmed', 'settled'], true) && inWindow($r['confirmedAt'])) {
        $sum += (float) $r['total'];
    }
}
```

```php
// AFTER — push the SUM (and its filter) into OpenRegister's aggregation runner
$query  = AggregationQuery::create(
    metric: 'sum', field: 'total',
    filter: ['status' => ['in' => ['confirmed', 'settled']], 'confirmedAt' => ['gte' => $from, 'lte' => $to]],
);
$result = $this->getAggregationRunner()->runAdhocByRef(registerRef: $reg, schemaRef: $sch, query: $query);
$sum    = (float) ($result['value'] ?? 0.0);
```

## The contract reachable from pipelinq's call path

Pipelinq resolves `OCA\OpenRegister\Service\Aggregation\AggregationRunner` from the DI container
(the same resolution path as `ObjectService`) via a new `getAggregationRunner()` helper on each
touched service. Confirmed reachable and used here:

- `runAdhocByRef(string $registerRef, string $schemaRef, AggregationQuery $query): array`
  — `count` / `sum` (and `avg`/`min`/`max`, unused here), ungrouped or grouped by one field.
- `AggregationQuery::create(metric, field, filter, groupBy)` — `filter` supports scalar equality plus
  `in` / `notIn` / `gt` / `gte` / `lt` / `lte` / `ne` per field; `groupBy => ['field' => …]`.
- Same `list` RBAC + `_organisation` tenant scoping as `findAll`. Throws `NotAuthorized` (403) /
  `RuntimeException` (404 bad ref). Result shapes: ungrouped `['value' => float|int|null, …]`
  (`null` on an empty set; `int` for count, `float` otherwise); grouped
  `['groups' => [['key' => …, 'value' => …], …], …]`.

The register/schema refs are the same ones each service already passes to its `findAll` calls.

## What can be expressed server-side — and what cannot

A leg moves to `runAdhocByRef` **only** when it is a plain `COUNT`/`SUM` over a top-level column with
filters OR a single-field `groupBy`, and no per-row transformation precedes the aggregate. Concretely:

| Candidate | Pushed? | Why / why not |
| --- | --- | --- |
| `CashShiftService::sumConfirmedSales` | **yes** | `SUM(total)`, status `in`, `confirmedAt` range — the PHP path compared via `strtotime` and OR's native date range matches it (verified at boundaries) |
| `PointsLedgerService::getAccountBalance` | **yes** | `SUM(aantal)`, no window — equals the prior full-history sum |
| `LoyaltyReportingService::getTierReport` | **yes** | grouped `COUNT` by `currentTierId`; `null`/`''` key folded to `unassigned` in PHP |
| `PosStaffReportService::staffSalesReport` | **yes** | grouped `COUNT` + signed split `SUM` (non-refunded − refunded) reconstructs the per-row sign |
| `LoyaltyReportingService::getLiabilitySnapshot` / `getKpis` outstanding | no | per-account `max(0, …)` floor before `SUM` — not a server aggregate; the floor already forces a hydrate in `getKpis` |
| `PointsLedgerService::getLedgerHistory` / `LoyaltyEngineService::countAlreadyEarned` | no (windowed) | the date window is a PHP **string** compare against a space-normalised stored timestamp; OR `gte` includes same-day rows the string compare drops (proven live: 50 → 150) |
| `PosTransactionService::buildTaxReport` / `PosBookkeepingService::aggregateTransactions` | no | per-rate sums live in a **nested array** inside each row with a per-transaction sign, not a scalar column |
| `PosBookkeepingService::fetchTransactionsForDate` | no | match is on `COALESCE(settledAt, confirmedAt)` truncated to a day |
| `RapportageService` (source/aging/win-loss) | no | `_dateCreated`↦`@self.created` fallback date + per-row conditional sums + default `'unknown'` bucket + derived aging buckets |
| `RoutingService::getAgentWorkload` open-requests | no | "non-terminal" is a **case-folded** `NOT IN`; OR `notIn` is exact and would diverge on mixed-case data |

## Behavior-preservation notes

- **Signed split SUM (staff report):** the original applied `sign = (status === 'refunded') ? -1 : 1`
  per row before summing. A single grouped `SUM` over all three statuses would *add* refunds. The
  refund-netting is reconstructed as `SUM(total | confirmed,settled) − SUM(total | refunded)` per
  staff, so the per-staff total and tax (and the all-three-status `transactionCount`) are bit-for-bit
  the prior values. Empty-`staffMemberId` rows are dropped, matching the prior `continue`.
- **Default bucket (tier report):** OR returns missing/empty `currentTierId` as a `null`/`''` group;
  these are folded into `unassigned` in PHP, and because two source buckets (`null` and `''`) can both
  map to `unassigned` the counts are accumulated, never overwritten.
- **Empty-set semantics:** an ungrouped `SUM` over no rows returns `null`; `(float) (… ?? 0.0)` /
  `(int) round(… ?? 0)` reproduce the prior "summed nothing = 0" result.
- **Timestamp format trap:** OpenRegister stores `timestamp` / `confirmedAt` as `Y-m-d H:i:s` (space,
  no offset). The `strtotime`-based windows (`sumConfirmedSales`) are safe to push; the raw
  **string-compared** windows (`getLedgerHistory`) are not — so only the un-windowed ledger balance
  moved, and the windowed legs stay in PHP.
- **Graceful degradation:** every pushed-down call is wrapped so an OR failure returns the same
  fallback the prior `findAll` catch did — `0.0` / `0` for the sums, and the original PHP
  bucketing/reduce for the grouped reports.

## Tests

- `tests/Unit/Service/FakeAggregationRunner.php` — an in-memory runner that computes count/sum
  (grouped + ungrouped) over a row list using OR's filter operator vocabulary (`in`, date-aware
  `gte`/`lte`, scalar equality), returning `null` on an empty numeric set. Lets a unit test assert
  pushdown == prior-PHP-reduce on identical data rather than against a hand-rolled expectation.
- `tests/Unit/Service/QueryPushdownBatch2Test.php` — one test per pushed-down method: it computes the
  oracle with the original PHP reduce inline, then asserts the refactored method (driven by the fake
  runner) returns the identical value, including refund netting and the `unassigned` fold.
- `CashShiftServiceTest` — its container mock now also answers the `AggregationRunner` id with a
  `FakeAggregationRunner` built from the live posTransaction store, so the existing window / boundary /
  status-exclusion assertions exercise the new path unchanged.
- `tests/Stubs/Service/Aggregation/{AggregationQuery,AggregationRunner}.php` — `class_exists`-guarded
  stubs so the bare unit environment can type-hint and build queries; replaced by the real classes when
  openregister is installed.

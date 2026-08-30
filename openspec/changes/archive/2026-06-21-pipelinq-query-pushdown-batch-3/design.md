# Design — Query Pushdown Batch 3 (COUNT / facet + mechanical list pushdowns, non-GDPR)

## The anti-pattern

```php
// BEFORE — one un-filtered findAll hydrates every request, then PHP filters it twice
$allRequests = $this->findAllSafe('request_schema');           // ALL requests
$assigned = [];
foreach ($allRequests as $r) {                                  // purpose 1: my open requests
    if ((string)($r['assignee'] ?? '') === $userId && in_array($r['status'], OPEN, true)) {
        $assigned[] = $r;
    }
}
foreach ($allRequests as $r) {                                  // purpose 2: open count per queue
    if (in_array($r['status'], OPEN, true) && $r['queue'] !== '') { /* resolve queue→slug, ++ */ }
}
```

```php
// AFTER — express each purpose as its own server-side query
$assigned = $objectService->findAll(config: ['filters' =>
    ['register' => $reg, 'schema' => $sch, 'assignee' => $userId, 'status' => OPEN]]);   // filtered list

$grouped  = $this->getAggregationRunner()->runAdhocByRef($reg, $sch,
    AggregationQuery::create(metric: 'count', filter: ['status' => ['in' => OPEN]],
                             groupBy: ['field' => 'queue']));                            // grouped count
// PHP still resolves each group key (raw queue ref) → queue slug and folds into queueCounts[slug]
```

## The contract reachable from pipelinq's call path

Identical to Batch 2 — pipelinq resolves `OCA\OpenRegister\Service\Aggregation\AggregationRunner`
from the DI container (the same path as `ObjectService`) via a `getAggregationRunner()` helper:

- `runAdhocByRef(string $registerRef, string $schemaRef, AggregationQuery $query): array` — `count`
  grouped by one field. Grouped result: `['groups' => [['key' => …, 'value' => <int>], …], …]`; a
  missing/null group key comes back as a `null` key.
- `AggregationQuery::create(metric, field, filter, groupBy)` — `filter` supports scalar equality plus
  `in` per field; `groupBy => ['field' => …]`.
- `ObjectService::findAll(['filters' => [... 'field' => value | [v1, v2] ...]])` — a scalar value is
  `eq`; an array value is `IN`. Same `list` RBAC + `_organisation` tenant scoping as before.

The register/schema refs are the same ones the service already passes to its `findAll` calls.

## What can be expressed server-side — and what cannot

A leg moves to a pushdown **only** when it is a plain filtered `findAll` (equality / `IN` on a
top-level column) or a single-field grouped `COUNT`, and no per-row transformation, cross-source
merge, computed bucket key, computed/ISO-`T` date window, or post-IDOR ordering precedes it.

| Candidate | Pushed? | Why / why not |
| --- | --- | --- |
| `KccWerkplekService::getWorkspaceState` — assignedRequests | **yes** | filtered `findAll` `assignee = userId` + `status IN (new, in_progress)`; missing-assignee never matched before (`userId` non-empty), so `eq` is exact; natural order preserved |
| `KccWerkplekService::getWorkspaceState` — queueCounts | **yes** | grouped `COUNT` by stored `queue`, `status IN` filter; the raw group key is re-mapped to a slug **in PHP** (a request stores slug-or-id), folded into the seeded `queueCounts` exactly as before |
| `NaviService` trend / breakdown / conversion / count | no | rows are fetched **once** and feed the empty-result guard + `rawData` envelope; trend buckets by a PHP-computed `o-\WW` week key; pushing requires re-architecting `processQuery` |
| `Portal/AbstractPortalReadFacade::getForAccount` | no | per-row **IDOR scope classify** removes rows *before* sort/paginate; pushing `sort`/`limit` would slice the wrong set |
| `Portal/PortalRequestService::assertWithinRateLimit` | no | account-scoped (already filtered, small) fail-open security count over a date window; boundary risk outweighs the saving |
| `ChannelProviderRepository::listActive` | no | `kind`/`active` filter already pushed; residual is a defence-in-depth boolean re-check + a `?? 100` default-bucket `usort` on a tiny set |
| `EntityActivityService::getActivity` | no | **cross-source merge** of contactmoment rows + an entity-object `notes` array; per-schema filters already pushed |
| `ActivityTimelineService::getTimeline` | no | four-schema **cross-source merge** + an **ISO-`T` / end-of-day** date window + per-source `date` mapping |
| `ActivityTimelineService::getWorklog` | no | must hydrate every match to **sum ISO-8601 durations** (`totalDuration`); sort is on a computed `date` field |
| `BrpCacheService::get` / `invalidate` | no | `bsnHash` filter already pushed; `get` selects max-unexpired via a **tz-aware `DateTimeImmutable`** compare + lexical max; `invalidate` mutates every row |

## Behavior-preservation notes (KccWerkplek)

- **Computed bucket key (queueCounts):** the prior loop matched `request['queue']` (slug *or* id)
  against each queue's slug *and* id, then incremented `queueCounts[$qSlug]`. The grouped `COUNT`
  groups by the **raw** stored `queue` value, so PHP must still map each group `key` → slug before
  adding `value`. The `null`/empty group (requests with no `queue`) matches no queue → not counted,
  matching the prior `$queueRef === '' continue`. `queueCounts` is seeded to `0` for every queue with a
  non-empty slug (active or not) before folding, exactly as before. A group whose key matches no queue
  is dropped (the prior inner loop `break`-less no-match also dropped it).
- **`assignee` eq vs the prior string compare:** the prior path did
  `(string)($request['assignee'] ?? '') === $userId`. Because `$userId` is the authenticated UID
  (never empty), a request with a missing/empty `assignee` never matched; an `eq` filter on `assignee`
  likewise excludes missing values, so the matching subset is identical. `openTasks` is filtered the
  same way (`assigneeUserId = userId` + `status IN (open, in_behandeling)`).
- **Order preservation:** the prior `assignedRequests` / `openTasks` were built by iterating the
  `findAll` result in natural order with no `usort`; the filtered `findAll` returns the same matching
  rows in the same natural order. Only `queues` carries a `sortOrder` `usort`, which is unchanged.
- **Graceful degradation:** each pushed-down call is wrapped so an OR failure yields the same empty
  result the prior `findAllSafe`/`findAll` catch did — an empty `assignedRequests` / `openTasks` /
  `queueCounts`, and the page still renders (REQ-KWP-010).

## Why the other candidates stay in PHP (gotchas honoured)

- **Date-range divergence (gotcha 1):** `ActivityTimelineService` appends `T23:59:59` to bare to-dates
  (ISO-`T`), and `BrpCacheService` compares with tz-aware `DateTimeImmutable` against a possibly
  offset-suffixed stored value. Both are the exact ISO-`T` / non-`strtotime` shape that diverges from
  OR's space-separated `Y-m-d H:i:s` native compare, so they stay in PHP.
- **Computed bucket / cross-source / post-IDOR:** `NaviService` (computed week bucket + empty-guard
  coupling), `EntityActivityService` and `ActivityTimelineService` (cross-source merges), and
  `AbstractPortalReadFacade` (sort/paginate after a per-row IDOR filter) cannot be expressed as a
  single server-side query without changing the result.

## Tests

- `tests/Unit/Service/FakeAggregationRunner.php` — reused from Batch 2 (in-memory grouped/ungrouped
  count/sum over a row list with OR's `in`/`gte`/`lte`/equality operators; a `null` group key is
  surfaced as the `__NULL__`-bucketed `key => null` group).
- `tests/Unit/Service/QueryPushdownBatch3Test.php` — builds a `KccWerkplekService` whose container
  answers both the `ObjectService` id (with a fake that honours `assignee` + `status IN` filters and
  groups nothing) and the `AggregationRunner` id (with the `FakeAggregationRunner` over the same
  request rows). It computes the oracle with the original fetch-all PHP reduce inline, then asserts the
  refactored `getWorkspaceState` returns the identical `assignedRequests` / `openTasks` / `queueCounts`
  / `queues` / `agentProfile`, including the slug-or-id queue re-map and the empty-queue exclusion.
- `tests/Stubs/Service/Aggregation/{AggregationQuery,AggregationRunner}.php` — the Batch 2
  `class_exists`-guarded stubs (unchanged) let the bare unit environment type-hint and build queries.

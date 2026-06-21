---
kind: code
---

## Why

This is **Seam 1, Batch 3** of the OpenRegister query-pushdown work: the non-GDPR COUNT / facet
and mechanical list paths that still implement a **fetch-all-then-PHP-filter/group/count**
anti-pattern. They call `ObjectService::findAll(...)` with no (or a broad) filter, hydrate every
matching row into PHP, and then filter / group-count / sort / slice in application code. Batch 2
moved the money/reporting `SUM`/`AVG` legs. This batch targets the remaining COUNT/group and
mechanical list candidates that are **not** GDPR-scoped (the GDPR services — `AvgRequestService`,
`DpiaDetectionService`, `ConsentService`, `OptOutService`, `RedactionService`,
`EvidenceCollectionService` — are explicitly deferred to Seam 3 and are untouched here).

Pushing the aggregation/filter down honours ADR-022 (apps consume OR abstractions rather than
re-deriving them) and removes the unbounded row hydrate. The contract used is the same one Batch 2
relied on: `OCA\OpenRegister\Service\Aggregation\AggregationRunner::runAdhocByRef($registerRef,
$schemaRef, AggregationQuery)` for grouped `COUNT`, plus `ObjectService::findAll(['filters' => …,
'sort' => …, 'limit' => …, 'offset' => …])` for filtered lists.

This is a **strictly behavior-preserving** refactor. Every preserved number / list was proven
identical to the prior PHP result both by a unit test (driven by an in-memory fake aggregation
runner + a fake ObjectService over the same rows) and by a live run of the real service method
against seeded objects on the `:8080` instance. Where a domain rule cannot be expressed by a single
server-side aggregate or a plain `findAll` filter — a **computed bucket key** (resolving a ref to a
slug), a **cross-source merge**, an **ISO-`T` / end-of-day date window**, a **timezone-aware
`DateTimeImmutable` compare**, a **post-IDOR-filter** sort/paginate, or a **case-folded** match —
that leg stays in PHP and the reason is documented inline.

## The aggregation / filter contract used

```php
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;

// grouped COUNT (KccWerkplek queue counts)
$query  = AggregationQuery::create(
    metric: 'count',
    filter: ['status' => ['in' => ['new', 'in_progress']]],
    groupBy: ['field' => 'queue'],
);
$result = $this->getAggregationRunner()->runAdhocByRef(registerRef: $reg, schemaRef: $sch, query: $query);
// grouped: ['groups' => [['key' => <raw queue ref|null>, 'value' => <int>], …], 'backend' => …]

// filtered list (KccWerkplek assigned requests)
$rows = $objectService->findAll(config: [
    'filters' => ['register' => $reg, 'schema' => $sch, 'assignee' => $userId, 'status' => ['new', 'in_progress']],
]);
```

The runner is resolved from the DI container the same way `ObjectService` is, via a new
`getAggregationRunner()` helper. No constructor signatures change.

## What Changes — pushed down

- **KccWerkplekService::getWorkspaceState** — previously one un-filtered `findAll('request_schema')`
  hydrated **all** requests and was reused for two purposes (the user's assigned-and-open list, and
  the per-queue open counts). It is split into the two pushed-down queries that exactly express each
  purpose:
  - **assignedRequests** → a filtered `findAll` with `assignee = userId` and `status IN (new,
    in_progress)`. The prior PHP filter compared `(string)($request['assignee'] ?? '') === $userId`
    (and `userId` is never empty, so a missing `assignee` never matched) — an `eq` filter reproduces
    this exactly, and the un-sorted natural order of the matching subset is unchanged.
  - **queueCounts** → a grouped `COUNT` by the stored `queue` field, filtered to the same open
    statuses. The **bucket key stays computed in PHP**: each returned group key (the raw `queue` ref,
    which a request may store as either the queue slug *or* its id) is re-mapped to the queue slug by
    matching against the queue list, then folded into `queueCounts[slug]`, exactly as the prior loop
    did. Groups whose key matches no queue (including the `null`/empty group) are not counted, matching
    the prior `continue`. `queueCounts` is still seeded to `0` for every queue with a non-empty slug.
  The agent-profile resolve, the active-queue assembly, and the `sortOrder` `usort` are untouched (they
  consume the separately-fetched `allAgents` / `allQueues`). The whole method degrades to the prior
  empty/partial payload when OR is unavailable (each leg is wrapped, mirroring the prior `findAll`
  catch).

## What Changes — STAYS in PHP (with reason)

- **NaviService** (`buildTrendResponse` / `buildConversionResponse` / `buildBreakdownResponse` /
  `buildCountResponse`) — the rows are fetched **once** by `buildContext` and are load-bearing beyond
  the count: `processQuery` short-circuits to a "no matching data" text response on an empty row set,
  and the row set rides into the response envelope's `rawData`. The trend leg buckets by
  `date('o-\WW', strtotime(...))` — a **PHP-computed week key** from a date, which OR cannot group on
  cleanly (gotcha: computed bucket → keep in PHP). Converting the conversion/breakdown counts to a
  pushdown would require re-architecting `processQuery`'s empty-result guard and envelope contract,
  which is not behavior-preserving within this batch.
- **Portal/AbstractPortalReadFacade::getForAccount** — the rows are fetched, then a **per-row IDOR
  scope classify** (`PortalScopeResolver::classify`, per-account delegation) removes rows *before* the
  sort and pagination. Pushing `sort`/`limit` into `findAll` would order/slice *before* the IDOR
  filter, changing which rows survive the page. The sort+paginate MUST run on the post-IDOR set.
- **Portal/PortalRequestService::assertWithinRateLimit** — an account-scoped count over a date window;
  the fetch is already `reporterAccountId`-filtered (small), and it is a fail-open security limit whose
  OR-unavailable path counts `0`. The marginal saving does not justify the date-boundary risk on a
  security control, so the windowed count stays in PHP.
- **ChannelProviderRepository::listActive** — the equality filter (`kind`, `active`) is **already**
  pushed; the residual work is a defence-in-depth `active` re-check (the comment notes some OR backends
  do not honour boolean filters) and a `usort` by `priority` with a `?? 100` **default for missing
  priority**. The result set (active providers of one kind) is tiny and the call uses the legacy
  `findAll(filters:, register:, schema:)` overload; changing its query shape to push a `sort` risks the
  exact boolean-filter quirk the code already guards against.
- **EntityActivityService::getActivity** — a **cross-source merge** of contactmomenten (separate OR
  rows) and notes (an array field *on* the entity object), sorted by a normalised `timestamp` across
  both sources then paginated. Not expressible as a single OR query; the per-schema equality filters it
  does issue are already pushed.
- **ActivityTimelineService::getTimeline / getWorklog** — `getTimeline` is a four-schema **cross-source
  merge** with a `withinDateRange` filter that appends `T23:59:59` to bare to-dates (an **ISO-`T` /
  end-of-day** window — gotcha: diverges from OR native) and sorts by a per-source-mapped `date` field.
  `getWorklog` must hydrate every matched worklog anyway to **sum ISO-8601 durations** for
  `totalDuration`, so a pushed sort/limit would save nothing; its sort is on a computed `date` field.
- **BrpCacheService::get / invalidate** — `bsnHash` equality is already pushed. `get` then selects the
  most-recent **unexpired** entry via a **timezone-aware `DateTimeImmutable`** compare (`retentieTot >
  now` in UTC) and a lexical max over `opgehaaldOp`; OR native date `gt` on a possibly-offset-suffixed
  stored value risks divergence (gotcha). `invalidate` must mutate every matched row, so the rows are
  required.

## Verification

- Unit: `QueryPushdownBatch3Test` asserts `KccWerkplekService::getWorkspaceState` returns the SAME
  `assignedRequests` / `openTasks` / `queueCounts` / `queues` / `agentProfile` the prior fetch-all PHP
  path produced over identical rows, via an in-memory `FakeAggregationRunner` (reused from Batch 2)
  mirroring OR's grouped-count + filter semantics and a fake `ObjectService` that honours the
  `assignee` + `status IN` filters.
- Live (`:8080`): the real `getWorkspaceState` was run against seeded request/task/agent/queue objects
  in register `16`; the pushed-down `assignedRequests` count, `openTasks` count, and per-queue
  `queueCounts` matched the prior PHP computation exactly.

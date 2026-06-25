## 1. Confirm the aggregation contract is reachable

- [x] 1.1 DI-resolve `OCA\OpenRegister\Service\Aggregation\AggregationRunner` on `:8080` and smoke a grouped `count` over `request_schema`

## 2. KccWerkplekService::getWorkspaceState

- [x] 2.1 Add a `getAggregationRunner()` DI helper mirroring `getObjectService()`
- [x] 2.2 Replace the un-filtered `findAllSafe('request_schema')`-for-assigned with a filtered `findAll` (`assignee = userId` + `status IN (new, in_progress)`); keep the natural order; keep the OR-unavailable → empty degrade
- [x] 2.3 Replace the open-tasks PHP filter with a filtered `findAll` (`assigneeUserId = userId` + `status IN (open, in_behandeling)`)
- [x] 2.4 Replace the per-queue PHP count loop with a grouped `COUNT` by `queue` (filter `status IN (new, in_progress)`); re-map each raw group key → queue slug in PHP and fold into the seeded `queueCounts`; keep the empty/no-match exclusion
- [x] 2.5 Keep the agent-profile resolve, active-queue assembly and `sortOrder` `usort` unchanged (they consume the separate `allAgents` / `allQueues` fetches)

## 3. Candidates that STAY in PHP (document inline)

- [x] 3.1 `NaviService` (trend / breakdown / conversion / count) — rows feed the empty-result guard + `rawData`; trend buckets by a PHP-computed week key; document inline
- [x] 3.2 `Portal/AbstractPortalReadFacade::getForAccount` — per-row IDOR classify precedes sort/paginate; document inline
- [x] 3.3 `Portal/PortalRequestService::assertWithinRateLimit` — fail-open account-scoped windowed count; boundary risk; document inline
- [x] 3.4 `ChannelProviderRepository::listActive` — filter already pushed; defence-in-depth re-check + default-bucket `usort`; document inline
- [x] 3.5 `EntityActivityService::getActivity` + `ActivityTimelineService::getTimeline`/`getWorklog` — cross-source merge / ISO-`T` window / computed-duration sum; document inline
- [x] 3.6 `BrpCacheService::get`/`invalidate` — tz-aware `DateTimeImmutable` compare + per-row mutate; document inline

## 4. Tests

- [x] 4.1 Reuse the Batch 2 `FakeAggregationRunner` + aggregation stubs
- [x] 4.2 Add `QueryPushdownBatch3Test` asserting `getWorkspaceState` equals the prior fetch-all PHP reduce (assignedRequests / openTasks / queueCounts / queues / agentProfile), including the slug-or-id queue re-map and the empty-queue exclusion

## 5. Quality + live verification

- [x] 5.1 `composer lint` + `phpcs --warning-severity=0` clean on the changed `lib/` file(s)
- [x] 5.2 New unit test green; full suite genuinely-passing count ≥ baseline (pre-existing stub-vs-real-OR harness errors are not a regression — diff against a stashed run)
- [x] 5.3 LIVE on `:8080`: run the real `getWorkspaceState` against seeded request/task/agent/queue objects and confirm assignedRequests / openTasks / queueCounts equal the PHP computation

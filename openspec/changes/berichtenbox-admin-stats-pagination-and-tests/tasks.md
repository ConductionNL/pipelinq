## 1. Bound the stats query

- [ ] 1.1 In `lib/Controller/BerichtenboxAdminController.php::stats()` (line ~117), replace the
      unbounded `findAll(config: ['filters' => [...]])` call with a bounded loop: pass an explicit
      `'limit'` (e.g. 500) and iterate `offset` until a page returns fewer rows than the limit,
      folding each page into the running `$counters` tally instead of collecting all rows first.
- [ ] 1.2 If the installed `OCA\OpenRegister\Service\ObjectService` exposes a count-by-field /
      facet aggregation for this OR version, prefer it over the manual pagination loop — check
      `openregister/lib/Service/ObjectService.php` for a facet/count method before implementing
      the loop.
- [ ] 1.3 Confirm `tally()`'s `unread` semantics (`deliveryStatus in ('queued','sent') and read !=
      true` or equivalent — read the current helper body first) are preserved exactly by the
      paginated/aggregated path.
- [ ] 1.4 Verify manually against a seeded `berichtenboxMessage` register with > `limit` rows that
      the returned counters match a one-shot unbounded query (temporary local comparison, not
      shipped).

## 2. Test coverage for BerichtenboxAdminController

- [ ] 2.1 Create `tests/Unit/Controller/BerichtenboxAdminControllerTest.php` following the existing
      `tests/Unit/Controller/*Test.php` fixture/mock conventions in this repo (check
      `BerichtenboxWebhookControllerTest.php` and `BrpControllerTest.php` for the house pattern).
- [ ] 2.2 Test `stats()`: empty `register`/`schema` config returns the zeroed counter shape with
      `unread: 0` (no `ObjectService` call).
- [ ] 2.3 Test `stats()`: a stubbed/faked `ObjectService::findAll()` (or the paginated call site
      from task 1) returning representative rows tallies to the expected per-status counters.
- [ ] 2.4 Test `stats()`: `ObjectService` throwing surfaces `Http::STATUS_INTERNAL_SERVER_ERROR`
      with an `error` key (existing catch branch).
- [ ] 2.5 Test `retry()`: resets `retryCount` to 0, clears `nextRetryAt`, sets `deliveryStatus` to
      `queued`, and calls `saveObject` with the expected uuid.
- [ ] 2.6 Test `retry()`: a `saveObject` failure surfaces `Http::STATUS_INTERNAL_SERVER_ERROR`
      with an `error` key (existing catch branch).
- [ ] 2.7 Run `composer test:unit` (or the app's PHPUnit entrypoint) and confirm the new test class
      passes and existing suites remain green.

## 3. Verify

- [ ] 3.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and fix any pre-existing
      issues surfaced in the touched file per CLAUDE.md.
- [ ] 3.2 Manually hit `GET /apps/pipelinq/api/admin/berichtenbox/stats` as an admin against a
      seeded register and confirm the response shape is unchanged from before this change.

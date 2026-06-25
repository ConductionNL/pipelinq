# OpenRegister Integration — COUNT/Facet + Mechanical List Pushdown Delta

**Spec refs**: `openregister-integration`, ADR-022 (apps consume OR abstractions)
**Standards**: OpenRegister ad-hoc aggregation contract (`AggregationRunner::runAdhocByRef` / `AggregationQuery`) + `ObjectService::findAll` filter/sort/limit

## ADDED Requirements

### Requirement: Server-Side COUNT / Facet and Filtered-List Pushdown (non-GDPR)

Backend non-GDPR read services MUST push grouped `COUNT` work down into OpenRegister's ad-hoc
aggregation runner, and filtered-list work into `ObjectService::findAll` filters, rather than
hydrating every matching object and filtering / grouping / counting in PHP. A service that needs
per-key counts MUST call `AggregationRunner::runAdhocByRef($registerRef, $schemaRef,
AggregationQuery::create(metric: 'count', filter: …, groupBy: ['field' => …]))`; a service that needs
a filtered subset MUST pass the equality / `IN` filters to `findAll(['filters' => …])`. The runner
MUST be resolved from the DI container the same way `ObjectService` is. A pushed-down call MUST
degrade to the prior fallback (empty list / empty counts / partial payload) when OpenRegister is
unavailable, so the page still renders.

A computation MAY remain a PHP loop **only** when the OpenRegister contract cannot express it —
namely a **computed bucket key** (a group key derived in PHP, e.g. resolving a stored ref to a slug),
a **cross-source merge** (combining separate schemas or an array property on a row), a date window
that is **string-compared with an ISO-`T` / end-of-day boundary** or a **timezone-aware
`DateTimeImmutable`** against a differently-formatted stored timestamp, a sort/paginate that MUST run
**after a per-row IDOR filter**, an aggregate over a value that must be **computed per row** (e.g. an
ISO-8601 duration sum), or a **case-folded** match. Such a leg MUST carry an inline comment stating
why it stays in PHP, and its result MUST NOT change.

The GDPR-scoped services (`AvgRequestService`, `DpiaDetectionService`, `ConsentService`,
`OptOutService`, `RedactionService`, `EvidenceCollectionService`) are out of scope for this delta and
MUST NOT be modified here.

**Feature tier**: MVP

#### Scenario: A user's open requests are filtered server-side

- GIVEN requests with various assignees and statuses
- WHEN `KccWerkplekService::getWorkspaceState(userId)` builds the agent's assigned-and-open list
- THEN it MUST request the subset via `findAll` with `assignee = userId` and `status IN (new, in_progress)`
- AND it MUST NOT fetch every request and filter them in PHP for this list
- AND the returned `assignedRequests` MUST equal the prior PHP-filtered list (same rows, same order)

#### Scenario: Open requests are counted per queue server-side with a computed slug bucket preserved

- GIVEN open and closed requests referencing queues by slug or by id
- WHEN `KccWerkplekService::getWorkspaceState(userId)` builds `queueCounts`
- THEN it MUST request a grouped `COUNT` by the stored `queue` field via `runAdhocByRef`, filtered to `status IN (new, in_progress)`
- AND each returned group key (the raw queue ref) MUST be re-mapped in PHP to the queue's slug and folded into `queueCounts[slug]`
- AND `queueCounts` MUST stay seeded to 0 for every queue with a non-empty slug
- AND a group whose key matches no queue (including the null/empty group) MUST NOT be counted
- AND the per-queue counts MUST equal the prior PHP loop

#### Scenario: A cross-source, computed-bucket, ISO-T-windowed, or post-IDOR leg stays in PHP with a stated reason

- GIVEN a builder that buckets by a PHP-computed week key over pre-fetched rows that also drive an
  empty-result guard, OR a timeline that merges several schemas with an end-of-day ISO-`T` date window,
  OR a portal facade that sorts and paginates only after a per-row IDOR scope filter, OR a cache lookup
  that selects the most-recent unexpired row via a timezone-aware `DateTimeImmutable` compare
- WHEN the service computes it
- THEN that specific leg MAY remain a PHP loop
- AND the code MUST carry an inline comment explaining why it cannot be pushed down
- AND its result MUST be unchanged from before the refactor

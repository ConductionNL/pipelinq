---
kind: code
---

## Why

Several pipelinq services implement a **fetch-all-then-PHP-loop** anti-pattern: they call
OpenRegister's `ObjectService::findAll(...)` (often with a large or arbitrary `limit`), pull every
matching row into PHP, and then count / filter / sort / slice in application code. OpenRegister's
query engine (`ObjectService::count()`, plus `findAll`'s `filters` operators, `sort`, `limit`,
`offset`) already performs all of that server-side. The local re-derivation wastes memory and
round-trips and contradicts ADR-022 (apps consume OR abstractions rather than re-deriving them).

This is **Seam 1, Batch 1** — the small / low-risk set. It is a behavior-preserving refactor: each
candidate keeps its exact output and all domain math; only the *where* of the count/sort/page moves
from PHP into OR.

### The QueueService depth bug

`QueueService::getQueueDepth()` fetched `findAll(['limit' => 1])` and returned `count($results)` —
which **caps the reported depth at 1** regardless of how many items the queue actually holds. That
broke capacity / overflow decisions in `processOverflow()`. Pushing the depth down to
`ObjectService::count()` both removes the over-fetch and **fixes the bug** (the queue's real depth
is now returned). A previously-skipped unit test (issue #286 — "ObjectService API mismatch") is
un-skipped and rewritten to prove depth > 1.

### Latent OR-signature bugs fixed along the way

Several of these call sites used `findAll(register: ..., schema: ..., limit: ...)` — named arguments
that do **not** match OpenRegister's real `findAll(array $config = [])` signature, so they threw at
runtime and were silently swallowed by surrounding `catch` blocks (returning `0` / `[]`). Converting
them to the canonical `findAll(config: ['filters' => ...])` / `count(config: ['filters' => ...])`
shape both fixes the latent breakage and performs the pushdown.

### Legs that intentionally STAY in PHP (OR cannot express them)

OpenRegister's query engine has **no SUM/AVG aggregation facet** (only `terms`/`range`/`date_histogram`),
**no NOT IN operator**, and cannot sort on a computed/coalesced field. So:

- **LoyaltyReportingService** `getLiabilitySnapshot` SUM (with a per-account `max(0, …)` floor) and
  `getTierReport` group-count stay in PHP — SUM is unreachable and the `max(0)` floor / `unassigned`
  default cannot be reproduced server-side.
- **RapportageService** `filterByCreated` stays in PHP — it resolves `_dateCreated` with a fallback to
  `@self.created`, so an OR `created` range filter would wrongly drop rows whose object-level
  `_dateCreated` diverges from metadata.
- **PosCustomerLinkService** `getCustomerHistory` stays in PHP — it excludes `draft`/`parked` (a NOT IN)
  and sorts on a *computed* `createdAt` (`confirmedAt ?? settledAt ?? @self.created`); only the
  already-present `customer` equality filter is server-side.
- **RoutingService** open-**requests** leg stays in PHP — "non-terminal" is a NOT IN over
  case-folded statuses. Only the open-**leads** leg (pure equality filters) is pushed to `count()`.
- **ForecastExportService** `childOwners` distinct-owner dedupe stays in PHP — OR `distinct` is not
  reachable via the simple filter call path used here.

## What Changes

- **DELETE** the dead, unregistered `ComplaintSlaJob` (its `fetchComplaints()` was a `[]` stub; the
  real complaint-SLA work is done by the registered `SlaDeadlineSweepJob`) and its trivial test.
- **QueueService::getQueueDepth** → `ObjectService::count()` (bug-fix + pushdown).
- **PosRoleService::countActiveStaffForRole** → push the `posRole` filter to OR; keep the
  `isActive`-default count in PHP (preserves "missing flag = active"). Fix the latent named-arg bug in
  `listRoles` and `PosStaffService::listStaff` too.
- **BlastService::listBlasts / listDeliveriesForBlast** → `count()` for the total + `limit`/`offset`
  for the page (replaces fetch-all + `array_slice`); fix the latent named-arg bug in the loaders.
- **RoutingService::getAgentWorkload** (leads leg) → `count()`.
- **ForecastService::latestSnapshot / latestOverride** → OR `sort` DESC + `limit 1` (replaces
  fetch-all + `usort` + `[0]`).
- **ForecastExportService::exportSnapshots** → OR `sort` ASC + `limit`/`offset` + `count()` total.
- Add `count()` to the test-stub `ObjectService`; add/adjust unit tests; un-skip the depth test.

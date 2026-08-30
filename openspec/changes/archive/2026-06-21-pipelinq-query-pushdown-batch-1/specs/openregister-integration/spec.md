# OpenRegister Integration — Server-Side Query Pushdown Delta

**Spec refs**: `openregister-integration`, ADR-022 (apps consume OR abstractions)
**Standards**: OpenRegister API conventions (`count` / `findAll` config shape)

## ADDED Requirements

### Requirement: Server-Side Query Pushdown (Count / Sort / Paginate)

Backend services MUST push counting, sorting, and pagination down into OpenRegister's query engine
rather than fetching every matching object and counting / sorting / slicing in PHP. A service that
needs only a count MUST call `ObjectService::count(['filters' => …])`; a service that needs the most
recent or a page of objects MUST use `findAll`'s `sort`, `limit`, and `offset` config keys. Services
MUST call `ObjectService` with its real single-`$config`-array signature
(`findAll(config: […])` / `count(config: […])`), never the obsolete
`findAll(register: …, schema: …, limit: …)` positional/named form.

A predicate MAY remain in PHP **only** when OpenRegister's query engine cannot express it — namely
`SUM`/`AVG` aggregation, `NOT IN`, sorting on a computed/coalesced value, or `DISTINCT` over the
simple filter call path. Such a leg MUST carry an inline comment stating why it stays in PHP.

**Feature tier**: MVP

#### Scenario: Queue depth is counted server-side

- GIVEN a queue holding more than one request
- WHEN `QueueService::getQueueDepth()` is called
- THEN it MUST call `ObjectService::count()` filtered to that queue
- AND it MUST return the queue's true item count (NOT a value capped at 1 by a `limit: 1` fetch)

#### Scenario: Active-staff-for-role count pushes the role filter down

- GIVEN POS staff rows, some on the target role and some on other roles
- WHEN `PosRoleService::countActiveStaffForRole()` is called
- THEN the `posRole` equality MUST be applied server-side via `findAll(config:)`
- AND staff on other roles MUST NOT be fetched into PHP
- AND a staff row with a missing `isActive` field MUST still count as active (the `isActive` default stays in PHP)

#### Scenario: Latest forecast snapshot is selected by server-side sort + limit

- GIVEN multiple forecast snapshots for an owner / period / level
- WHEN `ForecastService::latestSnapshot()` resolves the most recent snapshot
- THEN it MUST request `sort` by `as_of_date` descending with `limit` 1
- AND it MUST NOT fetch every snapshot and sort them in PHP

#### Scenario: Paginated list uses a server-side total and page window

- GIVEN more matching objects than one page holds
- WHEN a paginated list (e.g. `BlastService::listBlasts`, `ForecastExportService::exportSnapshots`) is built
- THEN the total MUST come from `ObjectService::count()`
- AND the page MUST come from a `findAll` with `limit` and `offset`
- AND the service MUST NOT fetch the full result set and `array_slice` it in PHP

#### Scenario: An OR-unexpressible predicate stays in PHP with a stated reason

- GIVEN a predicate OpenRegister cannot express (e.g. a `NOT IN` over terminal statuses, or a `SUM` with a per-row floor)
- WHEN the service computes it
- THEN that specific leg MAY remain a PHP loop
- AND the code MUST carry an inline comment explaining why it cannot be pushed down

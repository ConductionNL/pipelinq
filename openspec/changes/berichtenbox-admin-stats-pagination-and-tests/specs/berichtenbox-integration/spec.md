## ADDED Requirements

### Requirement: Berichtenbox admin stats query MUST be bounded

The `GET /api/admin/berichtenbox/stats` endpoint (`BerichtenboxAdminController::stats()`) MUST NOT
issue an unbounded `ObjectService::findAll()` query (no `limit`) over the full
`berichtenboxMessage` register to compute delivery-status counters. The query MUST be paginated
(explicit `limit`/`offset`) or replaced by a server-side count/facet aggregation, so response time
and memory use do not grow linearly with the tenant's all-time message volume.

#### Scenario: Stats endpoint on a large register stays bounded

- **GIVEN** a `berichtenboxMessage` register containing more rows than one aggregation page
  (e.g. > 500)
- **WHEN** an admin calls `GET /api/admin/berichtenbox/stats`
- **THEN** the controller issues one or more bounded `ObjectService` calls, each carrying an
  explicit `limit`, rather than a single call with no `limit`
- **AND** the returned counters equal the counts that a full unbounded scan would have produced

@e2e exclude admin-only backend data-access change with no distinct UI surface (unchanged
response shape); verified by PHPUnit per tasks.md section 2.

#### Scenario: Empty register configuration short-circuits without a query

- **GIVEN** the app config's `register` or `berichtenboxMessage_schema` value is empty
- **WHEN** an admin calls `GET /api/admin/berichtenbox/stats`
- **THEN** the response is the zeroed counter shape with `unread: 0`
- **AND** no `ObjectService::findAll()` call is issued

@e2e exclude admin-only backend data-access change with no distinct UI surface (unchanged
response shape); verified by PHPUnit per tasks.md section 2.

### Requirement: Berichtenbox admin controller MUST have automated regression coverage

`BerichtenboxAdminController::stats()` and `BerichtenboxAdminController::retry()` MUST be covered
by unit tests exercising both their success and failure branches, so a future refactor of the
query or the retry-reset logic cannot silently regress without a failing test.

#### Scenario: stats() success and failure are both asserted

- **GIVEN** the `BerichtenboxAdminControllerTest` suite
- **WHEN** it runs against a stubbed `ObjectService` returning rows, and separately against one
  that throws
- **THEN** the success case asserts the correct per-status counter tally
- **AND** the failure case asserts an HTTP 500 JSON response carrying an `error` key

@e2e exclude PHPUnit-level regression coverage for an admin backend controller with no distinct
UI surface; see tasks.md 2.2-2.4.

#### Scenario: retry() success and failure are both asserted

- **GIVEN** the `BerichtenboxAdminControllerTest` suite
- **WHEN** `retry()` is called for a message id, and separately when the underlying `saveObject`
  call throws
- **THEN** the success case asserts `retryCount` is reset to 0, `nextRetryAt` is cleared, and
  `deliveryStatus` is set to `queued` before saving
- **AND** the failure case asserts an HTTP 500 JSON response carrying an `error` key

@e2e exclude PHPUnit-level regression coverage for an admin backend controller with no distinct
UI surface; see tasks.md 2.5-2.6.

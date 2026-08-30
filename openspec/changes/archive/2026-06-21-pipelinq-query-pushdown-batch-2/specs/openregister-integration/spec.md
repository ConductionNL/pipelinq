# OpenRegister Integration — Server-Side Aggregation Pushdown Delta

**Spec refs**: `openregister-integration`, ADR-022 (apps consume OR abstractions)
**Standards**: OpenRegister ad-hoc aggregation contract (`AggregationRunner::runAdhocByRef` / `AggregationQuery`)

## ADDED Requirements

### Requirement: Server-Side Aggregation Pushdown (Sum / Count / Group)

Backend money / reporting services MUST push `SUM` and grouped `COUNT` work down into OpenRegister's
ad-hoc aggregation runner rather than hydrating every matching object and reducing in PHP. A service
that needs a total over a column MUST call
`AggregationRunner::runAdhocByRef($registerRef, $schemaRef, AggregationQuery::create(metric: 'sum',
field: …, filter: …))`; a service that needs per-key counts MUST add `groupBy: ['field' => …]`. The
runner MUST be resolved from the DI container the same way `ObjectService` is. A pushed-down call
MUST degrade to the prior fallback (`0` / empty / the original PHP reduce) when OpenRegister is
unavailable, so the report still renders.

A computation MAY remain a PHP loop **only** when the OpenRegister aggregation contract cannot
express it — namely a per-row transformation before the aggregate (e.g. a `max(0, …)` floor or a
per-row sign that is not reducible to a status split), an aggregate over a value nested inside a
row's array property rather than a top-level column, a date window that is string-compared against a
differently-formatted stored timestamp, a coalesced/defaulted/derived bucket key, or a case-folded
`NOT IN`. Such a leg MUST carry an inline comment stating why it stays in PHP, and its numeric result
MUST NOT change.

**Feature tier**: MVP

#### Scenario: Confirmed-sales total is summed server-side over a date window

- GIVEN POS transactions with mixed statuses and confirmation timestamps
- WHEN `CashShiftService::sumConfirmedSales(from, to)` computes the in-window sales total
- THEN it MUST request `SUM(total)` via `runAdhocByRef` with `status IN (confirmed, settled)` and a `confirmedAt` `gte`/`lte` window
- AND it MUST NOT fetch every transaction and sum them in PHP
- AND the returned total MUST equal the prior PHP window-sum to the cent

#### Scenario: Account balance is summed server-side over the full ledger

- GIVEN a points ledger with credit, debit, and expiry entries for an account
- WHEN `PointsLedgerService::getAccountBalance(accountId)` computes the live balance
- THEN it MUST request `SUM(aantal)` via `runAdhocByRef` filtered by `accountId`
- AND an account with no ledger entries MUST balance to 0 (the runner's `null` result casts to 0)
- AND the returned balance MUST equal the prior sum over the full ledger history

#### Scenario: Tier distribution is counted server-side with a default bucket preserved

- GIVEN loyalty accounts for a programme, some with no `currentTierId`
- WHEN `LoyaltyReportingService::getTierReport(programmeId)` builds the per-tier counts
- THEN it MUST request a grouped `COUNT` by `currentTierId` via `runAdhocByRef`
- AND accounts with a missing or empty `currentTierId` MUST be folded into the `unassigned` bucket
- AND the per-tier counts MUST equal the prior PHP bucketing

#### Scenario: Per-staff sales reproduce refund netting via a signed split sum

- GIVEN final-status POS transactions (confirmed / settled / refunded) for several staff members
- WHEN `PosStaffReportService::staffSalesReport()` aggregates per staff member
- THEN the `transactionCount` MUST come from a grouped `COUNT` over all three final statuses
- AND each staff total MUST be `SUM(total | confirmed,settled) − SUM(total | refunded)` (and likewise for tax), reconstructing the per-row refund sign
- AND a transaction with an empty `staffMemberId` MUST be excluded
- AND the per-staff `transactionCount` / `total` / `totalTax` MUST equal the prior PHP reduce

#### Scenario: A per-row-floored or windowed-by-string sum stays in PHP with a stated reason

- GIVEN an outstanding-points liability sum that applies a per-account `max(0, …)` floor, or a ledger
  window that string-compares a stored timestamp OpenRegister normalises to a different format
- WHEN the service computes it
- THEN that specific leg MAY remain a PHP loop
- AND the code MUST carry an inline comment explaining why it cannot be pushed down
- AND its numeric result MUST be unchanged from before the refactor

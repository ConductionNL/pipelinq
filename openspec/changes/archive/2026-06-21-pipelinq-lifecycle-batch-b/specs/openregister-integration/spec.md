# OpenRegister Integration — Declarative Lifecycle State Machines (Money Paths) Delta

**Spec refs**: `openregister-integration`, ADR-031 (declarative-first), ADR-022 (apps consume OR abstractions)
**Standards**: OpenRegister `x-openregister-lifecycle` annotation + `LifecycleValidationListener` enforcement contract

## ADDED Requirements

### Requirement: Money-Path Object Lifecycle State Machines Are Schema-Declared

Revenue and booking services that gate an object's status transitions MUST declare the transition
graph in the schema's `configuration.x-openregister-lifecycle` annotation (ADR-031) as the single
source of truth, rather than a hardcoded PHP adjacency map. OpenRegister's
`LifecycleValidationListener` enforces the declared graph automatically on every
`ObjectService::saveObject()`. A thin PHP guard MAY be retained, but it MUST derive its
allowed-transition set (and terminal set) from the schema declaration, and it is retained ONLY to
preserve an existing error contract (exception type, message, HTTP status) or pre-save validation.

The migration MUST be strictly behavior-preserving: no transition legal before the change becomes
illegal, and none illegal before becomes legal. A conditional predicate MAY remain in a PHP guard
ONLY when the declarative grammar cannot express it (engine-only origin, "requires a related won
lead", "requires a non-empty reason", date-range coherence), and MUST carry an inline comment.

When a schema needs a **second** independent state machine on a different field, and OpenRegister's
single enforced lifecycle field is already claimed by another field, that second machine MUST NOT be
declared as a second `x-openregister-lifecycle` (OpenRegister enforces only one lifecycle field per
schema). It MUST instead be declared under an app-namespaced `configuration` key and enforced by the
app, with the schema annotation remaining the source of truth for its state partition.

**Feature tier**: MVP

#### Scenario: Contract transitions derive terminal set and graph from the schema

- GIVEN the contract schema declares `x-openregister-lifecycle` on `status` with
  `terminal: [renewed, churned, cancelled]` and transitions covering every reachable
  non-terminal→target edge the service permits today
- WHEN `ContractService::assertTransitionAllowed(['status' => 'active'], 'expiring', byEngine: true)` is called
- THEN it MUST pass, having read the terminal set + adjacency from the schema declaration
- AND `assertTransitionAllowed(['status' => 'churned'], 'active')` MUST throw `InvalidArgumentException`
  reporting the terminal state (terminal set sourced from the schema)
- AND `assertTransitionAllowed(['status' => 'active'], 'renewed')` without a won renewal lead MUST still
  throw `InvalidArgumentException` with the existing "requires a won renewal lead" message (a conditional
  predicate kept in PHP)
- AND a `saveObject()` that flips a `renewed` (terminal) contract's status back to `active` MUST be
  rejected by OpenRegister's `LifecycleValidationListener` as defense-in-depth

#### Scenario: Booking state machine is sourced from the schema and preserves its messages

- GIVEN the booking schema declares `x-openregister-lifecycle` on `status` mirroring the prior
  `allowedTransitions()` map (pending-deposit/confirmed sources; completed/no-show/cancelled-*/rescheduled terminal)
- WHEN `BookingService::allowedTransitions()` is read
- THEN it MUST equal the prior hardcoded map, having been derived from the schema declaration
- AND `BookingService::assertTransitionAllowed('pending-deposit', 'confirmed')` MUST pass
- AND `assertTransitionAllowed('confirmed', 'pending-deposit')` MUST throw `InvalidArgumentException`
  with the existing "Invalid status transition" message
- AND `assertTransitionAllowed('completed', 'confirmed')` MUST be rejected (terminal state has no outgoing edge)

#### Scenario: Forecast-category partition moves to the schema but enforcement stays in PHP

- GIVEN the lead schema already declares `x-openregister-lifecycle` on `status`, and OpenRegister enforces
  only one lifecycle field per schema
- AND the forecast-category open/closed partition and default are declared under the app-namespaced
  `configuration.x-pipelinq-forecast-lifecycle` key on the lead schema
- WHEN `ForecastDealService::validateTransition()` moves a `closed_won` deal to an open category
- THEN it MUST be rejected with `forecast.error.closed_deal_locked`, having read the open/closed partition
  from the schema annotation (not a hardcoded constant)
- AND the `DealUpdatedListener` revert-after-write behavior MUST be unchanged (OpenRegister's listener does
  NOT enforce this second field)
- AND a new deal created without a `forecast_category` MUST default to `pipeline` via the schema property
  `default` (`defaultBehavior: "falsy"`), with `DealCreatedListener` retained as an idempotent backstop

# Design: pos-lifecycle-guard-adoption

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Lands as | Why |
|---|---|---|
| posTransaction state machine (draft→parked→confirmed→settled→refunded) | **Declarative** `x-openregister-lifecycle` | ADR-031 default. OR's engine owns the transition table, audit trail, `ObjectTransitionedEvent`, and invalid-transition rejection. |
| posRefund state machine (pending→completed/rejected) | **Declarative** `x-openregister-lifecycle` | Same. |
| cashier-owner OR pos-group OR admin authorization on confirm/settle/park/resume | **Guard** (`requires`) | ADR-031 §"PHP guards remain a legitimate seam". OR's engine enforces `update` RBAC + the `requires` guard; it does NOT itself interpret the `authorization:{field,groups}` block, so the field/group rule must run in the guard. This is the seam that closes the IDOR. |
| pos_managers OR admin authorization on refund + posRefund complete/reject | **Guard** | Same seam. |
| Server-authoritative money RECOMPUTE on confirm (computeTotals) | **Guard precondition** + post-transition recompute | ADR-031 Exception (2): a calculation that derives totals from child line objects and must run as a transition precondition is a legitimate guard, not expressible as a pure schema declaration. |
| Cumulative OVER-REFUND CAP + proportional recompute on posRefund complete | **Guard precondition** | ADR-031 Exception (2): spans posRefund + every prior completed posRefund + the original posTransaction; a cross-object invariant the schema engine cannot model. |
| Non-empty-cart precondition on confirm | **Guard precondition** | Cross-object (counts child posTransactionLine rows). |

Net: lifecycle → declarative; authorization + money/cap invariants → thin
single-method PHP guards called *by* the engine. No bespoke state machine, no
`setStatus`, no hand-rolled `isManager` survives.

## How OR enforces this (contracts consumed)

- `OCA\OpenRegister\Service\Lifecycle\TransitionEngine::transition($objectId, $action): ObjectEntity`
  — loads the object, enforces per-object `update` RBAC via `PermissionHandler`,
  validates the `from`→`to` move against the schema's `x-openregister-lifecycle`,
  saves through `ObjectService::saveObject()` (which fires `ObjectUpdatingEvent`),
  and dispatches the typed `ObjectTransitionedEvent` (audit + notifications).
- `OCA\OpenRegister\Listener\LifecycleValidationListener` — on `ObjectUpdatingEvent`,
  re-validates the transition and, when the matched transition declares
  `requires: <tag>`, resolves the guard via `LifecycleGuardRegistry` and calls
  `$guard->check($newObject, $action, $userId)`. A `GuardResult::deny(...)`
  stops propagation and stamps a structured error → surfaced as 403/422. The
  registry is **fail-closed**: a transition that references an unregistered
  guard tag cannot proceed.
- `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface::check(array $object, string $action, string $userId): GuardResult`
  — the contract our guards implement. `GuardResult::allow()` /
  `GuardResult::deny($message)`.

Because the guard runs inside `saveObject()` regardless of which controller
called, an attacker who POSTs `/confirm` on someone else's transaction UUID is
denied by the guard (not their cashier, not in the pos group, not admin) — the
IDOR is closed at the data layer, not just the controller.

## Why the guard, not just `authorization:{field,groups}`

OR's `TransitionEngine` and `LifecycleValidationListener` enforce two things:
the object-level `update` RBAC verdict and the transition's `requires` guard.
They do **not** read the descriptive `authorization:{field,groups}` sub-object
that the lead/request transitions carry (that block documents intent for
humans + future tooling). Pipelinq objects are not owner-partitioned in OR's
default RBAC (any authed user can `update` through the generic API — that IS
the IDOR root cause). Therefore the cashier-owner/group/admin rule MUST be
enforced in a `requires` guard to actually close the hole. We keep the
descriptive `authorization` block for parity/readability AND wire a guard that
enforces it.

## Guard map

| Schema.transition | requires tag | Guard responsibility |
|---|---|---|
| posTransaction.confirm | `OCA\Pipelinq\Lifecycle\PosTransactionConfirmGuard` | owner/group/admin + non-empty cart + recompute totals |
| posTransaction.settle | `OCA\Pipelinq\Lifecycle\PosTransactionAccessGuard` | owner/group/admin |
| posTransaction.park | `OCA\Pipelinq\Lifecycle\PosTransactionAccessGuard` | owner/group/admin |
| posTransaction.resume | `OCA\Pipelinq\Lifecycle\PosTransactionAccessGuard` | owner/group/admin |
| posTransaction.refund | `OCA\Pipelinq\Lifecycle\PosTransactionRefundGuard` | pos_managers/admin |
| posRefund.complete | `OCA\Pipelinq\Lifecycle\PosRefundManagerGuard` | pos_managers/admin + over-refund cap + recompute |
| posRefund.reject | `OCA\Pipelinq\Lifecycle\PosRefundManagerGuard` | pos_managers/admin |

`PosRefundManagerGuard` branches on `$action`: `reject` only checks manager;
`complete` additionally runs the cap + recompute.

## Service refactor

`PosTransactionService` keeps `computeTotals` / `recalculateTotals` /
`buildTaxReport` / `recalculateLine` (the calc core — used by the guard and the
report) and its OR persistence helpers. The five transition methods collapse to
thin wrappers that (a) recompute+persist totals where required, then (b) call
`TransitionEngine::transition($id, $action)` and return the saved object as an
array. The hand-rolled `isManager` is deleted (the guard owns the verdict);
`IGroupManager` moves into the guards.

`PosRefundService` mirrors this: `recalculateTotals` + `sumCompletedRefunds` +
event emitters stay; `confirmRefund`/`rejectRefund` become wrappers over
`TransitionEngine::transition($id, 'complete'|'reject')`; `isManager` deleted.

## Endpoint authorization (non-transition)

A shared `PosAccessGuard` helper (cashier-owner OR pos-group OR admin, fail
closed) is invoked at the top of receipt preview/email/print and the product
endpoints; `taxReport` requires pos_managers/admin. These are not lifecycle
transitions, so they call the access check directly (the same predicate the
lifecycle guards use), not the engine.

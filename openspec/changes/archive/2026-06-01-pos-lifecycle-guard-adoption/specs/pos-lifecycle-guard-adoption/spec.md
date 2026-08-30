# POS Lifecycle Guard Adoption

**Status**: active
**Capability**: pos-lifecycle-guard-adoption
**Schema.org mapping**: `schema:Order` (posTransaction, posRefund)

**OpenSpec changes**:
- `pos-lifecycle-guard-adoption` — Move the POS transaction + refund lifecycles
  onto OpenRegister's declarative `x-openregister-lifecycle` machinery, route
  every state transition through `TransitionEngine`, and enforce per-object
  authorization in `LifecycleGuardInterface` guards — closing the confirmed
  IDOR and removing the bespoke PHP state machines + hand-rolled `isManager`.

---

## Requirements

### REQ-PLG-001: Declarative POS lifecycle

The `posTransaction` and `posRefund` schemas MUST declare their state machine
via `x-openregister-lifecycle` instead of a bespoke PHP state machine.

#### Scenario: posTransaction transitions are declarative

- GIVEN the `pipelinq_register.json` register file
- THEN `posTransaction` MUST declare `x-openregister-lifecycle` with field
  `status` and transitions `confirm` (draft,parked→confirmed), `settle`
  (confirmed→settled), `park` (draft→parked), `resume` (parked→draft),
  `refund` (confirmed,settled→refunded)
- AND each transition MUST name a `requires` guard tag

#### Scenario: posRefund transitions are declarative

- GIVEN the register file
- THEN `posRefund` MUST declare `x-openregister-lifecycle` with field `status`
  and transitions `complete` (pending→completed) and `reject` (pending→rejected),
  each naming a `requires` guard tag

### REQ-PLG-002: Transitions route through OpenRegister TransitionEngine

Every POS state transition MUST be applied via
`OCA\OpenRegister\Service\Lifecycle\TransitionEngine::transition()`, not by a
bespoke `setStatus`-style mutation.

#### Scenario: Confirm routes through the engine

- GIVEN a cashier confirms their own draft transaction with a non-empty cart
- WHEN `PosTransactionController::confirm` runs
- THEN the service MUST call `TransitionEngine::transition($id, 'confirm')`
- AND the resulting object MUST have `status = confirmed`
- AND an `ObjectTransitionedEvent` MUST be dispatched by the engine

### REQ-PLG-003: IDOR closed by per-object lifecycle guard

A POS state transition MUST be denied for a caller who is neither the
transaction's cashier, nor a member of the POS group, nor a Nextcloud admin.

#### Scenario: Non-owner is denied confirm

- GIVEN transaction T owned by cashier A (`cashier = "A"`)
- AND a different user B who is not in the POS group and not an admin
- WHEN B invokes confirm/settle/park/resume on T
- THEN the `LifecycleGuardInterface` guard MUST return `GuardResult::deny(...)`
- AND the transition MUST NOT be applied (HTTP 403)

#### Scenario: POS-group member is allowed

- GIVEN transaction T owned by cashier A
- AND a user C who is a member of the configured POS group
- WHEN C invokes settle on a confirmed T
- THEN the guard MUST allow the transition

### REQ-PLG-004: Manager-gated refund + posRefund completion

`refund` on a posTransaction and `complete`/`reject` on a posRefund MUST be
denied for any caller who is not a POS manager (member of the configured
manager group) or a Nextcloud admin.

#### Scenario: Non-manager is denied refund

- GIVEN a non-manager, non-admin user
- WHEN they invoke refund on a confirmed transaction
- THEN the refund guard MUST deny the transition (HTTP 403)

#### Scenario: Over-refund cap preserved

- GIVEN a posRefund whose gross plus all prior completed refunds for the same
  original transaction would exceed the original total
- WHEN a manager completes the refund
- THEN `PosRefundManagerGuard` MUST deny the completion

### REQ-PLG-005: Server-authoritative money preserved

Monetary totals MUST remain server-computed from the persisted line items; the
confirm guard MUST recompute totals before the transition is applied.

#### Scenario: Totals recomputed on confirm

- GIVEN a draft transaction with line items
- WHEN it is confirmed
- THEN `computeTotals` MUST be re-run server-side and persisted, ignoring any
  client-supplied subtotal/tax/total

### REQ-PLG-006: Non-transition POS endpoints are object-scoped

`PosReceiptController::preview/email/print`, the product price/barcode lookup,
and `PosTransactionController::taxReport` MUST enforce authorization beyond bare
session auth.

#### Scenario: Receipt actions require object access

- GIVEN a transaction owned by cashier A
- WHEN a non-owner, non-group, non-admin user requests its receipt
  preview/email/print
- THEN the request MUST be rejected with HTTP 403

#### Scenario: Tax report is manager-only

- GIVEN a non-manager, non-admin user
- WHEN they request the cross-object BTW tax report
- THEN the request MUST be rejected with HTTP 403

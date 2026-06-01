# Proposal: pos-lifecycle-guard-adoption

## Problem

Two confirmed defects shipped in the merged POS suite (pos-transaction-core,
pos-refund-return, pos-receipt-engine, pos-product-catalogue):

1. **IDOR (ADR-005, HIGH).** `PosTransactionController::confirm/settle/park/resume`
   are `#[NoAdminRequired]` and only call `requireUserId()`. The backing
   `PosTransactionService` methods receive `$userId` but use it **only for
   logging** — there is no per-object ownership or group check. Any
   authenticated user can therefore drive ANY transaction by guessing its
   UUID (confirm someone else's cart, settle it, park/resume it). The same
   shape applies to the non-transition read/send endpoints
   (`PosReceiptController::preview/email/print`,
   `ProductCatalogController`, `PosTransactionController::taxReport`): they
   act on an object (or an aggregate report) with only session auth.
   `refund` IS manager-gated, but the four state-advancing actions are not.

2. **Bespoke lifecycle (ADR-031, HIGH).** `posTransaction`
   (draft/parked/confirmed/settled/refunded) and `posRefund`
   (pending/completed/rejected) declare `status` as a bare enum and drive
   transitions with hand-written PHP (`setStatus`-style mutation + manual
   `in_array($status, …)` precondition + a hand-rolled `isManager`), while
   the SAME register file already declares `x-openregister-lifecycle`
   declaratively on `lead`, `request`, `complaint`, and `task`. OpenRegister
   ships a `TransitionEngine` + `LifecycleGuardInterface` +
   `LifecycleValidationListener` that enforce transitions, run guards, fire
   `ObjectTransitionedEvent` (audit + notifications free), and reject invalid
   transitions — none of which the bespoke POS state machines inherit.

## Solution

Move the POS lifecycle to OpenRegister's declarative machinery and route every
state transition through `TransitionEngine`, closing the IDOR as a direct
consequence (the engine + a per-object guard authorize server-side regardless
of caller), per ADR-022 / ADR-031 / ADR-005.

1. **Declarative lifecycle.** Add `x-openregister-lifecycle` to `posTransaction`
   and `posRefund` in `lib/Settings/pipelinq_register.json`, mirroring the
   lead/request shape, with `confirm/settle/park/resume/refund` on
   posTransaction and `complete/reject` on posRefund. Each guarded transition
   names a `requires` guard tag.

2. **Custom guards** (`LifecycleGuardInterface`) under `lib/Lifecycle/`:
   - `PosTransactionConfirmGuard` — cashier-owner OR pos-group OR admin AND a
     server-authoritative money RECOMPUTE + non-empty-cart precondition.
   - `PosTransactionAccessGuard` — cashier-owner OR pos-group OR admin (used by
     settle/park/resume).
   - `PosTransactionRefundGuard` / `PosRefundManagerGuard` — pos_managers OR
     admin (refund, posRefund complete/reject), plus the cumulative
     over-refund cap + proportional recompute for posRefund complete.

   Guards are registered via `Application::register()` `registerService()`
   keyed by their FQCN tag (the tag the schema references), fail-closed.

3. **Route transitions through OR.** Refactor `PosTransactionController` +
   `PosTransactionService` and `PosRefundController` + `PosRefundService` so
   confirm/settle/park/resume/refund and posRefund complete/reject call OR's
   `TransitionEngine::transition()`. Delete the now-redundant `isManager` and
   manual `setStatus`/precondition bodies; keep `computeTotals` /
   `recalculateTotals` as the guard's calc core.

4. **Secure non-transition endpoints.** Add a per-object access check
   (cashier-owner OR pos-group OR admin) to
   `PosReceiptController::preview/email/print` and the product price/barcode
   surface, and restrict `taxReport` (a cross-object report) to
   pos_managers/admin. Throw `OCSForbiddenException` otherwise.

## Scope

- `x-openregister-lifecycle` blocks on `posTransaction` + `posRefund`.
- 4 lifecycle guards under `lib/Lifecycle/` + DI registration.
- Controller/service refactor to route through `TransitionEngine`.
- Per-object authorization on the receipt + product + taxReport endpoints.
- PHPUnit proving a non-owner non-group user is DENIED confirm/settle/park/
  resume (IDOR closed), manager-group required for refund + posRefund
  complete, over-refund cap preserved, money still server-recomputed.
- ADR-031 "Declarative-vs-imperative decision" section in design.md.

## Out of scope

- The posTransaction lifecycle Vue UI keeps its existing buttons (behaviour
  preserved for legitimate users).
- No changes to OpenRegister (we consume its contracts).

# Tasks: pos-lifecycle-guard-adoption

## 1. Declarative lifecycle (register patch)

- [x] 1.1 Add `x-openregister-lifecycle` to `posTransaction` in
  `lib/Settings/pipelinq_register.json`: field `status`; transitions
  `confirm` (draft,parked→confirmed, requires PosTransactionConfirmGuard),
  `settle` (confirmed→settled, requires PosTransactionAccessGuard),
  `park` (draft→parked, requires PosTransactionAccessGuard),
  `resume` (parked→draft, requires PosTransactionAccessGuard),
  `refund` (confirmed,settled→refunded, requires PosTransactionRefundGuard).
  Carry the descriptive `authorization` block on each.
- [x] 1.2 Add `x-openregister-lifecycle` to `posRefund`: field `status`;
  transitions `complete` (pending→completed, requires PosRefundManagerGuard),
  `reject` (pending→rejected, requires PosRefundManagerGuard).

## 2. Lifecycle guards (lib/Lifecycle/)

- [x] 2.1 `PosAccessPolicy` — shared owner/group/admin + manager predicate.
- [x] 2.2 `PosTransactionAccessGuard implements LifecycleGuardInterface` —
  cashier-owner OR pos-group OR admin (settle/park/resume).
- [x] 2.3 `PosTransactionConfirmGuard implements LifecycleGuardInterface` —
  access policy + non-empty cart + server-authoritative recompute.
- [x] 2.4 `PosTransactionRefundGuard implements LifecycleGuardInterface` —
  pos_managers/admin.
- [x] 2.5 `PosRefundManagerGuard implements LifecycleGuardInterface` —
  pos_managers/admin; on `complete` also enforce cumulative over-refund cap +
  proportional recompute.
- [x] 2.6 Register all guards (+ PosAccessPolicy) via
  `Application::register()` `registerService()` keyed by FQCN.

## 3. Route transitions through TransitionEngine

- [x] 3.1 `PosTransactionService`: collapse confirm/settle/park/resume/refund to
  thin wrappers over `TransitionEngine::transition()`. Delete `isManager` +
  manual status mutation. Keep computeTotals/recalculateTotals/buildTaxReport.
- [x] 3.2 `PosRefundService`: collapse confirmRefund/rejectRefund to wrappers
  over `TransitionEngine::transition($id,'complete'|'reject')`. Delete
  `isManager`. Keep recalculateTotals/sumCompletedRefunds/event emitters.
- [x] 3.3 Map `NotAuthorizedException` / engine `RuntimeException` to the
  correct OCS exceptions in both controllers.

## 4. Secure non-transition endpoints

- [x] 4.1 `ReceiptDeliveryService::preview/email/print` — assert per-object
  access (owner/group/admin) before acting; throw OCSForbiddenException.
- [x] 4.2 `ProductCatalogController` price/barcode — require an authed
  pos-group/owner/admin caller (already authed; add the access predicate).
- [x] 4.3 `PosTransactionController::taxReport` — restrict to pos_managers/admin.

## 5. Tests

- [x] 5.1 Guard unit tests: non-owner non-group user DENIED on confirm/settle/
  park/resume (IDOR closed); pos-group member ALLOWED; admin ALLOWED.
- [x] 5.2 Manager guard tests: non-manager DENIED refund + posRefund complete/
  reject; manager/admin ALLOWED.
- [x] 5.3 Over-refund cap still enforced via PosRefundManagerGuard.
- [x] 5.4 Money still server-recomputed (computeTotals unchanged + confirm guard
  recompute).
- [x] 5.5 Keep the full suite green.

## 6. Design doc

- [x] 6.1 ADR-031 Declarative-vs-imperative decision section (done in design.md).

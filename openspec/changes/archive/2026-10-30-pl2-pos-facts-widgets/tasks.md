# Tasks: pl2-pos-facts-widgets

### Task 1: The payment provider as a facts card
- **spec_ref**: `openspec/changes/pl2-pos-facts-widgets/specs/pos-payment-methods/spec.md#requirement-a-failed-payment-can-be-tried-again-from-the-transaction`
- **files**: `src/manifest.json`, `src/components/pos/PosTransactionPaymentWidget.vue`
- [x] Implement: `pos-tx-provider` facts with Complete, Try again and Reverse; PaymentStatusCard and ReversalReasonDialog deleted
- [x] Test: `tests/vitest/posFactsWidgets.spec.js`

### Task 2: The cash difference as a facts card
- **spec_ref**: `openspec/changes/pl2-pos-facts-widgets/specs/pos-cash-management/spec.md#requirement-the-shift-shows-its-latest-cash-difference`
- **files**: `src/manifest.json`, `src/registry.js`
- [x] Implement: `shift-variance` facts over the latest cashDiff with Approve and Reject; CashShiftVarianceWidget and CashShiftRejectDialog deleted
- [x] Test: `tests/vitest/posFactsWidgets.spec.js`

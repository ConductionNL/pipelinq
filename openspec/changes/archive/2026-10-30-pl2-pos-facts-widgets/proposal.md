---
kind: code
depends_on: [pl2-pos-bookings-server-rules]
---

# Proposal: pl2-pos-facts-widgets

## Summary

The payment-provider card on a POS transaction and the cash difference on a shift become `facts` widgets in the
manifest, with their actions declared. Two pipelinq components and their two dialogs go.

- **Payment provider.** `PaymentStatusCard` drew the provider, method, session and status, with Complete, Reverse and
  Try again. Its Try again emitted an event nothing listened to, so a failed payment could not be retried. The facts card
  `pos-tx-provider` shows the same facts with the status in the card header, and its actions call the existing payment
  endpoints: Complete captures, Try again starts the payment again with the provider and method the transaction holds,
  Reverse asks for a reason (the server refuses an empty one) and is shown to admins only.
- **Cash difference.** `CashShiftVarianceWidget` read the shift's cash differences and showed one. The facts card reads the
  shift's latest difference by query, shows the amounts as money, the percentage and the tolerance pill, and offers Approve
  and Reject to admins while the difference is pending.

The board PqKassabon draws "Betaling" as exactly such a card: a status pill, the provider and the method.

## Dependency

The facts `actions`, the query `source`, `suffix`, `format: "number"` and boolean pill tones come from nextcloud-vue
`feature/pl-abstract-pl2-facts` (CnFactsWidget). This change lands once that is released and pipelinq's
`@conduction/nextcloud-vue` is bumped to it.

## Scope

- `src/manifest.json`: `pos-tx-provider` on PosTransactionDetail, `shift-variance` as `facts` on CashShiftDetail.
- `src/components/pos/PosTransactionPaymentWidget.vue`: tenders only.
- Deleted: `PaymentStatusCard.vue`, `dialogs/ReversalReasonDialog.vue`, `CashShiftVarianceWidget.vue`,
  `modals/CashShiftRejectDialog.vue`, and the `CashShiftVarianceWidget` registry key.
- `l10n/`.

---
kind: code
---

# Proposal: pl2-pos-header-actions

## Summary

The POS transaction, POS refund and cash shift pages drew their header buttons with three pipelinq components
(PosTransactionHeaderActions, PosRefundHeaderActions, CashShiftHeaderActions) and three dialogs. They become declared
`headerActions`, following Ruben's answer to PL-ABSTRACT question 9 and the boards PqKassabon and PqKassadienst: one
primary button per state, the receipts and Register refund as quick actions, the rest in the Actions menu.

| Page | Primary by state | Quick actions | In the menu |
|---|---|---|---|
| POS transaction | draft: Confirm (only with lines), parked: Resume, confirmed: Check out | Print receipt, Email receipt, Register refund | Edit line items, Park, Reverse (admin, with a reason) |
| POS refund | pending: Confirm (admin) | | Reject (admin, with a reason) |
| Cash shift | open: Close and count shift | | |

The receipt modals read the active receipt templates themselves and close after a print or a mail, so a declared
`open-modal` can open them. A count without an amount used to close the shift at 0; the endpoint now refuses it with 422.

## Dependency

Endpoint forms without a create schema, `required` form fields and admin-only primary buttons come from nextcloud-vue
`feature/pl-abstract-pl2-actions` (manifest schema 2.140.0). This change lands once that is released and pipelinq is
bumped to it.

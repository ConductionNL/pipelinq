# Proposal: pos-refund-return

## Problem

The pos-transaction-core module supports a `refunded` status on transactions and a `refundReason` field, but the refund workflow itself is not implemented. There is no way for cashiers to:
- Issue a partial refund (one or more line items from a transaction)
- Issue a full refund (entire transaction)
- Specify a structured reason code for the refund
- Reverse the payment to the original tender method
- Trigger stock back-in when items are returned
- Emit accounting reversal events to Shillinq

Every surveyed competitor (13/13) implements refunds and returns as a foundational POS feature. Without it, customers cannot be refunded for damaged goods, unwanted items, or errors, and there is no audit trail of the refund. Stock levels become inaccurate for returned items.

## Solution

Implement the POS Refund + Return workflow with two new OpenRegister schemas and supporting backend/frontend:

1. **posRefund schema** (`schema:Order` with `@type refund`) — refund/return document with
   lifecycle (pending → completed / rejected), reference number, linked original transaction,
   reason code, refund amount, and payment reversal method
2. **posRefundLine schema** (`schema:OrderItem`) — individual line items being refunded from
   the original transaction, with returned quantity, refund reason, and restock flag
3. **Refund reason catalog** — configurable list of standard refund reasons (damaged, unwanted,
   error, exchange, expired, other)
4. **Refund workflow** — Create refund from transaction detail → select items and quantities →
   confirm → emit reversal event to Shillinq + stock movement event
5. **Tender reversal** — Capture original tender method; on completion, emit reversal to payment
   processor (or note for manual processing)
6. **Stock back-in** — When restock flag is true on a returned line, emit `shillinq.StockMovement`
   event with negative quantity to restore inventory
7. **Audit trail** — Full edit history, who approved the refund, when it was completed

## Scope

- `posRefund` and `posRefundLine` schemas added to `pipelinq_register.json` with seed data
  (3–5 objects each)
- Refund reason configuration entity (bundled seed data, e.g., 6 standard reasons)
- Refund list view: filter by original transaction, status, date range, search
- Refund detail view: original transaction details, refund line items table with returned qty,
  reason per line, restock flags, lifecycle buttons
- Refund form: create from transaction detail; select items to refund with qty/reason;
  edit existing draft refunds
- **Confirm refund**: validates non-empty refund, recalculates refund amount, sets `status=completed`,
  emits `pipelinq.TransactionRefund.completed` CloudEvent and `shillinq.StockMovement` events
  for restocked items
- **Reject refund**: cashier/manager can reject a refund (pending only)
- Backend `PosRefundController` + `PosRefundService` for lifecycle transitions and CloudEvent dispatch
- Navigation entry "Retouren" in Pipelinq POS section

## Out of scope

- Physical return inbound logistics (tracking shipment, receiving inspection) — V1
- Partial credit (store credit / gift card issuance instead of tender reversal) — V1
- Refund reason analytics dashboard — V1
- Multi-currency refund handling — V1
- Automated payment processor integration (Adyen, CCV, Worldline) — Enterprise
- Warranty claim processing — Enterprise

## Impact

- **Users**: Cashiers can issue refunds and process returns for the first time; customers get refunds
- **Inventory**: Shillinq receives `shillinq.StockMovement` events for items marked for restock,
  keeping inventory accurate
- **Accounting**: Shillinq receives refund reversal events to auto-create offsetting journal entries
- **Audit**: Full history of every refund with reason, cashier, and completion timestamp
- **Navigation**: New "Retouren" section in POS, linked from transaction detail

## Dependencies

- **pos-transaction-core** — refund is issued against an existing posTransaction; depends on
  posTransaction and posTransactionLine schemas, and the transaction lifecycle model
- **Shillinq** — must be configured to subscribe to `pipelinq.TransactionRefund.completed`
  and `shillinq.StockMovement` events
- **OpenRegister** — `posTransaction`, `posTransactionLine` schemas already defined in V1;
  new `posRefund` and `posRefundLine` schemas use same pattern

## Demand Evidence

Market intelligence research (dated 2026-05-20) shows refund/return capability in all 13 surveyed competitors:
- chromis-pos :: Refund + void with permission :: Manager override for refund/void
- dvi-salonsoftware :: Correctiefactuur + retourbon :: Correctie + creditfactuur NL-compliant
- erpnext-pos :: Return invoice with stock back-in :: Negative invoice; stock + GL reversed
- korona-cloud :: Returns, exchanges, partial refund :: Refund original tender; reason codes
- lightspeed-retail :: Returns :: Return item, credit to account
- odoo-pos :: Refund + return to original journal :: Refund line items; auto stock return; correct VAT
- salonkee-pos :: Refund + credit note :: Refund to original tender; credit note
- shopify-pos :: Returns with QR code / order lookup :: Online order returned in-store; refund to original tender
- square-pos :: Refund + partial refund to original tender :: Refund line item or whole ticket, EOD reconcile
- toast-pos :: Return management :: Item return, restock, refund journal entry

This feature is P0-must for competitive parity and customer satisfaction.

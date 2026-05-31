# Proposal: pos-transaction-core

## Problem

Pipelinq has no POS transaction capability. There is no cart entity, no line items with
quantities and discounts, no tax breakdown, and no transaction lifecycle
(draft → confirmed → settled / refunded). Every surveyed competitor (13/13) provides this
as a foundational feature of a POS module. Without it, cashiers cannot register sales,
totals cannot be computed, and no events can be emitted to Shillinq for accounting
journal entries.

## Solution

Implement the POS Transaction Core with two new OpenRegister schemas and supporting views:

1. **posTransaction schema** (`schema:Order`) — cart entity with lifecycle status
   (draft / parked / confirmed / settled / refunded), subtotal, total discount, per-rate
   tax breakdown, and grand total
2. **posTransactionLine schema** (`schema:OrderItem`) — line items with quantity, unit price,
   discount percentage, tax rate, and computed line total; optional link to the product catalog
3. **Transaction views** — list, detail, and form/editor views with real-time total
   recalculation as lines are added or modified
4. **Lifecycle actions** — Confirm, Settle, Refund / Void; Refund requires manager permission
5. **Park / resume** — park a draft transaction to hold the cart and resume it later
6. **CloudEvent emission** — emit `pipelinq.PosTransaction.confirmed` on confirmation,
   consumed by Shillinq to draft a journal entry

## Scope

- `posTransaction` and `posTransactionLine` schemas added to `pipelinq_register.json`
  with seed data (3–5 objects each)
- POS transaction list view: status filter, date range, cashier filter, search
- Transaction detail view: line items table, per-rate tax breakdown panel, lifecycle buttons
- Line item editor: product picker (catalog), free-text description fallback,
  qty / discount / tax inputs, real-time totals panel
- **Confirm**: validates non-empty cart, computes final totals, sets `confirmedAt`,
  emits `pipelinq.PosTransaction.confirmed` CloudEvent
- **Settle**: moves confirmed → settled, sets `settledAt`
- **Refund / Void**: manager-only; moves settled / confirmed → refunded, stores reason code
- **Park**: saves draft with `parkedAt`; parked transactions appear on the list for resume
- Backend `PosTransactionController` + `PosTransactionService` for lifecycle transitions
  and CloudEvent dispatch
- Navigation entry "Kassabon" in Pipelinq sidebar

## Out of scope

- Payment terminal integration (Adyen, CCV, Worldline) — V1
- Multi-tender split payment per transaction — V1
- Cash management (float, mid-shift drops, blind count) — V1
- Receipt printing (thermal / PDF) — V1
- Fiscal compliance (RKSV / NF525 / fiscal tape) — Enterprise
- Return with stock back-in (requires inventory integration) — V1
- Charge to room / folio routing (requires PMS integration) — Enterprise
- Discount codes / promotion engine — V1

## Impact

- **Users**: Cashiers can register sales for the first time in Pipelinq
- **Shillinq**: Receives `pipelinq.PosTransaction.confirmed` events to draft journal entries
- **Product catalog**: `product` entities become linkable from POS line items
- **Client**: Optional client reference on transactions enables loyalty / account sale flows
- **Navigation**: New "Kassabon" section in the main app sidebar

## Dependencies

- **OpenRegister** — `product` and `client` schemas already defined in `pipelinq_register.json`
- **WebhookService** — available in OpenRegister for CloudEvent dispatch
- **Shillinq** — must be configured to subscribe to `pipelinq.PosTransaction.confirmed`

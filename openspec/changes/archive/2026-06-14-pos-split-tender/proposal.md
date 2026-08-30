# Proposal: POS Multi-tender Payment (cash + card + voucher)

## Problem

Current POS transaction model (`posTransaction`, `posTransactionLine`) supports only a single payment method per transaction. Retail operations require accepting multiple tender types on a single sale: customer pays partial amount in cash, remainder on card; or customer splits between cash, gift card, and voucher simultaneously. Without multi-tender support, sales requiring split payments cannot be completed or must be artificially split into multiple transactions.

## Solution

Add multi-tender payment support:

1. **New `posTender` entity**: represents a single payment method on a transaction (e.g., €50 cash, €30 card). Each tender references its type, amount, and GL posting account.

2. **New `posTenderType` entity**: defines available tender methods (Cash, PIN Card, Gift Card, Voucher, Account/Contact). Configured per register, linked to GL accounts in shillinq.

3. **Computed change**: when transaction total (including tax) exceeds sum of tendered amounts, the difference is computed as change (cash only).

4. **GL posting integration**: each tender posts to its configured GL account automatically (kas for cash, bank for card, debiteuren for account sales).

5. **Transaction settlement**: transaction may only settle when tender sum equals transaction total (no partial payment state).

## Scope

- `posTender` schema — payment method instance on a transaction
- `posTenderType` schema — tender method configuration
- `PosPaymentService` — validates tender combinations, computes change
- `POST /api/pos/transactions/{id}/tenders` — add tender to transaction
- `DELETE /api/pos/transactions/{id}/tenders/{tenderId}` — remove tender
- Changes to `posTransaction` settlement logic to require tender sum = total
- GL account posting to shillinq on settlement (via CloudEvent)

## Out of Scope

- Reversing/refunding specific tender lines (refund logic is per-transaction)
- Surcharging for payment method (card fees) — handled by separate surcharge feature
- Offline/offline PIN fallback — assumes Worldline/CCV integration present
- Currency conversion (multi-currency) — NL market assumes EUR only
- Wallet/BNPL integration — listed for enterprise phase

## Success Criteria

- Transaction can accept multiple tenders (cash + card) on a single sale
- Change is computed when cash tender exceeds line total
- Tender sum must equal transaction total before settlement
- Each tender posts to its GL account on settlement
- New `posTender` and `posTenderType` entities exist in `pipelinq_register.json`
- GL posting works with shillinq app via CloudEvent (verified via API test)
- No breaking changes to existing `posTransaction` settlement flow

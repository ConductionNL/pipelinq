# Proposal: pos-payment-provider-adapter

## Problem

Pipelinq POS has no integrated payment processing. Currently, payment methods in the POS transaction workflow are limited to manual entry (cash, voucher, account sale) — there is no gateway integration for card-present or online payments. Market intelligence covering 13/13 sampled competitors shows that integrated payment processing is a P0 feature:

1. **No payment gateway adapters** — Competitors support multiple payment providers (Mollie, Adyen, Stripe, Square, SumUp, Lightspeed Payments). Without an adapter layer, each new payment method requires custom hardcoding.
2. **No Dutch-primary payment methods** — Mollie (iDEAL + Bancontact) dominates Dutch e-commerce but is not integrated. CCV and Worldline are de-facto PIN terminal standards in NL retail (pos-staff-pin-permissions requires CCV integration).
3. **No PIN terminal integration** — CCV and Worldline PIN terminals are ubiquitous in NL hospitality and retail (Dutch competitors: Chromis, DVI Salon, Korona, Salonized all feature CCV / Worldline native). Without native PIN terminal support, Dutch shops cannot accept card payments in-store.
4. **No settlement webhook routing** — Payment providers emit settlement webhooks (card captured, payment settled, refund processed). There is no mechanism to route these webhooks to posTransaction for status updates, leaving transactions in a "confirmed" state indefinitely without settlement confirmation.
5. **No multi-tender split payment** — competitors (Shopify, Square, Chromis) allow split tenders (€50 cash + €30 card on a €80 transaction). Without adapter support, split payment logic cannot be implemented.

Without integrated payment processing, Pipelinq POS is limited to cash-only or manual payment entry workflows, blocking adoption in retail and hospitality where card acceptance is mandatory.

## Solution

Implement a pluggable payment provider adapter architecture with four provider implementations:

1. **Payment Provider Adapter Interface** — Defines contract for payment provider implementations:
   - `initiate(posTransaction, amount, paymentMethod)` → payment session ID
   - `capture(sessionId)` → settlement confirmation
   - `refund(sessionId, reason)` → refund confirmation
   - `webhook(payload, signature)` → webhook validation and event routing

2. **Provider implementations**:
   - **Mollie** — iDEAL, Bancontact, credit card (frictionless for Dutch users)
   - **CCV** — PIN terminal integration via CCV Gateway API (Dutch PIN standard)
   - **Adyen** — multi-method terminal (card, mobile wallet, local methods)
   - **Stripe** — card processing and Apple Pay / Google Pay

3. **Payment configuration schema** — Per-provider credentials, API keys, webhook secrets stored securely in `IAppConfig` with encryption.

4. **posTransaction payment fields** — Extend `posTransaction` schema with:
   - `paymentProvider` — which provider handled this transaction
   - `paymentSessionId` — reference to the payment session for reconciliation
   - `paymentStatus` — pending / captured / settled / failed / refunded
   - `paymentMethod` — card / ideal / bancontact / cash / etc.

5. **Webhook routing** — Register settlement webhooks from each provider; route to `PosPaymentService` to update transaction status from `confirmed` → `settled`.

6. **Admin UI** — Settings form to configure provider credentials, test connections, and manage webhooks.

## Scope

- `PaymentProviderInterface` abstract contract
- Four provider adapters: `MollieAdapter`, `CcvAdapter`, `AdyenAdapter`, `StripeAdapter`
- `PosPaymentService` — handles payment initiation, capture, refund
- `PosPaymentController` — API endpoints for payment actions and webhook receipt
- `paymentProvider` OpenRegister schema — credential storage + encryption
- Extend `posTransaction` schema with payment fields (paymentProvider, paymentSessionId, paymentStatus, paymentMethod)
- `PaymentSettingsForm.vue` — admin UI for provider configuration
- Webhook registration and validation for settlement events
- Seed data: 4 payment provider objects with dummy credentials (encrypted)
- Deduplication check

## Out of Scope

- Split tender (multi-payment per transaction) — V1
- Loyalty/gift card tenders — V1
- Currency conversion / multi-currency support — V1
- PCI compliance scanning / audit — operational
- Payment terminal hardware integration (reader drivers) — V1
- Reconciliation reporting (payment vs. transaction gaps) — separate change
- 3D Secure / SCA mandate flow beyond provider SDK defaults
- Subscription payments / recurring billing — V1

## Success Criteria

- A cashier can initiate a Mollie iDEAL payment from a POS transaction; payment status updates from `confirmed` → `settled` when the webhook is received
- A PIN terminal integrated via CCV can be tested from the admin settings without requiring a live payment (test mode)
- Adyen payment credentials can be configured and validated via the admin UI
- A refund initiated via Stripe updates the transaction status from `settled` → `refunded` and stores the refund reference
- Webhook payloads are validated using provider-specific signatures; invalid webhooks return 401 without updating transaction state
- Payment provider credentials are stored encrypted in `IAppConfig` and are not exposed in API responses
- All four providers (Mollie, CCV, Adyen, Stripe) can be configured simultaneously; transactions route to the correct provider

## Impact

- **Cashiers**: Can accept card payments at checkout (Mollie for online, CCV for PIN terminal, Adyen/Stripe for cards)
- **Shop owners**: Can enable card acceptance without separate POS terminal system
- **Accounting**: `posTransaction.paymentStatus` flows through to Shillinq for reconciliation
- **Fraud risk**: Webhook validation prevents unauthorized payment status changes
- **Dependencies**: Depends on `pos-transaction-core` for transaction entity and lifecycle

## Dependencies

- **pos-transaction-core** — transaction entity, lifecycle schema
- **Mollie API** — production and sandbox endpoints
- **CCV Gateway API** — PIN terminal and payment processing
- **Adyen API** — terminal management and payment endpoints
- **Stripe API** — payment intent and webhook signing
- **IAppConfig** — secure credential storage with encryption

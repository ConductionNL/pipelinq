---
status: draft
---

# POS Pluggable Payment Provider Specification

## Purpose

POS payment provider adapters enable integrated card, online, and PIN terminal payment processing from Pipelinq. This specification defines the contract, configuration, webhook handling, and transaction flow for four payment providers: Mollie (iDEAL / Bancontact), CCV (PIN terminals), Adyen (multi-method), and Stripe (cards / wallets).

**Demand signal**: 13/13 competitors provide integrated payment processing; Dutch retail requires Mollie (iDEAL) + CCV (PIN terminals).
**Feature tier**: MVP (single tender per transaction, capture + refund; no split payment, no subscription).
**Standards**: PCI-DSS (credentials encrypted at rest), provider-specific webhook signature validation (HmacSHA256, HMAC-SHA512, etc.).

---

## Data Model

Payment state is stored on the existing `posTransaction` entity (ADR-000):

- **paymentProvider** — which provider handled this transaction (mollie, ccv, adyen, stripe, cash, voucher, account)
- **paymentSessionId** — external reference from the provider (e.g., Mollie order ID)
- **paymentStatus** — transaction payment lifecycle: pending → captured → settled OR failed → [refunded]
- **paymentMethod** — specific method used (ideal, bancontact, card, cash, etc.)

Provider configuration is stored in a new `paymentProvider` schema with encrypted credentials.

---

## ADDED Requirements

---

### Requirement: Payment Provider Adapter Interface (REQ-PAY-001)

The system MUST provide a pluggable adapter interface that all payment providers implement.

**Feature tier**: MVP

#### Scenario: MollieAdapter implements PaymentProviderInterface

- GIVEN the `MollieAdapter` class exists
- WHEN a developer instantiates it and calls `initiate($transactionData, €50.00, "ideal")`
- THEN it MUST return `{ "sessionId": "tr_...", "redirectUrl": "https://mollie.com/pay/...", "status": "pending" }`
- AND the redirectUrl MUST be valid for the configured Mollie account

#### Scenario: CcvAdapter initiates PIN terminal payment

- GIVEN a CCV terminal with ID "kassa-01" is configured
- WHEN `CcvAdapter::initiate($transactionData, €45.99, "card")` is called
- THEN it MUST return `{ "sessionId": "CCV20260520...", "redirectUrl": null, "status": "pending" }`
- AND the sessionId MUST contain the CCV transaction reference for webhook matching

#### Scenario: AdyenAdapter routes to Adyen API

- GIVEN Adyen credentials are configured in sandbox mode
- WHEN `AdyenAdapter::initiate($transactionData, €99.99, "card")` is called
- THEN it MUST call the Adyen `/payments` endpoint with correct merchant account
- AND return a sessionId reference that Adyen returns in the response

#### Scenario: StripeAdapter creates PaymentIntent

- GIVEN Stripe API key is configured
- WHEN `StripeAdapter::initiate($transactionData, €25.50, "card")` is called
- THEN it MUST create a Stripe PaymentIntent with amount in cents (2550)
- AND return the `client_secret` as the sessionId for frontend wallet payments

#### Scenario: Refund calls provider-specific refund endpoint

- GIVEN a settled transaction with `paymentSessionId="pi_123"` and `paymentProvider="stripe"`
- WHEN `StripeAdapter::refund("pi_123", "Artikel defect")` is called
- THEN it MUST call Stripe `/refunds` endpoint
- AND return `{ "sessionId": "pi_123", "refundId": "re_456", "status": "refunded" }`

---

### Requirement: Provider Credential Storage & Encryption (REQ-PAY-002)

Payment provider credentials MUST be stored encrypted and never exposed in API responses or logs.

**Feature tier**: MVP

#### Scenario: Mollie API key stored encrypted

- GIVEN an admin enters `sk_live_1234567890abcdef` in the Mollie credential form
- WHEN the form is submitted
- THEN the API key MUST be encrypted before storing in `IAppConfig` under `pipelinq.payment_provider.mollie.apiKey`
- AND the API response MUST NOT include the decrypted key (omit or return `***` masked value)

#### Scenario: CCV API secret decrypted only when needed

- GIVEN CCV credentials are stored encrypted
- WHEN `PosPaymentService::initiatePayment()` needs to call CCV, it decrypts the secret
- THEN decryption happens in-memory only, not logged or exposed
- AND after the API call, the decrypted value is discarded

#### Scenario: Webhook signature secrets stored separately

- GIVEN each provider has a webhook signature secret (e.g., Mollie `whsec_...`)
- WHEN the webhook is received, `PosPaymentService::handleWebhook()` loads the secret
- THEN it decrypts it from `IAppConfig`, validates the payload signature, and discards the decrypted value
- AND if the signature is invalid, the webhook is rejected with HTTP 401 without updating transaction state

---

### Requirement: Payment Initiation (REQ-PAY-003)

Cashiers can initiate a payment from a POS transaction for one of the configured payment providers.

**Feature tier**: MVP

#### Scenario: Initiate Mollie payment from transaction

- GIVEN a cashier is on a `confirmed` posTransaction detail view with total €21.53
- WHEN they click "Betalen met Mollie"
- THEN the system MUST call `POST /api/pos-payments/{id}/initiate` with `{ "providerName": "mollie", "paymentMethod": "ideal" }`
- AND the transaction MUST be updated with:
  - `paymentProvider = "mollie"`
  - `paymentSessionId = "tr_WDqYK6vllg"` (from Mollie response)
  - `paymentStatus = "pending"`
  - `paymentMethod = "ideal"`
- AND if `redirectUrl` is returned, the browser MUST navigate to it (Mollie redirect flow)

#### Scenario: PIN terminal payment captures immediately

- GIVEN a CCV PIN terminal is connected and configured
- WHEN a cashier initiates a payment of €89.97
- THEN the system MUST call `CcvAdapter::initiate()` with terminal ID
- AND the terminal device MUST show the payment prompt (€89,97 on screen)
- AND when the customer completes the PIN entry, the webhook MUST update the transaction to `paymentStatus = "captured"`
- AND no manual "Capture" button click is required by the cashier

#### Scenario: Invalid provider returns 422

- GIVEN a cashier attempts to pay via a provider that is not configured or disabled
- WHEN they call `POST /api/pos-payments/{id}/initiate` with `providerName="fake_provider"`
- THEN the system MUST return HTTP 422 with error message "Provider fake_provider is not configured"
- AND the transaction MUST remain in `confirmed` status

#### Scenario: Initiate payment with incorrect amount

- GIVEN a transaction total is €50.00
- WHEN the payment is initiated with the wrong amount (e.g., €5.00)
- THEN the system MUST still initiate the payment with €5.00 (do not second-guess the cashier)
- AND if the provider returns a validation error, the error MUST be shown to the cashier
- AND the transaction MUST remain in `confirmed` status

---

### Requirement: Payment Capture (REQ-PAY-004)

Payments that authorize but do not immediately settle MUST be captured to finalize the charge.

**Feature tier**: MVP

#### Scenario: Capture Stripe payment via API

- GIVEN a transaction with `paymentStatus = "pending"` and `paymentProvider = "stripe"`
- WHEN the cashier clicks "Afronden" button
- THEN the system MUST call `POST /api/pos-payments/{id}/capture`
- AND `StripeAdapter::capture(sessionId)` MUST call Stripe `/charges/{id}/capture` endpoint
- AND on success, the transaction MUST be updated with `paymentStatus = "captured"`
- AND on failure, the error MUST be shown and status MUST remain `pending`

#### Scenario: Mollie payment webhook auto-settles

- GIVEN a Mollie iDEAL payment is initiated and customer completes payment
- WHEN Mollie webhook arrives at `POST /webhook/mollie` with signature validation
- THEN `PosPaymentService::handleWebhook()` MUST update the transaction directly to `paymentStatus = "settled"`
- AND no explicit "Capture" step is required

#### Scenario: CCV PIN terminal does not require explicit capture

- GIVEN a CCV PIN payment is initiated and customer completes PIN entry
- WHEN the CCV webhook arrives, the transaction status MUST move to `captured` and then `settled`
- AND no manual "Capture" button is shown for CCV transactions on the detail view

---

### Requirement: Payment Refund (REQ-PAY-005)

Settled payments can be refunded. Refund requires manager permission.

**Feature tier**: MVP

#### Scenario: Refund Stripe payment

- GIVEN a settled transaction with `paymentProvider = "stripe"` and `paymentSessionId = "pi_123"`
- WHEN a manager clicks "Terugboeken" and enters reason "Artikel beschadigd"
- THEN the system MUST call `POST /api/pos-payments/{id}/refund` with `{ "reason": "Artikel beschadigd" }`
- AND `AuthorizationService` MUST verify the user has manager permission; return 403 if not
- AND `StripeAdapter::refund()` MUST call Stripe API and return refund confirmation
- AND the transaction MUST be updated with:
  - `paymentStatus = "refunded"`
  - `refundReason = "Artikel beschadigd"`
  - `refundedAt = {{iso-timestamp}}`

#### Scenario: Refund not allowed for draft transaction

- GIVEN a transaction with `status = "draft"` or `paymentStatus != "settled"`
- WHEN a manager attempts to refund
- THEN the system MUST return HTTP 422 with error "Transaction is not settled; cannot refund"
- AND status MUST remain unchanged

#### Scenario: Mollie refund via webhook

- GIVEN a transaction has been refunded via Mollie dashboard
- WHEN Mollie sends a settlement webhook indicating a refund
- THEN the webhook MUST update the transaction to `paymentStatus = "refunded"` automatically

---

### Requirement: Webhook Reception & Validation (REQ-PAY-006)

Payment providers POST settlement webhooks to the app. Webhooks MUST be validated using provider-specific signatures.

**Feature tier**: MVP

#### Scenario: Mollie webhook validation

- GIVEN Mollie sends a webhook to `POST /webhook/mollie` with payload and `X-Mollie-Signature` header
- WHEN `PosPaymentService::handleWebhook("mollie", payload, signature)` is called
- THEN it MUST:
  1. Load the Mollie provider config from `IAppConfig`
  2. Decrypt the webhook secret
  3. Compute HMAC-SHA256(payload, secret) and compare to the header signature
  4. Return `{ "status": "ok" }` if valid OR `{ "status": "invalid", "error": "Signature mismatch" }` if not
- AND if invalid, HTTP 401 MUST be returned and the transaction MUST NOT be updated

#### Scenario: CCV webhook signature validation

- GIVEN CCV sends a webhook with `X-CCV-Signature` header
- WHEN the webhook handler validates the signature
- THEN it MUST use CCV's signature algorithm (HmacSHA512 with merchantId concatenation) per CCV API docs
- AND only update the transaction if the signature validates

#### Scenario: Webhook payload parsing by provider

- GIVEN a Mollie webhook payload with order ID `tr_WDqYK6vllg`
- WHEN the webhook is validated and routed to settlement handling
- THEN `PosPaymentService::handleSettlement()` MUST:
  1. Extract the sessionId (Mollie order ID) from the webhook payload
  2. Query the database for a posTransaction with matching `paymentSessionId`
  3. Extract payment status from the webhook (e.g., "paid", "expired", "cancelled")
  4. Update the transaction's `paymentStatus` accordingly

#### Scenario: Webhook for unknown transaction ignored

- GIVEN a webhook arrives with a sessionId that does NOT match any transaction
- WHEN the webhook handler searches for the transaction
- THEN it MUST log the unmatched webhook (for debugging) and return HTTP 200 (not 400)
- AND no transaction MUST be created or modified

#### Scenario: Duplicate webhook idempotency

- GIVEN a settlement webhook has already been processed
- WHEN the same webhook is received again (same provider event ID)
- THEN the second webhook MUST be idempotent: the transaction status MUST remain `settled` (no duplicate refunds, no duplicate captures)
- AND HTTP 200 MUST be returned both times

---

### Requirement: Provider Configuration UI (REQ-PAY-007)

Administrators can configure payment provider credentials, test connections, and manage webhooks.

**Feature tier**: MVP

#### Scenario: Configure Mollie credentials

- GIVEN an admin is on `/settings/payment`
- WHEN they click the "Mollie" card and enter `sk_live_ABC123DEF456`
- THEN clicking "Opslaan" MUST:
  1. Encrypt the API key
  2. Store it in `IAppConfig`
  3. Call `POST /api/payment-providers/mollie/test` to verify connectivity
  4. Show "Verbinding succesvol getest op {{timestamp}}"

#### Scenario: Test CCV connection fails gracefully

- GIVEN CCV credentials are invalid
- WHEN the admin clicks "Verbinding testen"
- THEN the system MUST call `CcvAdapter::testConnection()`
- AND when CCV API returns 401 or 403, the test MUST show "Fout: Invalid API credentials. Controleer uw API key."
- AND the credentials MUST still be saved (do not block save on test failure)

#### Scenario: Disable provider for transactions

- GIVEN a provider is configured but the admin wants to disable it temporarily
- WHEN they toggle "Deze provider inschakelen" to OFF
- THEN transactions MUST NOT be routable to that provider (UI dropdown does not show it)
- AND existing transactions with that provider MUST still show their payment status correctly

#### Scenario: Per-provider configuration fields

- GIVEN the Mollie card on `/settings/payment`
- WHEN the admin expands it, they MUST see:
  - Checkbox: "Deze provider inschakelen"
  - Radio buttons: "Sandbox" / "Productie"
  - Text input: "API Key" (masked)
  - Text input: "Webhook Secret" (masked)
  - Button: "Verbinding testen"
  - Timestamp: "Laatst getest op {{time}}" (if tested)
- AND CCV card MUST additionally show:
  - Text input: "Terminal ID" (e.g., "kassa-01")

---

### Requirement: Payment Method Selection (REQ-PAY-008)

Cashiers select the payment method and provider when confirming a transaction.

**Feature tier**: MVP

#### Scenario: Payment method dropdown on transaction confirmation

- GIVEN a PosTransactionForm is being edited with line items and totals calculated
- WHEN the cashier is about to confirm the transaction
- THEN above the "Bevestigen" button, a dropdown MUST appear: "Betaalmethode"
- AND the dropdown options MUST be:
  - Contant (cash) — always available
  - Cadeaubon (voucher) — if configured
  - Rekening (account sale) — if client is selected
  - Mollie — if enabled in settings
  - CCV — if enabled in settings
  - Adyen — if enabled in settings
  - Stripe — if enabled in settings

#### Scenario: Select Mollie iDEAL as payment method

- GIVEN "Mollie" is selected in the payment method dropdown
- WHEN a sub-dropdown or radio buttons appear showing "iDEAL" and "Bancontact"
- THEN the cashier selects "iDEAL"
- AND clicking "Bevestigen" MUST call `confirmTransaction()` and then `initiatePayment(mollie, ideal)`

#### Scenario: PIN terminal payment method shows no sub-options

- GIVEN "CCV" is selected in the payment method dropdown
- WHEN no sub-menu appears (CCV does not offer choice of method like Mollie)
- AND clicking "Bevestigen" MUST call `confirmTransaction()` and then `initiatePayment(ccv, card)`
- AND the terminal prompt MUST appear on the PIN device

#### Scenario: Cash payment bypasses payment service

- GIVEN "Contant" is selected
- WHEN "Bevestigen" is clicked
- THEN the transaction MUST be confirmed with:
  - `paymentProvider = "cash"`
  - `paymentStatus = "settled"` (no capture step needed)
  - `paymentMethod = "cash"`
- AND no `initiatePayment()` call MUST be made

---

### Requirement: Transaction Status Lifecycle with Payments (REQ-PAY-009)

Transactions flow through lifecycle states including payment status updates.

**Feature tier**: MVP

#### Scenario: Transaction status transitions with card payment

- GIVEN a POS transaction with items and total of €50.00
- WHEN the cashier confirms the transaction
- THEN `posTransaction.status = "confirmed"` and `paymentStatus = null` (awaiting payment method selection)
- WHEN the cashier selects "Stripe" and clicks confirm again
- THEN `paymentStatus = "pending"` (payment initiated)
- WHEN the webhook arrives with payment capture
- THEN `paymentStatus = "captured"` and if settlement is automatic, `paymentStatus = "settled"`
- AND the transaction detail view MUST show the timeline: Confirmed → Payment Pending → Payment Captured → Settled

#### Scenario: Parked transaction retains payment state

- GIVEN a confirmed transaction with `paymentStatus = "pending"` (payment in progress)
- WHEN the cashier clicks "Parkeren"
- THEN `posTransaction.status = "parked"` BUT `paymentStatus` MUST NOT change (still "pending")
- AND when the transaction is resumed, `paymentStatus` MUST still be "pending"
- AND the payment session MUST remain valid with the provider

---

### Requirement: Error Handling (REQ-PAY-010)

Payment failures and invalid transitions MUST show clear user-facing error messages in Dutch.

**Feature tier**: MVP

#### Scenario: Payment provider API timeout

- GIVEN a cashier initiates a Stripe payment but Stripe API is unreachable
- WHEN the HTTP request times out after 5 seconds
- THEN the system MUST show "Verbinding met betalingsprovider verbroken. Probeer opnieuw." (Connection lost message)
- AND the transaction status MUST remain in `confirmed` (not advanced to `pending`)
- AND the cashier MUST be able to retry without reconfirming the transaction

#### Scenario: Insufficient funds webhook

- GIVEN a Stripe payment is initiated and the webhook returns "insufficient_funds"
- WHEN `handleWebhook()` processes this, it MUST extract the error from the provider webhook
- THEN the transaction MUST be updated to `paymentStatus = "failed"`
- AND the transaction detail view MUST show "Betaling geweigerd: Onvoldoende dekking"
- AND the transaction MUST remain `confirmed` (not refunded, allowing retry)

#### Scenario: Mollie order expired

- GIVEN a Mollie iDEAL payment session was initiated but the customer did not complete payment within 15 minutes
- WHEN Mollie sends a webhook with status "expired"
- THEN the transaction MUST be updated to `paymentStatus = "failed"`
- AND the cashier MUST be able to re-initiate payment without changing the transaction

---

### Requirement: Shillinq Integration (REQ-PAY-011)

When a payment is settled, a CloudEvent MUST be emitted for Shillinq accounting.

**Feature tier**: MVP

#### Scenario: CloudEvent emitted on settlement

- GIVEN a posTransaction reaches `paymentStatus = "settled"`
- WHEN the settlement event is processed
- THEN `PosPaymentService` MUST emit a CloudEvent via `WebhookService`:
  ```json
  {
    "specversion": "1.0",
    "type": "pipelinq.PosPayment.settled",
    "source": "/apps/pipelinq/pos/payment",
    "id": "{{uuid}}",
    "time": "{{settledAt}}",
    "datacontenttype": "application/json",
    "data": {
      "transactionId": "{{uuid}}",
      "reference": "TXN-2026-0001",
      "paymentProvider": "mollie",
      "paymentMethod": "ideal",
      "paymentSessionId": "tr_WDqYK6vllg",
      "total": 21.53,
      "settledAt": "2026-05-20T09:14:30+02:00"
    }
  }
  ```
- AND Shillinq MUST subscribe to `pipelinq.PosPayment.settled` to draft journal entries

#### Scenario: Refund event emitted to Shillinq

- GIVEN a transaction is refunded
- THEN a CloudEvent with type `pipelinq.PosPayment.refunded` MUST be emitted with the refund reason and amount

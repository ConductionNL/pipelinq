# Tasks: pos-payment-provider-adapter

## 0. Deduplication Check

- [x] 0.1 Search `openspec/changes/` and `openregister/lib/Service/` for any existing payment provider or gateway integration; document findings
  - **acceptance_criteria**:
    - GIVEN the search is complete
    - THEN a one-line finding MUST be appended: "No payment integration found" or reference to existing capability
  - **finding**: No POS payment provider integration found. The existing `AppointmentPaymentProvider` is a single-purpose fee charger that routes booking no-show / late-cancellation charges through openconnector — it is NOT pluggable, NOT POS-related, and shares no contract surface. No `PaymentProviderInterface` / `PosPaymentService` / adapter classes existed prior to this change.

---

## 1. Data Model

- [x] 1.1 Add `paymentProvider` schema to `lib/Settings/pipelinq_register.json`
  - **spec_ref**: `specs/pos-payment-provider-adapter/spec.md#REQ-PAY-002`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the schema is imported
    - THEN all properties from design.md paymentProvider table MUST be present with correct types
    - AND `@type: "schema:PaymentService"` MUST be set
    - AND `apiKey`, `apiSecret`, `webhookSecret` MUST be marked as encrypted

- [x] 1.2 Extend `posTransaction` schema with payment fields
  - **spec_ref**: `specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001, REQ-PAY-009`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN posTransaction is updated
    - THEN four new properties MUST be added: paymentProvider, paymentSessionId, paymentStatus, paymentMethod
    - AND `paymentStatus` enum MUST be: pending, captured, settled, failed, refunded
    - AND default `paymentStatus` MUST be null (no payment yet)
    - AND existing posTransaction objects MUST remain unchanged (backwards compatible)

- [x] 1.3 Add seed data for paymentProvider (4 objects: mollie, ccv, adyen, stripe)
  - **spec_ref**: ADR-001 (data-layer)
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register is imported
    - THEN 4 paymentProvider objects MUST be created (mollie, ccv, adyen, stripe) with isActive=false, environment=sandbox, testMode=true
    - AND encrypted fields MUST contain placeholder values (not real credentials)
    - AND each provider MUST have a unique slug for idempotent re-import

- [x] 1.4 Update posTransaction seed data with payment fields
  - **spec_ref**: `specs/pos-payment-provider-adapter/spec.md#REQ-PAY-009`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN existing posTransaction seeds are updated
    - THEN TXN-0001 MUST have: paymentProvider=mollie, paymentSessionId, paymentStatus=settled, paymentMethod=ideal
    - AND TXN-0003 MUST have: paymentProvider=ccv, paymentStatus=captured, paymentMethod=card
    - AND TXN-0005 MUST have: paymentProvider=stripe, paymentStatus=refunded
    - AND TXN-0002 and TXN-0004 MUST have: paymentProvider=null, paymentStatus=pending

---

## 2. Payment Provider Interface & Adapters

- [x] 2.1 Create `lib/Service/Payment/PaymentProviderInterface.php`
  - **spec_ref**: `specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001`
  - **files**: `pipelinq/lib/Service/Payment/PaymentProviderInterface.php`
  - **acceptance_criteria**:
    - GIVEN the interface is defined
    - THEN four public methods MUST be declared: initiate(), capture(), refund(), validateWebhook()
    - AND return types and parameter types MUST match design.md
    - AND each method MUST include `@spec` docblock reference

- [x] 2.2 Create `lib/Service/Payment/MollieAdapter.php`
  - **spec_ref**: `specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001, REQ-PAY-003`
  - **files**: `pipelinq/lib/Service/Payment/MollieAdapter.php`
  - **acceptance_criteria**:
    - GIVEN MollieAdapter is instantiated with valid API key
    - WHEN initiate() is called with paymentMethod=ideal
    - THEN it MUST call Mollie Orders API with amount and description
    - AND return { "sessionId": "tr_...", "redirectUrl": "https://...", "status": "pending" }
    - AND validateWebhook() MUST use HMAC-SHA256 signature validation with Mollie secret

- [x] 2.3 Create `lib/Service/Payment/CcvAdapter.php`
  - **spec_ref**: `specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001, REQ-PAY-003`
  - **files**: `pipelinq/lib/Service/Payment/CcvAdapter.php`
  - **acceptance_criteria**:
    - GIVEN CcvAdapter is instantiated with terminalId
    - WHEN initiate() is called with amount
    - THEN it MUST call CCV Gateway API with merchant account and terminal ID
    - AND return { "sessionId": "CCV...", "redirectUrl": null, "status": "pending" }
    - AND validateWebhook() MUST use provider-specific signature algorithm (HmacSHA512)

- [x] 2.4 Create `lib/Service/Payment/AdyenAdapter.php`
  - **spec_ref**: `specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001`
  - **files**: `pipelinq/lib/Service/Payment/AdyenAdapter.php`
  - **acceptance_criteria**:
    - GIVEN AdyenAdapter is instantiated with API key and merchant account
    - WHEN initiate() is called
    - THEN it MUST call Adyen `/payments` endpoint
    - AND return sessionId from Adyen response
    - AND validateWebhook() MUST use Adyen HMAC signature validation

- [x] 2.5 Create `lib/Service/Payment/StripeAdapter.php`
  - **spec_ref**: `specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001, REQ-PAY-004`
  - **files**: `pipelinq/lib/Service/Payment/StripeAdapter.php`
  - **acceptance_criteria**:
    - GIVEN StripeAdapter is instantiated with API key
    - WHEN initiate() is called with amount
    - THEN it MUST create a Stripe PaymentIntent with amount in cents
    - AND return { "sessionId": client_secret, "redirectUrl": null, "status": "pending" }
    - AND capture() MUST confirm the PaymentIntent (not just capture — Stripe confirms on init)
    - AND refund() MUST call Stripe refunds API
    - AND validateWebhook() MUST use Stripe signature headers (t=, v1=)

---

## 3. Credential Management

- [x] 3.1 Implement credential encryption/decryption in `PosPaymentService`
  - **spec_ref**: `specs/pos-payment-provider-adapter/spec.md#REQ-PAY-002`
  - **files**: `pipelinq/lib/Service/PosPaymentService.php`
  - **acceptance_criteria**:
    - GIVEN a provider credential is stored in `IAppConfig`
    - WHEN `PosPaymentService::getProvider()` is called
    - THEN it MUST decrypt the apiKey and other secrets using `\OC\Encryption\Util`
    - AND the decrypted values MUST NOT be logged or exposed in responses
    - AND after use, decrypted values MUST be discarded from memory

- [x] 3.2 Add encryption to provider credential form submission
  - **spec_ref**: `specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007`
  - **files**: `pipelinq/lib/Controller/PosPaymentController.php`
  - **acceptance_criteria**:
    - GIVEN an admin submits provider credentials via `PUT /api/payment-providers/{name}`
    - WHEN the request body contains apiKey, apiSecret, or webhookSecret
    - THEN these MUST be encrypted before storing in `IAppConfig`
    - AND the API response MUST mask these fields with "***"

---

## 4. Payment Service & Controller

- [x] 4.1 Create `lib/Service/PosPaymentService.php`
  - **spec_ref**: `specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001 through REQ-PAY-011`
  - **files**: `pipelinq/lib/Service/PosPaymentService.php`
  - **acceptance_criteria**:
    - GIVEN the service is injected
    - THEN `getPaymentProvider(string $name)` MUST load and decrypt provider credentials, instantiate adapter, return it
    - AND `initiatePayment()` MUST load provider, call adapter initiate(), update transaction with sessionId + paymentStatus=pending
    - AND `capturePayment()` MUST call adapter capture(), update paymentStatus=captured
    - AND `refundPayment()` MUST check manager permission, call adapter refund(), update paymentStatus=refunded
    - AND `handleWebhook()` MUST validate signature via adapter validateWebhook(), return 401 on failure
    - AND `handleSettlement()` MUST parse webhook payload, find transaction by sessionId, update paymentStatus
    - AND `testConnection()` MUST call provider test API, store result in provider config
    - AND every public method MUST have `@spec` docblock

- [x] 4.2 Create `lib/Controller/PosPaymentController.php`
  - **spec_ref**: `specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003, REQ-PAY-004, REQ-PAY-005, REQ-PAY-007`
  - **files**: `pipelinq/lib/Controller/PosPaymentController.php`
  - **acceptance_criteria**:
    - GIVEN the controller is registered
    - THEN POST `/api/pos-payments/{id}/initiate` MUST call `PosPaymentService::initiatePayment()` with body params
    - AND POST `/api/pos-payments/{id}/capture` MUST call `capturePayment()`
    - AND POST `/api/pos-payments/{id}/refund` MUST validate manager permission, call `refundPayment()`
    - AND POST `/webhook/{provider}` MUST extract signature from headers, call `handleWebhook()`, return 401 on invalid signature
    - AND POST `/api/payment-providers/{name}/test` MUST require admin role, call `testConnection()`, return result
    - AND GET `/api/payment-providers` MUST return list of providers (credentials masked)
    - AND PUT `/api/payment-providers/{name}` MUST require admin role, encrypt and store credentials
    - AND all methods MUST be <15 lines (thin controller pattern)

---

## 5. Routes & Navigation

- [x] 5.1 Add payment API routes to `appinfo/routes.php`
  - **spec_ref**: ADR-002 (api)
  - **files**: `pipelinq/appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN the routes file is updated
    - THEN routes for POST `/api/pos-payments/{id}/initiate|capture|refund` MUST be added
    - AND route POST `/webhook/{provider}` MUST be public (no auth) so providers can send webhooks
    - AND routes GET|PUT `/api/payment-providers/{name}|/api/payment-providers` MUST require admin:PaymentProviders capability

- [x] 5.2 Add `/settings/payment` route to `src/router/index.js`
  - **spec_ref**: ADR-016 (routes)
  - **files**: `pipelinq/src/router/index.js`
  - **acceptance_criteria**:
    - GIVEN the router is configured
    - THEN route `/settings/payment` MUST point to `PaymentSettingsForm.vue`
    - AND route MUST require admin role

---

## 6. Frontend: Admin Settings

- [x] 6.1 Create `src/views/settings/PaymentSettingsForm.vue`
  - **spec_ref**: `specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007`
  - **files**: `pipelinq/src/views/settings/PaymentSettingsForm.vue`
  - **acceptance_criteria**:
    - GIVEN an admin opens `/settings/payment`
    - THEN they MUST see four provider cards: Mollie, CCV, Adyen, Stripe
    - AND each card MUST have:
      - Checkbox "Deze provider inschakelen"
      - Radio: Sandbox / Productie
      - Text input "API Key" (masked with placeholders after save)
      - Text input "API Secret" (if applicable)
      - Text input "Webhook Secret" (if applicable)
      - For CCV: additional input "Terminal ID"
      - Button "Verbinding testen"
      - Timestamp "Laatst getest op {{time}}" (if tested)
    - AND clicking "Opslaan" MUST call `PUT /api/payment-providers/{name}` with encrypted credentials
    - AND clicking "Verbinding testen" MUST call `POST /api/payment-providers/{name}/test` and show result

- [x] 6.2 Update `src/navigation/SettingsMenu.vue`
  - **spec_ref**: `specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007`
  - **files**: `pipelinq/src/navigation/SettingsMenu.vue`
  - **acceptance_criteria**:
    - GIVEN the settings menu is rendered
    - THEN a link "Betalingsmethoden" MUST appear (icon: credit-card or payment)
    - AND clicking it MUST navigate to `/settings/payment` (admin only)

---

## 7. Frontend: Transaction Payment UI

- [x] 7.1 Create `src/components/pos/PaymentMethodSelector.vue`
  - **spec_ref**: `specs/pos-payment-provider-adapter/spec.md#REQ-PAY-008`
  - **files**: `pipelinq/src/components/pos/PaymentMethodSelector.vue`
  - **acceptance_criteria**:
    - GIVEN a PosTransactionForm is being edited
    - WHEN the transaction is confirmed
    - THEN a dropdown MUST appear: "Betaalmethode"
    - AND options MUST include: Contant, Cadeaubon, Rekening, Mollie, CCV, Adyen, Stripe (based on enabled providers)
    - AND selecting Mollie MUST show sub-options: iDEAL, Bancontact
    - AND selecting Contant MUST set paymentMethod=cash automatically (no sub-option needed)
    - AND emitting @pay event MUST pass { paymentMethod, providerName }

- [x] 7.2 Update `src/views/pos/PosTransactionForm.vue`
  - **spec_ref**: `specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003, REQ-PAY-008, REQ-PAY-010`
  - **files**: `pipelinq/src/views/pos/PosTransactionForm.vue`
  - **acceptance_criteria**:
    - GIVEN the form is being edited
    - WHEN line items are added and totals are calculated
    - THEN before the "Bevestigen" button, `<PaymentMethodSelector>` MUST be inserted
    - AND on form submit, if a payment provider is selected (not cash):
      - THEN `confirmTransaction()` MUST be called first (status = confirmed)
      - THEN `initiatePayment()` MUST be called (paymentStatus = pending)
    - AND on cash payment: `confirmTransaction()` MUST set paymentStatus=settled (no initiate call)
    - AND errors from `initiatePayment()` MUST be shown in a CnNotification alert
    - AND the form MUST NOT submit if an error occurs (user can retry)

- [x] 7.3 Update `src/views/pos/PosTransactionDetail.vue`
  - **spec_ref**: `specs/pos-payment-provider-adapter/spec.md#REQ-PAY-009`
  - **files**: `pipelinq/src/views/pos/PosTransactionDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a transaction detail is displayed
    - THEN a new "Betaling" card MUST show:
      - Payment provider name (if set)
      - Payment method (ideal, card, cash, etc.)
      - Payment status badge (pending, captured, settled, failed, refunded)
      - If paymentSessionId: display as "Session: {{id}}"
      - If paymentStatus != settled: show action buttons based on status
        - pending: "Afronden" button → capture
        - captured: "Terugboeken" button (manager only) → refund
        - settled: "Terugboeken" button (manager only) → refund
        - failed: "Opnieuw proberen" button → re-initiate payment
    - AND clicking action buttons MUST call the respective API endpoints
    - AND on success, the card MUST refresh to show updated status
    - AND on error, CnNotification MUST show the error message

---

## 8. Webhook Handling

- [x] 8.1 Implement webhook routing in `lib/Service/PosPaymentService.php`
  - **spec_ref**: `specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006, REQ-PAY-011`
  - **files**: `pipelinq/lib/Service/PosPaymentService.php`
  - **acceptance_criteria**:
    - GIVEN a webhook arrives at `POST /webhook/{provider}`
    - WHEN `handleWebhook()` is called
    - THEN it MUST:
      1. Load provider adapter via `getPaymentProvider()`
      2. Call `adapter->validateWebhook(payload, signature)`
      3. Return { "status": "invalid", "error": "..." } if signature fails (causes 401)
      4. Parse the webhook payload via provider-specific logic
      5. Extract sessionId and payment status
      6. Call `handleSettlement(sessionId, data)` to update transaction
    - AND `handleSettlement()` MUST:
      1. Query for posTransaction with matching paymentSessionId
      2. Update paymentStatus based on webhook status (paid → settled, expired → failed, etc.)
      3. If paymentStatus becomes settled, emit `pipelinq.PosPayment.settled` CloudEvent
      4. If paymentStatus becomes refunded, emit `pipelinq.PosPayment.refunded` CloudEvent
      5. Return the updated transaction

- [x] 8.2 Validate webhook signatures per provider specification
  - **spec_ref**: `specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006`
  - **files**: `pipelinq/lib/Service/Payment/{MollieAdapter,CcvAdapter,AdyenAdapter,StripeAdapter}.php`
  - **acceptance_criteria**:
    - GIVEN each adapter implements validateWebhook()
    - THEN Mollie MUST use HMAC-SHA256(payload, secret) vs. X-Mollie-Signature header
    - AND CCV MUST use HmacSHA512 with merchantId concatenation per API spec
    - AND Adyen MUST use HMAC-SHA256 with specific header format (X-Adyen-Signature)
    - AND Stripe MUST use signature headers (t=, v1=) with HMAC-SHA256
    - AND all implementations MUST return true|false, not throw exceptions

- [x] 8.3 Handle webhook idempotency for duplicate events
  - **spec_ref**: `specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006`
  - **files**: `pipelinq/lib/Service/PosPaymentService.php`
  - **acceptance_criteria**:
    - GIVEN a webhook has been processed and transaction status is already updated
    - WHEN the same webhook is received again (by provider event ID / timestamp)
    - THEN no duplicate update MUST occur (idempotent)
    - AND HTTP 200 MUST be returned both times
    - AND optional: webhook event ID MUST be stored on transaction for dedup check

---

## 9. CloudEvent Emission

- [x] 9.1 Emit `pipelinq.PosPayment.settled` event on settlement
  - **spec_ref**: `specs/pos-payment-provider-adapter/spec.md#REQ-PAY-011`
  - **files**: `pipelinq/lib/Service/PosPaymentService.php`
  - **acceptance_criteria**:
    - GIVEN a transaction reaches paymentStatus=settled
    - WHEN `handleSettlement()` completes
    - THEN `WebhookService::emit()` MUST be called with CloudEvent:
      - specversion: "1.0"
      - type: "pipelinq.PosPayment.settled"
      - source: "/apps/pipelinq/pos/payment"
      - id: {{uuid}}
      - time: {{settledAt}}
      - data: { transactionId, reference, paymentProvider, paymentMethod, paymentSessionId, total, settledAt }

- [x] 9.2 Emit `pipelinq.PosPayment.refunded` event on refund
  - **spec_ref**: `specs/pos-payment-provider-adapter/spec.md#REQ-PAY-011`
  - **files**: `pipelinq/lib/Service/PosPaymentService.php`
  - **acceptance_criteria**:
    - GIVEN a transaction is refunded via `refundPayment()`
    - THEN CloudEvent type `pipelinq.PosPayment.refunded` MUST be emitted
    - AND data MUST include refundReason, refundId (if returned by provider), and refundedAt

---

## 10. Error Handling & Validation

- [x] 10.1 Validate payment request parameters in controller
  - **spec_ref**: `specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003, REQ-PAY-010`
  - **files**: `pipelinq/lib/Controller/PosPaymentController.php`
  - **acceptance_criteria**:
    - GIVEN a payment initiation request
    - WHEN providerName or paymentMethod is missing or invalid
    - THEN return HTTP 422 with error message in Dutch
    - AND transaction status MUST NOT be updated
    - AND errors MUST be user-friendly: "Betaalmethode niet geconfigureerd" (not stack traces)

- [x] 10.2 Handle provider API errors gracefully
  - **spec_ref**: `specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003, REQ-PAY-010`
  - **files**: `pipelinq/lib/Service/Payment/{Adapters}.php`
  - **acceptance_criteria**:
    - GIVEN a provider API call fails (timeout, 401, 500)
    - WHEN the exception is caught in the adapter
    - THEN the adapter MUST return { "status": "failed", "error": "descriptive message" }
    - AND the error MUST be logged (without exposing secrets)
    - AND HTTP 500 or 422 MUST be returned to the client with user-friendly message
    - AND transaction status MUST NOT be updated (remains confirmed)

- [x] 10.3 Validate transaction state before payment actions
  - **spec_ref**: `specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003, REQ-PAY-004, REQ-PAY-005`
  - **files**: `pipelinq/lib/Service/PosPaymentService.php`
  - **acceptance_criteria**:
    - GIVEN initiatePayment() is called on a transaction with status != confirmed
    - WHEN validation fails
    - THEN return HTTP 422: "Transaction is not confirmed"
    - AND GIVEN refundPayment() is called on a transaction with paymentStatus != settled
    - THEN return HTTP 422: "Transaction payment not settled"
    - AND GIVEN refundPayment() is called by non-manager
    - THEN return HTTP 403: "Manager permission required"

---

## 11. Testing & Validation

- [x] 11.1 Create test fixtures for payment adapters
  - **files**: `tests/Unit/Service/Payment/MollieAdapterTest.php` (and for other adapters)
  - **acceptance_criteria**:
    - GIVEN test cases for each adapter
    - THEN mock the provider API responses
    - AND test initiate() with valid and invalid requests
    - AND test capture() and refund() methods
    - AND test validateWebhook() with valid and invalid signatures
    - AND test error handling for provider API failures

- [x] 11.2 Create integration test for payment flow
  - **files**: `tests/Integration/PosPaymentIntegrationTest.php`
  - **acceptance_criteria**:
    - GIVEN a full payment flow test
    - THEN create a posTransaction, confirm it, initiate payment (mocked provider)
    - AND simulate webhook arrival and validation
    - AND verify transaction paymentStatus is updated correctly
    - AND verify CloudEvent is emitted to Shillinq

---

## 12. Documentation & Configuration

- [x] 12.1 Document payment provider setup instructions
  - **files**: `docs/pos-payment-provider-adapter.md`
  - **acceptance_criteria**:
    - GIVEN the doc is written
    - THEN it MUST include:
      - Overview of payment provider architecture
      - Step-by-step instructions for configuring each provider (Mollie, CCV, Adyen, Stripe)
      - Where to find API keys (links to provider dashboards)
      - How to set webhook URLs in each provider's settings
      - Testing checklist (test connections, test payment flow in sandbox)

- [x] 12.2 Add environment variable documentation for credentials
  - **files**: `.env.example` or `docs/configuration.md`
  - **acceptance_criteria**:
    - GIVEN operators need to configure payments
    - THEN document that credentials are stored encrypted in `IAppConfig` (not .env)
    - AND provide example screenshots of the admin UI

---

## 13. Merge & Release Checks

- [x] 13.1 Verify all files are created and routes are registered
  - **acceptance_criteria**:
    - GIVEN the code is complete
    - THEN `npm run build` MUST complete without errors
    - AND `npm run lint` MUST pass (or warnings only)
    - AND `php -l lib/Service/PosPaymentService.php` MUST pass (no syntax errors)

- [x] 13.2 Verify seed data imports without errors
  - **acceptance_criteria**:
    - GIVEN the app is installed / upgrade runs
    - THEN `lib/Settings/pipelinq_register.json` MUST be imported
    - AND 4 paymentProvider objects MUST be created in OpenRegister
    - AND posTransaction objects MUST be updated with payment fields
    - AND no duplicate slug errors on re-import

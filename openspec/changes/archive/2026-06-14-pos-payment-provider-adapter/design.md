# Design: pos-payment-provider-adapter

## Architecture Overview

A thin pluggable adapter layer routes payment requests to provider-specific implementations (Mollie, CCV, Adyen, Stripe). Each provider implements a common `PaymentProviderInterface` contract. Provider credentials are stored encrypted in `IAppConfig`. Settlement webhooks from each provider are routed through a unified `WebhookRouter` to update transaction status. No new database tables are introduced — all data is stored via `IAppConfig` (credentials) and extensions to the existing `posTransaction` schema (payment metadata).

---

## Data Model (OpenRegister Schemas)

### posTransaction (extension)

The existing `posTransaction` schema is extended with four new payment fields:

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| paymentProvider | string | No | Name of the payment provider used (mollie, ccv, adyen, stripe, cash, voucher, account) |
| paymentSessionId | string | No | External payment session ID from the provider (e.g., Mollie order ID, Stripe PaymentIntent ID, CCV transaction ID) |
| paymentStatus | string | No | Payment lifecycle: `pending` \| `captured` \| `settled` \| `failed` \| `refunded`. Default: `pending` |
| paymentMethod | string | No | The specific payment method used (card, ideal, bancontact, cash, voucher, etc.) |

**Unchanged fields**: All existing `posTransaction` fields remain; the four new fields are additive.

---

### paymentProvider (new schema)

Configuration and credentials for each payment provider. Stored in `IAppConfig` encrypted; OpenRegister schema allows easy admin UI for credential management.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Provider identifier: mollie, ccv, adyen, stripe |
| displayName | string | Yes | Human-readable name (Mollie, CCV, Adyen, Stripe) |
| type | string | Yes | Provider type: `online` (Mollie, Stripe) or `terminal` (CCV, Adyen) |
| isActive | boolean | No | Whether this provider is enabled for transactions. Default: false |
| environment | string | No | Execution environment: `live` or `sandbox`. Default: sandbox |
| apiKey | string (encrypted) | No | Provider API key or secret (e.g., Mollie sk_*, Stripe sk_*, Adyen API key) |
| apiSecret | string (encrypted) | No | Additional secret for providers that require signing (e.g., CCV) |
| webhookSecret | string (encrypted) | No | Webhook signature verification key from the provider |
| testMode | boolean | No | If true, payment requests use test/dummy amounts for validation without charging. Default: true |
| config | object | No | Provider-specific nested config: `{ "terminalId": "kassa-01", "accountId": "...", "merchantId": "..." }` |
| lastTestedAt | string (date-time) | No | Timestamp of the last successful connection test |
| testResult | object | No | Result of the last test: `{ "status": "ok"|"error", "message": "..." }` |
| createdAt | string (date-time) | No | When this provider configuration was created |
| updatedAt | string (date-time) | No | When this provider configuration was last updated |

---

## Seed Data

Seed objects added to `lib/Settings/pipelinq_register.json` under `components.objects[]` using the `@self` envelope.

### paymentProvider seeds (4 objects)

```json
[
  {
    "@self": { "register": "pipelinq", "schema": "paymentProvider", "slug": "provider-mollie" },
    "name": "mollie",
    "displayName": "Mollie",
    "type": "online",
    "isActive": false,
    "environment": "sandbox",
    "apiKey": "{{ENCRYPTED: test_dummy_key}}",
    "webhookSecret": "{{ENCRYPTED: test_signature_key}}",
    "testMode": true,
    "config": {},
    "lastTestedAt": null,
    "testResult": null
  },
  {
    "@self": { "register": "pipelinq", "schema": "paymentProvider", "slug": "provider-ccv" },
    "name": "ccv",
    "displayName": "CCV",
    "type": "terminal",
    "isActive": false,
    "environment": "sandbox",
    "apiKey": "{{ENCRYPTED: test_dummy_key}}",
    "apiSecret": "{{ENCRYPTED: test_dummy_secret}}",
    "webhookSecret": "{{ENCRYPTED: test_signature_key}}",
    "testMode": true,
    "config": { "terminalId": "kassa-01" },
    "lastTestedAt": null,
    "testResult": null
  },
  {
    "@self": { "register": "pipelinq", "schema": "paymentProvider", "slug": "provider-adyen" },
    "name": "adyen",
    "displayName": "Adyen",
    "type": "terminal",
    "isActive": false,
    "environment": "sandbox",
    "apiKey": "{{ENCRYPTED: test_dummy_key}}",
    "webhookSecret": "{{ENCRYPTED: test_signature_key}}",
    "testMode": true,
    "config": {},
    "lastTestedAt": null,
    "testResult": null
  },
  {
    "@self": { "register": "pipelinq", "schema": "paymentProvider", "slug": "provider-stripe" },
    "name": "stripe",
    "displayName": "Stripe",
    "type": "online",
    "isActive": false,
    "environment": "sandbox",
    "apiKey": "{{ENCRYPTED: pk_test_dummy}}",
    "apiSecret": "{{ENCRYPTED: sk_test_dummy}}",
    "webhookSecret": "{{ENCRYPTED: whsec_test_signature}}",
    "testMode": true,
    "config": {},
    "lastTestedAt": null,
    "testResult": null
  }
]
```

### posTransaction seeds (extended with payment fields)

Existing posTransaction seeds are updated to include the new payment fields:

```json
[
  {
    "@self": { "register": "pipelinq", "schema": "posTransaction", "slug": "txn-2026-0001" },
    "reference": "TXN-2026-0001",
    "cashier": "admin",
    "terminalId": "kassa-01",
    "status": "settled",
    "subtotal": 19.75,
    "discountTotal": 0,
    "taxBreakdown": [{ "rate": 9, "base": 19.75, "tax": 1.78 }],
    "totalTax": 1.78,
    "total": 21.53,
    "paymentProvider": "mollie",
    "paymentSessionId": "tr_WDqYK6vllg",
    "paymentStatus": "settled",
    "paymentMethod": "ideal",
    "confirmedAt": "2026-05-20T09:14:00+02:00",
    "settledAt": "2026-05-20T09:14:30+02:00",
    "notes": "Mollie iDEAL betaling"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTransaction", "slug": "txn-2026-0002" },
    "reference": "TXN-2026-0002",
    "cashier": "jan.smit",
    "terminalId": "kassa-02",
    "status": "draft",
    "subtotal": 0,
    "discountTotal": 0,
    "taxBreakdown": [],
    "totalTax": 0,
    "total": 0,
    "paymentProvider": null,
    "paymentStatus": "pending"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTransaction", "slug": "txn-2026-0003" },
    "reference": "TXN-2026-0003",
    "cashier": "emma.bakker",
    "terminalId": "kassa-01",
    "status": "confirmed",
    "subtotal": 89.97,
    "discountTotal": 9.00,
    "taxBreakdown": [
      { "rate": 21, "base": 80.97, "tax": 17.00 }
    ],
    "totalTax": 17.00,
    "total": 97.97,
    "paymentProvider": "ccv",
    "paymentSessionId": "CCV20260520102833001",
    "paymentStatus": "captured",
    "paymentMethod": "card",
    "confirmedAt": "2026-05-20T10:33:00+02:00",
    "notes": "CCV PIN terminal betaling"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTransaction", "slug": "txn-2026-0004" },
    "reference": "TXN-2026-0004",
    "cashier": "jan.smit",
    "terminalId": "kassa-03",
    "status": "parked",
    "subtotal": 24.95,
    "discountTotal": 0,
    "taxBreakdown": [{ "rate": 9, "base": 24.95, "tax": 2.25 }],
    "totalTax": 2.25,
    "total": 27.20,
    "paymentProvider": null,
    "paymentStatus": "pending",
    "parkedAt": "2026-05-20T11:02:00+02:00",
    "notes": "Klant even weg — betaling later"
  },
  {
    "@self": { "register": "pipelinq", "schema": "posTransaction", "slug": "txn-2026-0005" },
    "reference": "TXN-2026-0005",
    "cashier": "admin",
    "terminalId": "kassa-01",
    "status": "refunded",
    "subtotal": 703.30,
    "discountTotal": 0,
    "taxBreakdown": [{ "rate": 21, "base": 703.30, "tax": 147.69 }],
    "totalTax": 147.69,
    "total": 850.99,
    "paymentProvider": "stripe",
    "paymentSessionId": "pi_1ABCDefGHIjklmno",
    "paymentStatus": "refunded",
    "paymentMethod": "card",
    "confirmedAt": "2026-05-19T14:22:00+02:00",
    "settledAt": "2026-05-19T14:23:00+02:00",
    "refundedAt": "2026-05-20T09:45:00+02:00",
    "refundReason": "Artikel beschadigd bij levering — retour verwerkt",
    "notes": "Stripe betaling gerefundeerd"
  }
]
```

---

## Backend

### PaymentProviderInterface (`lib/Service/Payment/PaymentProviderInterface.php`)

Abstract contract for payment provider implementations.

```php
interface PaymentProviderInterface {
    public function initiate(array $transactionData, float $amount, string $paymentMethod): array;
    public function capture(string $sessionId): array;
    public function refund(string $sessionId, string $reason): array;
    public function validateWebhook(array $payload, string $signature): bool;
}
```

Methods return:
- `initiate()` → `{ "sessionId": "...", "redirectUrl": "...|null", "status": "pending"|"failed" }`
- `capture()` → `{ "sessionId": "...", "status": "captured"|"failed", "error": "...|null" }`
- `refund()` → `{ "sessionId": "...", "status": "refunded"|"failed", "refundId": "...", "error": "...|null" }`

### Provider Adapters

Four concrete implementations of `PaymentProviderInterface`:

| Class | File | Description |
|-------|------|-------------|
| `MollieAdapter` | `lib/Service/Payment/MollieAdapter.php` | iDEAL, Bancontact, card payments via Mollie API |
| `CcvAdapter` | `lib/Service/Payment/CcvAdapter.php` | PIN terminal and card payments via CCV Gateway API |
| `AdyenAdapter` | `lib/Service/Payment/AdyenAdapter.php` | Terminal and online payments via Adyen API |
| `StripeAdapter` | `lib/Service/Payment/StripeAdapter.php` | Card and wallet payments via Stripe API |

Each adapter:
- Calls the provider's API to initiate payment sessions
- Handles provider-specific response formats
- Validates webhook signatures using provider-specific algorithms
- Maps provider-specific payment method names to normalized values (card, ideal, bancontact, etc.)

### PosPaymentService (`lib/Service/PosPaymentService.php`)

Orchestrates payment operations and webhook routing.

| Method | Description |
|--------|-------------|
| `getPaymentProvider(string $name): PaymentProviderInterface` | Load provider adapter by name; throw if not found or inactive |
| `initiatePayment(string $transactionId, string $providerName, string $paymentMethod): array` | Load provider, call `initiate()`, update transaction with sessionId and paymentStatus=pending |
| `capturePayment(string $transactionId, string $sessionId): array` | Load provider from transaction, call `capture()`, update transaction with paymentStatus=captured |
| `refundPayment(string $transactionId, string $reason): array` | Validate status is settled, load provider, call `refund()`, update transaction with paymentStatus=refunded + refundReason |
| `handleWebhook(string $providerName, array $payload, string $signature): array` | Validate signature via provider adapter; route to settlement handler; return event ID + status |
| `handleSettlement(string $sessionId, array $settlementData): void` | Find posTransaction with matching sessionId; update paymentStatus=settled; emit event to Shillinq |
| `testConnection(string $providerName): array` | Load provider, attempt test API call, store result in provider config |

### PosPaymentController (`lib/Controller/PosPaymentController.php`)

Thin controller delegating all logic to `PosPaymentService`.

| Method | URL | Action |
|--------|-----|--------|
| POST | `/api/pos-payments/{id}/initiate` | Initiate payment with `providerName` and `paymentMethod` body params |
| POST | `/api/pos-payments/{id}/capture` | Capture payment |
| POST | `/api/pos-payments/{id}/refund` | Refund payment with `reason` body param |
| POST | `/webhook/{provider}` | Receive settlement webhook from provider (routes via WebhookRouter) |
| POST | `/api/payment-providers/{name}/test` | Test provider connection (admin only) |
| GET | `/api/payment-providers` | List configured providers with status (admin only) |
| PUT | `/api/payment-providers/{name}` | Update provider credentials and config (admin only) |

---

## Credential Storage & Encryption

Payment provider credentials (API keys, secrets, webhook signatures) are stored in `IAppConfig` under the `pipelinq` namespace. All encrypted fields are encrypted at rest using Nextcloud's `\OC\Encryption\Util` encryption service.

Example `IAppConfig` keys:
- `pipelinq.payment_provider.mollie.apiKey` → encrypted value
- `pipelinq.payment_provider.mollie.webhookSecret` → encrypted value
- `pipelinq.payment_provider.ccv.apiKey` → encrypted value
- `pipelinq.payment_provider.stripe.apiSecret` → encrypted value

Decryption happens only when:
1. An API request requires the credential (e.g., initiating a payment)
2. An admin requests the provider configuration for editing (masked in API response)

Credentials are NEVER logged, exposed in stack traces, or included in seed data — seeds contain placeholder `{{ENCRYPTED: ...}}` strings that are replaced during setup.

---

## Frontend

### Routes (added to `src/router/index.js`)

- `/settings/payment` — PaymentSettingsForm

### Store (added to `src/store/store.js`)

```js
objectStore.registerObjectType('paymentProvider', 'paymentProvider', 'pipelinq')
```

### Views

**PaymentSettingsForm.vue** (`src/views/settings/PaymentSettingsForm.vue`)
- Admin-only form to configure payment provider credentials
- Four provider panels: Mollie, CCV, Adyen, Stripe (one per provider)
- Each panel:
  - Toggle: "Enable this provider"
  - Environment selector: `sandbox` or `live`
  - API key input (masked, not revealed after save)
  - API secret input (masked)
  - Webhook secret input (masked)
  - Provider-specific fields (e.g., CCV terminal ID)
  - Test button: calls `/api/payment-providers/{name}/test`, shows result (green checkmark or error)
  - Last tested timestamp

### Components

**PaymentMethodSelector.vue** (`src/components/pos/PaymentMethodSelector.vue`)
- Appears on PosTransactionForm when confirming payment
- Dropdown: Cash, Voucher, Account Sale, Mollie (if enabled), CCV (if enabled), Adyen (if enabled), Stripe (if enabled)
- On selection of a card provider, shows loading spinner until provider redirects back to app

---

## Reuse Analysis

| Platform Capability | Usage in this change |
|---------------------|----------------------|
| `IAppConfig` | Encrypted storage of provider credentials |
| `ObjectService.saveObject()` | Update posTransaction with payment fields |
| `\OC\Encryption\Util` | Credential encryption / decryption |
| `WebhookService` | CloudEvent emission to Shillinq on settlement |
| `AuthorizationService` | Admin-only access to payment settings |
| `CnFormDialog` | Provider credential forms |

No custom database tables, no new authentication methods, no new search endpoints are needed.

---

## Deduplication Check

- **pos-transaction-core** handles transaction lifecycle (draft → confirmed → settled). This change extends it with payment provider fields. No overlap.
- **No existing payment provider integration** found in `openregister/lib/Service/` or `pipelinq/lib/Service/`.
- Webhook routing is provider-generic and does not duplicate `WebhookService` — it uses it.

---

## Files Changed

### New Files

| File | Description |
|------|-------------|
| `lib/Service/Payment/PaymentProviderInterface.php` | Abstract contract |
| `lib/Service/Payment/MollieAdapter.php` | Mollie implementation |
| `lib/Service/Payment/CcvAdapter.php` | CCV implementation |
| `lib/Service/Payment/AdyenAdapter.php` | Adyen implementation |
| `lib/Service/Payment/StripeAdapter.php` | Stripe implementation |
| `lib/Service/PosPaymentService.php` | Payment orchestration |
| `lib/Controller/PosPaymentController.php` | API endpoints |
| `src/views/settings/PaymentSettingsForm.vue` | Admin credential form |
| `src/components/pos/PaymentMethodSelector.vue` | Payment method dropdown |

### Modified Files

| File | Change |
|------|--------|
| `lib/Settings/pipelinq_register.json` | Add paymentProvider schema + 4 seed objects; extend posTransaction with payment fields |
| `appinfo/routes.php` | Add payment API routes |
| `src/router/index.js` | Add `/settings/payment` route |
| `src/store/store.js` | Register paymentProvider object type |
| `src/views/pos/PosTransactionForm.vue` | Add `PaymentMethodSelector` before confirm button |
| `src/navigation/SettingsMenu.vue` | Add "Betalingsmethoden" settings link |

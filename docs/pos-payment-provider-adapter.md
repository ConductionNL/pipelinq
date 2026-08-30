# POS Pluggable Payment Provider Adapter

This document explains how to configure and operate the pluggable payment
provider adapter that ships with the Pipelinq POS module.

## Overview

The POS payment seam routes every card-present or card-not-present payment
through one of four provider adapters:

| Provider | Type      | Used for                             |
|----------|-----------|--------------------------------------|
| Mollie   | online    | iDEAL, Bancontact, creditcard        |
| CCV      | terminal  | PIN-terminal in retail / hospitality |
| Adyen    | terminal  | Multi-method terminal + online       |
| Stripe   | online    | Card + wallet (Apple/Google Pay)     |

Every adapter implements `PaymentProviderInterface` (`lib/Service/Payment/PaymentProviderInterface.php`)
with the same six methods (`initiate`, `capture`, `refund`, `validateWebhook`,
`parseWebhook`, `testConnection`). New providers can be added by writing a
new adapter; the controller + service do not need to change.

Money is integer-cent on every wire to the provider; `AbstractPaymentAdapter::toCents()`
converts the canonical decimal EUR `posTransaction.total` once at the boundary.

## Configuring a provider

1. Open the Nextcloud admin UI at `/index.php/apps/pipelinq/settings/payment`.
2. Toggle **"Enable this provider"** on the card you want to configure.
3. Choose **Sandbox** or **Productie** (live).
4. Paste the API key (and API secret / webhook secret where applicable) from
   the provider's dashboard. Already-stored secrets render as `***SET***`;
   leaving a field blank or showing the mask preserves the stored value.
5. Click **"Verbinding testen"** to confirm the credentials work. The
   result is recorded in `paymentProvider.testResult` and shown on the card.
6. Click **"Opslaan"** to persist the configuration.

All secret fields (`apiKey`, `apiSecret`, `webhookSecret`) are encrypted at
rest via Nextcloud's `ICrypto` service and stored in `IAppConfig` under
keys of the form `pipelinq.payment_provider.<name>.<field>`. They are never
returned in API responses (the controller masks them with `***SET***`) and
never logged.

### Provider-specific extra fields

| Provider | Extra fields needed in `paymentProvider.config` |
|----------|-------------------------------------------------|
| CCV      | `terminalId` — physical PIN-terminal id (e.g. `kassa-01`); `merchantId` for webhook signature |
| Adyen    | `merchantAccount` — Adyen merchant account name |

### Where to find your credentials

- **Mollie** — Dashboard → Developers → API keys (`live_…` or `test_…`).
  Webhook secret comes from the per-profile webhook settings.
- **CCV** — Onboarding email lists the API key + the per-terminal `terminalId`.
- **Adyen** — Customer Area → Developers → API Credentials. Webhook HMAC
  secret is set per Standard webhook in Customer Area.
- **Stripe** — Dashboard → Developers → API keys (`sk_…`). The webhook
  signing secret is shown when you create a webhook endpoint.

## Configuring webhooks

Each provider should be configured to POST settlement events to:

```
https://<your-nextcloud>/index.php/apps/pipelinq/api/pos-payment-webhook/<provider>
```

For example, Mollie's webhook URL is:

```
https://nc.example.com/index.php/apps/pipelinq/api/pos-payment-webhook/mollie
```

The endpoint is `PublicPage + NoCSRFRequired` — payment providers cannot
present a Nextcloud session cookie, so authenticity is enforced via the
provider-specific HMAC signature. The signature algorithm is provider-
specific (HMAC-SHA256 for Mollie / Adyen / Stripe; HMAC-SHA512 for CCV).
Invalid signatures return `HTTP 400 Bad Request` without updating any
transaction state.

## Operating model

### Initiating a payment

1. Cashier confirms a `posTransaction` (status `draft` → `confirmed`).
2. They pick a method in the **Betaalmethode** dropdown
   (`PaymentMethodSelector.vue`).
3. The frontend POSTs to `/api/pos-payments/{id}/initiate` with
   `{providerName, paymentMethod}`. `PosPaymentService::initiatePayment()`
   loads the adapter, calls `initiate()`, and stamps the transaction with
   `paymentProvider / paymentSessionId / paymentStatus="pending" /
   paymentMethod`. If the provider returns a `redirectUrl`, the browser
   navigates there (Mollie flow).
4. The provider eventually POSTs a settlement webhook. The endpoint
   validates the signature, calls `parseWebhook()` to extract the
   normalised status, and persists the update through
   `PosPaymentService::handleSettlement()`. Idempotency is keyed on
   `paymentWebhookEventId` — a duplicate webhook never re-mutates the
   transaction.

### Refunds

1. A POS manager opens the detail view of a settled transaction.
2. They click **"Terugboeken"** in the **Betaling** card.
3. The frontend POSTs to `/api/pos-payments/{id}/refund` with `{reason}`.
4. `PosPaymentService::refundPayment()` verifies the caller is in the
   `pos_managers` group (or is a Nextcloud admin) and that the transaction's
   `paymentStatus` is `settled` or `captured`, then calls the adapter's
   `refund()` and stamps `paymentStatus="refunded" / refundReason /
   refundedAt`.
5. A `pipelinq.PosPayment.refunded` CloudEvent is emitted to Shillinq.

### Testing in sandbox

Every provider supports a sandbox / test mode. Always configure sandbox
credentials before going live, and set `testMode=true` on the
`paymentProvider` record to be doubly sure no live charges happen during
configuration.

### Testing checklist

- [ ] Each enabled provider passes **"Verbinding testen"** (green badge,
      `testResult.status="ok"`).
- [ ] A sandbox payment for each enabled provider returns a `sessionId`
      and transitions to `paymentStatus=pending`.
- [ ] The settlement webhook arrives at `/api/pos-payment-webhook/<name>`
      with a valid signature and transitions the transaction to
      `paymentStatus=settled`.
- [ ] A duplicate settlement webhook (same provider event id) is reported
      as `status=duplicate` and does NOT re-save the transaction.
- [ ] A refund initiated by a POS manager moves the transaction to
      `paymentStatus=refunded` and emits a `pipelinq.PosPayment.refunded`
      CloudEvent.
- [ ] An invalid webhook signature returns `HTTP 400` and does NOT mutate
      any transaction.

## CloudEvents emitted

| Event type                          | Source                            | Subscriber                |
|-------------------------------------|-----------------------------------|---------------------------|
| `pipelinq.PosPayment.settled`       | `/apps/pipelinq/pos/payment`      | Shillinq accounting       |
| `pipelinq.PosPayment.refunded`      | `/apps/pipelinq/pos/payment`      | Shillinq accounting       |

The `data` block carries `transactionId`, `reference`, `paymentProvider`,
`paymentMethod`, `paymentSessionId`, `total` and the settled/refunded
timestamp.

## Files

| File                                            | Purpose                                  |
|-------------------------------------------------|------------------------------------------|
| `lib/Service/Payment/PaymentProviderInterface.php` | Adapter contract                      |
| `lib/Service/Payment/AbstractPaymentAdapter.php`   | Shared base (cents conversion, transport seam, secret-safe logging) |
| `lib/Service/Payment/MollieAdapter.php`            | Mollie implementation                 |
| `lib/Service/Payment/CcvAdapter.php`               | CCV PIN-terminal implementation       |
| `lib/Service/Payment/AdyenAdapter.php`             | Adyen implementation                  |
| `lib/Service/Payment/StripeAdapter.php`            | Stripe implementation                 |
| `lib/Service/Payment/HttpTransport.php`            | HTTP transport seam (interface)       |
| `lib/Service/Payment/CurlHttpTransport.php`        | cURL implementation w/ 5s timeout     |
| `lib/Service/PosPaymentService.php`                | Orchestration + credential encryption + webhook + CloudEvent emission |
| `lib/Controller/PosPaymentController.php`          | API endpoints                         |
| `src/views/settings/PaymentSettingsForm.vue`       | Admin credential form                 |
| `src/components/pos/PaymentMethodSelector.vue`     | Cashier method dropdown               |
| `src/components/pos/PaymentStatusCard.vue`         | Detail-view status + action buttons   |

## Security model

- Credentials encrypted at rest via `ICrypto` — never logged, never
  returned in API responses.
- Webhook endpoint is `#[PublicPage] + #[NoCSRFRequired]` with HMAC
  signatures as the sole authenticity boundary (per ADR-005).
- Refund is gated on the `pos_managers` group (Nextcloud admins always
  pass). The admin group name is configurable via
  `appConfig: pipelinq.pos_managers_group`.
- All API endpoints reject malformed payloads with `HTTP 422` and stale
  / unauthorized requests with `HTTP 401 / 403`.

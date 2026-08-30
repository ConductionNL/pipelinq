<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# WhatsApp and SMS messaging

Pipelinq supports omnichannel customer messaging on WhatsApp and SMS
via the `whatsapp-sms-channel-adapter` change. This page covers
provider setup, agent usage, and the compliance defaults.

## Supported providers

| Channel  | Vendors                                            |
| -------- | -------------------------------------------------- |
| WhatsApp | Meta Cloud API (direct), Twilio (BSP), 360dialog   |
| SMS      | Twilio, MessageBird (Bird.com), CM.com, Vonage     |

All HTTP transport is delegated to `openconnector` (ADR-005). Pipelinq
holds the per-tenant credentials in the encrypted `channelProvider`
schema and never bundles a vendor SDK.

## Provider setup checklist

1. Open `Settings → Pipelinq → Messaging providers`.
2. Create one `channelProvider` row per sender:
   - `kind`: `whatsapp-cloud-api`, `whatsapp-bsp` or `sms`.
   - `vendor`: `meta`, `twilio`, `messagebird`, `cm-com`, `360dialog`,
     `vonage`.
   - `phoneNumber`: E.164 sender number (or vendor account id).
   - `webhookSecret`: shared HMAC secret used to verify inbound
     webhooks.
   - `priority`: lower wins on failover.
   - `active`: must be `true` for the adapter to route to it.
   - `credentials`: vendor-specific bag (encrypted at rest).
3. Register the openconnector source matching the vendor and copy
   the source id onto the provider row's `sourceId` field.
4. Point the provider's webhook URL at:
   - WhatsApp: `https://<host>/index.php/apps/pipelinq/api/messaging-webhooks/whatsapp/<providerId>`
   - SMS:      `https://<host>/index.php/apps/pipelinq/api/messaging-webhooks/sms/<providerId>`
   Both endpoints are `PublicPage` + `NoCSRFRequired`; pipelinq
   verifies authenticity by HMAC signature on every request and
   returns `400 BAD_REQUEST` on any mismatch.

## Compliance defaults

- **Consent** — `ConsentService.canSend()` is the gate placed in front
  of every outbound send. Records are append-only; a contact's most
  recent `messagingConsentRecord` per channel decides the state.
- **STOP keywords** — `STOP`, `STOPALL` and `UITSCHRIJVEN` are
  detected case-insensitively on every inbound message; an
  `opted-out` record is appended automatically and (on WhatsApp) an
  acknowledgement is sent within the open session window.
- **Opt-in reactivation** — `JA`, `YES` and `START` write a new
  `opted-in` record without overwriting the older opt-out (audit
  history is preserved).
- **GDPR erasure** — `ClientManagementIntegration.onContactDeleted()`
  cascades to `ConsentService.deleteForContact()`, removing every
  `messagingConsentRecord` for the contact.

## Send budgets

`messageSendBudget` rows cap per-tenant, per-provider spend. The
options are:

- `hardStop: true` — refuses every send past the cap with
  `budgetExceeded`. No provider API call is made.
- `hardStop: false` — sends proceed, but the admin receives a single
  notification per period when crossing `alertThresholdPct`.

`BudgetPeriodResetJob` rolls every budget whose `periodResetAt` has
passed (default: daily). Cost capture is supplied by
`CostCaptureService`: it reads `Price`/`PriceUnit` (Twilio) or falls
through to `CostEstimationService` (static price table) for vendors
that don't expose per-message cost (Meta Cloud API).

## Session window (WhatsApp only)

Meta enforces a 24-hour customer-service window for free-form
WhatsApp messages. `WhatsAppAdapter.send()`:

- Free-form: requires an inbound message on the contact in the last
  24h, otherwise returns `sessionWindowExpired`.
- Template: works regardless of window provided the template's
  `status` is `approved`. Parameter count must match the `{{N}}`
  placeholders or the adapter returns `templateParameterMismatch`.

## Background jobs

| Job                       | Interval | Purpose                                    |
| ------------------------- | -------- | ------------------------------------------ |
| `TemplateApprovalSyncJob` | 1 hour   | Pull Meta / BSP template status            |
| `BudgetPeriodResetJob`    | 1 day    | Reset `currentPeriodMessages`/`CostEur`    |
| `CostReconciliationJob`   | 1 day    | Retry deferred EUR conversion              |
| `WebhookProcessorJob`     | 1 minute | Drain the internal `webhookQueue` schema   |

## Agent guide (short)

- Pick a contact and switch to the WhatsApp or SMS tab.
- Inside the session window: type and send. Outside it: pick an
  approved template from the picker.
- A red "STOP-uitgeschreven" banner appears on contacts who have
  opted out — the send button is disabled until an agent (or the
  contact) records a new opt-in.
- Inbound media downloads automatically within Meta's 5-minute
  expiry window; the file is virus-scanned and linked from the
  message under `/<user>/files/pipelinq/conversations/<id>/media/`.

# Outbound Messaging (WhatsApp / SMS)

Pipelinq sends outbound WhatsApp and SMS messages to clients and contacts —
from an agent on a Client or Contact detail page, and automatically as part of
an SLA escalation chain. Every message is consent-gated, budget-gated, template
-gated (WhatsApp), transported through OpenRegister's `MessageDispatchProvider`
leaf (credentials live on an OpenConnector source, never in pipelinq) and
audited as a `contactmoment`.

Inbound WhatsApp/SMS ingestion (webhooks, consent capture, conversation
threading) is documented separately; this page covers the **outbound** side
wired by the `outbound-messaging-provider-wiring` change.

## Providers

| Role | Provider | OpenConnector source | Notes |
|------|----------|----------------------|-------|
| Primary SMS + zero-cost test ring | **Bird** (ex-MessageBird) | `messagebird-sms` | Amsterdam HQ, EU residency; `test_…` access keys validate a send request without dispatching, at zero cost |
| SMS fallback | **CM.com** | `cmcom-sms` | Breda HQ, Premier BSP; used when a tender requires a Dutch Premier-BSP with central-government references |
| **Default production WhatsApp** | **CM.com BSP** | `whatsapp-bsp` | Owner decision 2026-07-06 ("gov friendly") — Premier BSP, Breda HQ, Dutch central-government references (ministries, DUO, CJIB) |
| WhatsApp CI/dev harness + per-tenant opt-in | **Meta Cloud API** | `whatsapp-cloud-api` | Free test number + up to 5 test recipients returning real `wamid.*` and production webhooks; an explicit per-tenant opt-in alternative (no platform fee) |

`channelProvider` rows are per-tenant configuration, so the WhatsApp leg is a
routing choice per tenant — the seed default points at `whatsapp-bsp` for
production.

**Deferred (consciously, no OR/OC issue filed):** a Bird-native WhatsApp leg
would need a new OpenConnector `bird-whatsapp` source **and** an addition to
OpenRegister's `MessageDispatchProvider::ALLOWED_SOURCES`
(`cmcom-sms`, `messagebird-sms`, `twilio-sms`, `whatsapp-cloud-api`,
`whatsapp-bsp`). With CM.com `whatsapp-bsp` as the default and Meta as the
harness/opt-in, there is no demand driver — revisit only on tenant demand.

## Configuring a provider (admin)

Settings → **Messaging (WhatsApp/SMS)**:

1. Create a `channelProvider` row: pick the `kind` (`sms` / `whatsapp-cloud-api`
   / `whatsapp-bsp`), the `vendor`, the OpenConnector `sourceId`, the sender
   `phoneNumber`, a `webhookSecret`, and a failover `priority` (lower wins).
   There is **no credential field** — vendor credentials live exclusively on the
   OpenConnector source (`MessageDispatchProvider` never handles a credential).
2. Copy the inbound webhook URL shown per provider
   (`/apps/pipelinq/api/messaging-webhooks/{whatsapp|sms}/{providerId}`) into the
   provider console.
3. Run the **connectivity test** — a zero-cost request through the OR leaf. A
   mock-flagged source returns its canned response with a "mock mode" badge; a
   degraded leaf shows the cause (source missing / leaf unavailable) rather than
   a 500.

## Going live

The seeded OpenConnector sources ship `configuration.mock: true` with a
realistic `mockResponse`, so the whole pipeline runs network-free in CI. To go
live for a source:

1. Add the vendor credentials to the **OpenConnector source** (admin-owned).
2. Remove `configuration.mock` from the source.

No pipelinq change is required to go live — the app addresses the source by
slug and the credential home is OpenConnector.

## Consent model

- **SMS** and **within-window WhatsApp replies**: blocked only by an
  `opted-out` record (agent-initiated service communication;
  Telecommunicatiewet service grounds). Bulk/marketing consent is owned by the
  marketing-compliance capability, not here.
- **Business-initiated WhatsApp** (template sends outside the 24h session window,
  including SLA escalations): require the contact's latest
  `messagingConsentRecord` for whatsapp to be `opted-in` (Meta business-messaging
  policy). An absent or `unknown` record does **not** pass.

Consent state is shown per channel on the send surface; opt-in/opt-out is
recorded there through `ConsentService` with mandatory evidence + legal basis
(never a raw frontend object write), attributed to the acting agent.

## SLA escalation dispatch

An SLA escalation step with `channel: sms` or `channel: whatsapp` and
`notify: customer` dispatches to the breached object's linked client/contact
through the channel adapters. Outcomes are recorded in the breach event's
`notifiedActors` using the marker vocabulary: the contact identifier on success,
or `consent-missing:<channel>`, `template-missing:whatsapp`,
`unsupported:<channel>:<role>` (non-customer roles have no phone seam),
`failed:<channel>`, `deferred:<channel>:<role>` (email/webhook, delegated to
their own capabilities). A whatsapp escalation is business-initiated: the step's
optional `templateId` is required unless the contact has an open 24h session
window. `email` and `webhook` escalation channels keep the `deferred:` marker.

## Outbound audit

Every successful outbound send (agent composer or SLA escalation) writes a
`contactmoment` through the unified `ContactmomentService` write path: WhatsApp
as `channel: chat` with `channelMetadata.platform = whatsapp`, SMS as the
`channel: sms` enum value, both with `direction: outbound`, `messageId` and
`conversationId`. The audit write is log-and-continue — it never blocks, fails,
or rolls back the send.

## Testing

- **CI contract ring (default, network-free):** PHPUnit
  `tests/Integration/OutboundMessagingContractTest.php` drives the adapters with
  the canned mock-mode vendor shapes and asserts the persisted message row + the
  contactmoment audit; `SlaEngineDispatchTest` covers the escalation dispatch
  matrix; Newman covers the send / preflight / consent / provider-test
  endpoints; Playwright (`tests/e2e/spec-coverage/outbound-messaging.spec.ts`)
  covers the settings + composer surfaces.
- **Live gate (env-guarded, `tests/Integration/OutboundMessagingLiveGateTest.php`):**
  SMS via a Bird `test_…` access key (`BIRD_TEST_ACCESS_KEY`, request validated
  at zero cost, nothing sent) and WhatsApp via the Meta test number
  (`META_WA_TEST_TOKEN` + `META_WA_TEST_PHONE_ID` + `META_WA_TEST_RECIPIENT`,
  real `wamid.*`). Enabled with `PIPELINQ_LIVE_MESSAGING=1`; **skipped, never
  failed, when the env credentials are absent.**

  > **Not live-verified in this delivery.** Live provider keys were not available
  > when this change shipped, so the live-gate leg is config-ready-but-untested.
  > The mock-mode contract ring is fully green; a live smoke send (and the Bird
  > no-delivery-callback gap — status flows are exercised by webhook fixtures)
  > stays a runbook item.

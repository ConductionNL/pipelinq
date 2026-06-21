---
kind: code
depends_on: [messaging-dispatch-leaf]
---

## Why

Pipelinq's outbound messaging clients — `TwilioSmsClient`, `MessageBirdSmsClient`, `CmComSmsClient`, and `WhatsAppProviderClient` — dispatch their transport leg through `OCA\OpenConnector\Service\SourceService::executeAction($sourceId, $action, $payload)`. **That method does not exist on `SourceService`.** Every SMS and WhatsApp send therefore reaches the `method_exists(...)` guard, which throws `PermanentSmsProviderException('openconnector SourceService lacks executeAction')`. As a result **every live SMS/WhatsApp send currently fails permanently** — the failover loop in `SmsAdapter` exhausts all providers on permanent errors (it does not retry them) and persists the message as `failed`.

OpenRegister just shipped the messaging-dispatch leaf (`OCA\OpenRegister\Service\Integration\Providers\MessageDispatchProvider`, per ADR-022 — the connection + credentials live in OpenConnector, addressed by a seeded source slug). Its `dispatch(source, body, path, headers)` method POSTs the consuming app's vendor-shaped body through the OpenConnector source and round-trips the raw provider response, degrading null-safely to `{ unavailable, cause }` (AD-23) instead of throwing. This change re-points pipelinq's transport leg onto that leaf, which **fixes the live bug** while preserving the adapters' observable contract.

## What Changes

Re-points **only the transport leg** of the four messaging clients — every other behaviour in the adapters is untouched.

- In `TwilioSmsClient::dispatchViaOpenConnector()`, `MessageBirdSmsClient::dispatchViaOpenConnector()`, `CmComSmsClient::dispatchViaOpenConnector()`, and `WhatsAppProviderClient::dispatch()`, replace the dead `SourceService::executeAction($sourceId, $action, $payload)` call with an in-process call to `MessageDispatchProvider::dispatch(source: <slug>, body: <the vendor-shaped payload the client already builds>, path: <vendor send path>, headers: [...])`, reading back `['response']` for the provider message id (Twilio `sid`, MessageBird `id`, CM.com `messageId`, Meta `wamid`).
- `channelProvider.sourceId` (a.k.a. `openconnectorSourceId`) is the OpenConnector source **slug** the leaf dispatches through (`cmcom-sms` / `messagebird-sms` / `twilio-sms` / `whatsapp-cloud-api` / `whatsapp-bsp`).
- `MessageDispatchProvider` is DI-resolved from the container, guarded by `class_exists(...)` + container-miss; on a miss the client throws the **same** `PermanentSmsProviderException` it throws today, so the existing failover loop behaves identically when OpenRegister's leaf is absent.
- On the leaf's degraded `{ unavailable: true, cause }` return the client maps the `cause` to the **same** provider-exception type it used before: `openconnector-source-missing` / `provider-auth` → `PermanentSmsProviderException`; `openconnector-down` / `upstream-service-down` (and any unknown cause) → `TransientSmsProviderException`. So `SmsAdapter` / `WhatsAppAdapter` failover, retry, delivery-status reconciliation, and persistence behave exactly as before — but a degraded source now **degrades non-fatally** rather than the call throwing on the missing `executeAction`.

Everything else in the adapters is **unchanged**: provider selection / hint pinning, consent + budget gating, STOP-keyword webhook, template-approval sync, 24h session-window enforcement, dedupe, and persistence.

This is strictly better than today (degrade-non-fatal vs. always-throw) while preserving the adapters' observable contract.

## Capabilities

### Modified Capabilities

- `messaging-channel` — the SMS/WhatsApp outbound transport leg routes through the OpenRegister `MessageDispatchProvider` leaf (ADR-022) instead of the non-existent `SourceService::executeAction`, fixing the always-fail send bug while preserving failover semantics.

## Impact

- **Code**: `lib/Service/Provider/TwilioSmsClient.php`, `lib/Service/Provider/MessageBirdSmsClient.php`, `lib/Service/Provider/CmComSmsClient.php`, `lib/Service/WhatsAppProviderClient.php` — transport leg only.
- **Depends on** `messaging-dispatch-leaf` (OpenRegister). When that leaf is absent at runtime the clients degrade to the same `PermanentSmsProviderException` as today (no regression); when present, sends route through it.
- **Operator step (later cutover)**: in OpenConnector, configure + enable the five messaging sources (`cmcom-sms`, `messagebird-sms`, `twilio-sms`, `whatsapp-cloud-api`, `whatsapp-bsp`) with the vendor credentials + base URL, and set each `channelProvider.sourceId` to the matching slug. Until then sends degrade non-fatally (queued/failed per the adapter's existing reconciliation) instead of throwing.
- No data migration, no schema change, no new route, no Vue change.

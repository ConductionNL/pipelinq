# Design — messaging transport via the OpenRegister dispatch leaf

## The re-point

Each messaging client builds a vendor-shaped payload (Twilio `From/To/Body` form fields, MessageBird `originator/recipients/body`, CM.com `from/to/body`, Meta `messaging_product` JSON template/text). The transport leg previously called:

```php
$sourceService = $this->container->get('OCA\\OpenConnector\\Service\\SourceService');
$result = $sourceService->executeAction($this->sourceId, $action, $payload); // method does not exist → throws
```

It now calls the OpenRegister leaf instead:

```php
$provider = $this->resolveDispatchProvider(); // class_exists + container guard
$result   = $provider->dispatch(
    source: $this->sourceId,   // OpenConnector source slug
    body:   $payload,          // the vendor-shaped body the client already builds
    path:   $sendPath,         // vendor send path (e.g. Messages.json, {PhoneNumberID}/messages)
    headers: $headers,         // optional per-call Content-Type
);
```

`MessageDispatchProvider::dispatch()` (OpenRegister) returns either:
- success: `{ status: 'sent', source, response }` — the client reads `response` and extracts the provider message id exactly as it read the old `executeAction` result; or
- degraded: `{ unavailable: true, cause }` — the leaf never throws on a missing/down source (AD-23).

The leaf owns the connection + credentials in OpenConnector (ADR-022); pipelinq passes only a **source slug** plus the vendor-shaped body, so no vendor SDK or credential ever lives in pipelinq.

### Send paths

| Client | source slug | path |
| --- | --- | --- |
| Twilio | `twilio-sms` | `Messages.json` |
| MessageBird | `messagebird-sms` | `messages` |
| CM.com | `cmcom-sms` | `messages` |
| WhatsApp (template / free-form) | `whatsapp-cloud-api` / `whatsapp-bsp` | `messages` |
| WhatsApp (media download/upload, list-templates) | as above | `media` / `media` / `message_templates` |

Paths are relative to the source's admin-owned base URL; the seeded OpenConnector source pins the absolute vendor endpoint, account id and version, so pipelinq carries no vendor host.

## Degrade → exception mapping (preserves the adapter contract)

`SmsAdapter` / `WhatsAppAdapter` catch two exception types: `TransientSmsProviderException` (→ fail over to the next priority provider, then retry) and `PermanentSmsProviderException` (→ no failover, persist `failed`). The leaf's degraded `cause` is mapped to preserve this exactly:

| leaf `cause` | mapped exception | adapter behaviour |
| --- | --- | --- |
| `openconnector-source-missing` | `PermanentSmsProviderException` | no failover (was "source not configured" today) |
| `provider-auth` | `PermanentSmsProviderException` | no failover (was 4xx config today) |
| `openconnector-down` | `TransientSmsProviderException` | fail over (was "openconnector unavailable" today) |
| `upstream-service-down` | `TransientSmsProviderException` | fail over (was 5xx today) |
| unknown / missing cause | `TransientSmsProviderException` | fail over (safe default — matches network-failure handling) |

The `class_exists` / container-miss guard throws the **same** `PermanentSmsProviderException` the `method_exists($sourceService, 'executeAction')` guard throws today, so on an OR-leaf-absent instance the failover loop is byte-for-byte unchanged.

## What stays (documented fallback / unchanged)

- All adapter behaviour: provider selection + hint pinning, consent + budget gating, STOP-keyword inbound webhook, template-approval sync job, 24h session-window enforcement, dedupe, persistence + delivery-status reconciliation.
- Webhook signature verification (`verifySignature`) on every client — unchanged; it never went through `executeAction`.
- The `channelProvider` row shape (`sourceId` / `openconnectorSourceId`, `vendor`, `phoneNumber`, `webhookSecret`, `credentials`). `sourceId` now carries the **source slug** the leaf dispatches through.

## Operator step (later cutover)

In OpenConnector, configure + enable the five messaging sources with vendor credentials + base URL, and set each `channelProvider.sourceId` to the matching slug (`cmcom-sms` / `messagebird-sms` / `twilio-sms` / `whatsapp-cloud-api` / `whatsapp-bsp`). Until then a send degrades non-fatally (the adapter persists `queued`/`failed` per its existing reconciliation) instead of throwing on the missing `executeAction`.

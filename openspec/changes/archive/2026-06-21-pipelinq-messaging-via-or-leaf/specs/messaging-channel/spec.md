## MODIFIED Requirements

### Requirement: Outbound messaging transport routes through the OpenRegister dispatch leaf

The pipelinq outbound messaging clients (`TwilioSmsClient`, `MessageBirdSmsClient`, `CmComSmsClient`, `WhatsAppProviderClient`) SHALL dispatch their transport leg through the OpenRegister `MessageDispatchProvider` leaf (`dispatch(source, body, path, headers)`, ADR-022) instead of the non-existent `OCA\OpenConnector\Service\SourceService::executeAction`. Each client SHALL pass the OpenConnector source **slug** (one of `cmcom-sms`, `messagebird-sms`, `twilio-sms`, `whatsapp-cloud-api`, `whatsapp-bsp`) as `source`, the vendor-shaped payload it already composes as `body`, and the vendor send path as `path`, and SHALL read the provider message id from the leaf's `response`. The connection and credentials SHALL live in OpenConnector, not in pipelinq.

The clients SHALL preserve the observable contract of `SmsAdapter` / `WhatsAppAdapter`: a degraded `{ unavailable: true, cause }` leaf result SHALL be mapped to the same provider-exception type used before — `openconnector-source-missing` and `provider-auth` to `PermanentSmsProviderException` (no failover), `openconnector-down`, `upstream-service-down`, and any unknown cause to `TransientSmsProviderException` (failover) — and the absence of the leaf class (container miss) SHALL raise the same `PermanentSmsProviderException` as today, so failover, retry, delivery-status reconciliation, and persistence behave identically.

**Standards**: ADR-022 (apps consume OpenRegister abstractions; connection + credentials in OpenConnector), AD-23 (degrade-don't-throw)
**Feature tier**: V1 (SMS/WhatsApp channel)

#### Scenario: SMS send routes through the dispatch leaf

- **WHEN** `SmsAdapter` sends an SMS through a configured Twilio `channelProvider` whose `sourceId` is `twilio-sms`
- **THEN** `TwilioSmsClient` calls `MessageDispatchProvider::dispatch()` with `source: 'twilio-sms'`, a `body` carrying the Twilio `From`/`To`/`Body` form fields, and `path: 'Messages.json'`, and reads the returned `response['sid']` as the external message id — it does NOT call `SourceService::executeAction`

#### Scenario: WhatsApp template send routes through the dispatch leaf

- **WHEN** `WhatsAppAdapter` fires a template send through a `channelProvider` whose `sourceId` is `whatsapp-cloud-api`
- **THEN** `WhatsAppProviderClient` calls `MessageDispatchProvider::dispatch()` with `source: 'whatsapp-cloud-api'`, a Meta `messaging_product` template `body`, and `path: 'messages'`, and extracts the `wamid` from `response['messages'][0]['id']`

#### Scenario: Degraded source-missing leaf result fails permanently (no failover)

- **WHEN** the dispatch leaf returns `{ unavailable: true, cause: 'openconnector-source-missing' }` for a send
- **THEN** the client raises `PermanentSmsProviderException` and `SmsAdapter` does NOT fail over — it persists the message as `failed`, exactly as when the source was unconfigured before the re-point

#### Scenario: Degraded upstream-down leaf result is transient and fails over

- **WHEN** the dispatch leaf returns `{ unavailable: true, cause: 'upstream-service-down' }` (or `openconnector-down`, or an unknown cause) for a send
- **THEN** the client raises `TransientSmsProviderException` and `SmsAdapter` fails over to the next priority provider, exactly as on a 5xx before the re-point

#### Scenario: Leaf absent degrades to the same permanent error as today

- **WHEN** the `MessageDispatchProvider` class is not available on the instance (OpenRegister leaf not deployed) and a send is attempted
- **THEN** the client raises `PermanentSmsProviderException` (mirroring today's missing-`executeAction` guard) and the send fails non-fatally without a PHP fatal, leaving the failover loop unchanged

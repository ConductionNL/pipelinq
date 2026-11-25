## ADDED Requirements

### Requirement: Pipelinq holds no PSP API key

Pipelinq SHALL NOT accept, store, or transmit a PSP API key. Every outbound call to
Mollie, Stripe, Adyen or CCV SHALL go through OpenRegister's `CredentialBrokerService`,
carrying `{method, path, body}` plus a credential UUID and the credential owner's UID.

The transport SHALL reduce the adapter's URL to a **path**: the host is the broker's
host-lock, and an adapter that can name the host can name a different one. It SHALL
**strip** every broker-owned header (`Authorization`, `X-API-Key`, `apikey`) the adapter
built.

When the broker is unavailable, or a provider has no `credentialId`, the provider SHALL
fail closed with an explanatory error. There SHALL be no direct, app-authenticated
fallback path.

`PosPaymentService::updateProvider()` SHALL ignore a submitted `apiKey`/`apiSecret` rather
than persist it, and a repair step SHALL delete any stored before this release.

@e2e exclude Taking a real payment requires a live PSP account and moves money, so the
happy path cannot run in CI or against the dev instance. The security-relevant halves ARE
mechanically verified: `BrokerHttpTransportTest` pins the host-discard, the header-strip,
the fail-closed guards and the cURL tripwire; `PosPaymentServiceTest` pins that a submitted
key is not stored anywhere. The credential-picker UI is covered by the settings Playwright
run.

#### Scenario: A provider without a credential cannot take a payment

- **WHEN** an operator initiates a payment on a provider with no `credentialId`
- **THEN** the provider fails closed with a message naming the missing credential
- **AND** no outbound call to the PSP is made

#### Scenario: The adapter's auth header never reaches the PSP

- **WHEN** an adapter builds an `Authorization` header and calls through the broker transport
- **THEN** that header is stripped before the broker call
- **AND** the broker injects the real secret server-side

#### Scenario: A request routed around the broker is refused, not sent

- **WHEN** a request still carrying the broker-managed placeholder reaches the direct cURL transport
- **THEN** the request is NOT sent
- **AND** the failure is logged at error level

#### Scenario: A submitted API key is not persisted

- **WHEN** a client POSTs an `apiKey` to the provider-update endpoint
- **THEN** the key is not written to app config, encrypted or otherwise
- **AND** the submission is logged as ignored

### Requirement: The webhook secret remains app-held

`webhookSecret` SHALL remain stored by Pipelinq, encrypted at rest. It verifies an HMAC on
an INBOUND webhook — a local verify operation, not an outbound request header — so a
constrained outbound HTTP proxy cannot carry it. It SHALL move to the broker only when the
broker gains a sign/verify capability.

@e2e exclude Storage/verify behaviour with no user-visible surface — covered by
`PosPaymentServiceTest`.

#### Scenario: The webhook secret survives the migration

- **WHEN** the PSP keys are removed from app config
- **THEN** the stored `webhookSecret` is left untouched
- **AND** inbound webhook HMAC verification continues to work

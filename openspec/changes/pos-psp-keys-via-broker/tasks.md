# Tasks — pos-psp-keys-via-broker

## Task 1: BrokerHttpTransport

- [x] 1.1 New `BrokerHttpTransport implements HttpTransport` — routes an adapter's outbound
      call through OpenRegister's `CredentialBrokerService` (lazy `class_exists` +
      `Server::get`, mirroring the openbuild pattern).
- [x] 1.2 Reduce the adapter's full URL to a **path + query**. The host is the broker's
      host-lock: an adapter that can name the host can name a different one.
- [x] 1.3 **Strip** every broker-owned header (`Authorization`, `X-API-Key`, `apikey`)
      before the call. The broker discards caller-supplied auth anyway; dropping them here
      means a stale header can never look like it is doing something.
- [x] 1.4 Fail closed with no broker and with no credential. No direct-cURL fallback —
      falling back would mean falling back to an app-held key, which no longer exists.
- [x] 1.5 Never log the body (card/customer data) or the credential; only method + path.

## Task 2: The adapters stop seeing the key

- [x] 2.1 `AbstractPaymentAdapter::BROKER_MANAGED_SECRET` — a clearly-labelled placeholder
      handed to the adapters in place of the real key, so the seventeen existing
      `if ($apiKey === '')` guards keep working unchanged rather than growing a second code
      path that could drift from the first.
- [x] 2.2 **Tripwire**: `CurlHttpTransport` refuses to send any request whose headers still
      carry the placeholder. If it ever gets there, the call has been routed around the
      broker — fail loudly rather than put a meaningless bearer token on the wire.

## Task 3: PosPaymentService holds a reference, not a secret

- [x] 3.1 `apiKey` / `apiSecret` removed from `SENSITIVE_FIELDS`; new `RETIRED_SECRET_FIELDS`
      exists only so the write path and the repair step can recognise — and refuse — them.
- [x] 3.2 New non-sensitive `credentialId` (a broker credential UUID; returned unmasked
      because it is not a secret).
- [x] 3.3 `getPaymentProvider()` fails closed without the broker or without a credential,
      and attaches a `BrokerHttpTransport` carrying the session UID (the broker's ownership
      guard needs an identity).
- [x] 3.4 `updateProvider()` **ignores and warns on** a submitted `apiKey`/`apiSecret` —
      it must not be quietly persisted "encrypted for safety".
- [x] 3.5 `webhookSecret` deliberately STAYS app-held and encrypted: it verifies an HMAC on
      an INBOUND webhook — a local verify op, not an outbound header — which a constrained
      HTTP proxy cannot carry.

## Task 4: Delete the legacy keys

- [x] 4.1 `RemoveLegacyPspKeys` repair step deletes every stored `apiKey`/`apiSecret`.
      Leaving them would be the worst of both worlds: dead config that is still live secret
      material sitting in `oc_appconfig` waiting for the next database dump.
- [x] 4.2 Bump the app version so the repair step actually runs.

## Task 5: Admin UI

- [x] 5.1 `PaymentSettingsForm.vue` — the API-key password field is replaced by a picker over
      the user's broker credentials (`GET /apps/openregister/api/credentials`).
- [x] 5.2 Map each Pipelinq provider to its broker identifiers. Live and test are **separate**
      catalogue entries (adyen/adyen-test, ccv/ccv-sandbox) because the base URL is the
      host-lock and the two cannot share one.
- [x] 5.3 The webhook-secret field stays, with the reason stated in the markup.

## Task 6: Tests

- [x] 6.1 `BrokerHttpTransportTest` — host discarded, auth headers stripped, fail-closed
      without a credential, and the cURL tripwire.
- [x] 6.2 `PosPaymentServiceTest` — inverted: a submitted `apiKey` is **not stored anywhere**
      (not even encrypted), `credentialId` is; `apiKey` is gone from the config surface.

## Task 7: Verify

- [x] 7.1 PHPUnit, PHPCS, Psalm, PHPStan green.
- [ ] 7.2 Playwright: the POS payment settings page renders the credential picker and no
      API-key field; a saved credential round-trips.

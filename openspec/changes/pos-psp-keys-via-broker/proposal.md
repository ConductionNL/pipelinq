# POS PSP keys via the credential broker

## Why

Pipelinq held the API keys that move the money. Mollie, Stripe, Adyen and CCV keys were
encrypted at rest with `ICrypto`, decrypted into memory by `PosPaymentService` on every
call, handed to the adapter, and pasted into an `Authorization`/`X-API-Key` header by the
adapter itself.

Encryption at rest is worth having, but it is not custody. Pipelinq could decrypt the key,
so **Pipelinq was the trust boundary** for a credential that can charge and refund a
customer's card. A bug in any of the four adapters, an exception that stringified the
wrong array, or a database dump taken at the wrong moment, and the key is out.

OpenRegister's credential broker exists for exactly this. The app sends
`{method, path, body}` plus a credential UUID; the broker checks the owner, the
allowed-app grant and the immutable allow-rules, then injects the secret server-side. The
app never sees it. The blocker was that the provider catalogue listed only
github/gitlab/doffin, so a Mollie credential could not even be created — that shipped in
**openregister #348**, which added all four PSPs (live and sandbox separately, because the
base URL is the host-lock).

## What Changes

- **`BrokerHttpTransport`** implements the existing `HttpTransport` seam, so no adapter
  needs to change shape. It reduces the adapter's URL to a **path** (the host is the
  broker's host-lock), **strips** every auth header the adapter built, and fails closed
  with no broker and no credential — there is deliberately no direct-cURL fallback, because
  falling back would mean falling back to an app-held key.
- **The adapters never see a key.** Rather than rewrite seventeen `if ($apiKey === '')`
  guards into a second code path that could drift from the first, they are handed
  `AbstractPaymentAdapter::BROKER_MANAGED_SECRET` — a clearly-labelled placeholder. The
  transport strips the header built from it, and **`CurlHttpTransport` refuses to send any
  request still carrying it**, so a future change that routes around the broker fails
  loudly instead of quietly sending a placeholder as a bearer token.
- **`PosPaymentService` stores a `credentialId`**, not a key. `apiKey`/`apiSecret` are gone
  from `SENSITIVE_FIELDS`; a client that still submits one is **ignored and warned**, not
  quietly re-encrypted.
- **`RemoveLegacyPspKeys`** deletes the keys stored before this release. Leaving them would
  be the worst of both worlds — dead config that is still live secret material.
- **The admin UI** picks a credential instead of taking a key.

### What deliberately does NOT move

`webhookSecret` stays app-held and encrypted. It verifies an **HMAC on an inbound
webhook** — a local verify operation, not an outbound request header — so a constrained
outbound HTTP proxy cannot carry it. It moves only when the broker grows a sign/verify
capability.

## Impact

- Affected specs: `pos-payment-provider-adapter`
- Affected code: `lib/Service/Payment/{BrokerHttpTransport,CurlHttpTransport,AbstractPaymentAdapter}.php`,
  `lib/Service/PosPaymentService.php`, `lib/Repair/RemoveLegacyPspKeys.php`,
  `src/views/settings/PaymentSettingsForm.vue`
- **Breaking**: every configured provider must have a broker credential selected before it
  can take a payment again. Until then `getPaymentProvider()` throws with an explanatory
  message rather than silently failing at the PSP. This is intentional — the alternative is
  a fallback to the key we just removed.
- Requires OpenRegister with the credential broker and the PSP entries from #348.

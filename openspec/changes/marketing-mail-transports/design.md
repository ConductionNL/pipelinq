# Design: marketing-mail-transports

## Context

`BlastService::dispatchBlastDeliveries()` sends every delivery through exactly
one path today: it resolves a single OpenConnector Source by
`blast.connectorSourceId` (`resolveConnectorSource()`, `lib/Service/BlastService.php:1183`)
and POSTs the rendered payload via `CallService::call()` in
`sendOneDelivery()` (`lib/Service/BlastService.php:1107`). There is no
instance-mailer path and no Mail-account path; a tenant with no OpenConnector
send-mail source cannot send at all.

Nextcloud's public `OCP\Mail\IMailer::createMessage()` returns
`\OC\Mail\Message` (`lib/private/Mail/Mailer.php:77`), a private class that
implements the public `IMessage` contract but also exposes
`getSymfonyEmail(): Symfony\Component\Mime\Email`
(`lib/private/Mail/Message.php:231`) — the only way to set an arbitrary
header, since `IMessage` has no header setter. This is a private-API
dependency: guarded by `method_exists($message, 'getSymfonyEmail')` and
logged (not thrown) when the runtime `IMailer` implementation ever stops
returning `\OC\Mail\Message`.

The Nextcloud Mail app (`custom_apps/mail`) ships no OCP contract.
`AccountService::find(string $userId, int $id): Account` takes an **int** id;
Mail accounts have no UUID concept, unlike every OpenRegister object. This is
a real type boundary: `mailTransport.mailAccountRef` is stored as a numeric
string (schema-portable) and cast to `int` at the `MailAccountTransport`
adapter boundary, inside a guard that fails soft on a non-numeric value
instead of letting a `TypeError` propagate.

`BlastWebhookController` already has three provider parsers (SendGrid, SES,
Twilio) that share one shape: read the raw body, verify an HMAC signature
(`hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature)`) against a
per-provider app-config secret (`blast.webhook_secret.<provider>`), normalise
the payload into `{eventType, providerId, email, timestamp, bounceType?,
reason?}`, then hand off to `BlastSendJob::enqueueWebhookEvent()` for
asynchronous processing by `WebhookProcessorService::processEvent()`, which
calls `ComplianceService::recordConsentWithdrawal()` on bounce/complaint/
unsubscribe. Brevo, Mailjet, Mailgun and Postmark parsers reuse this shape
exactly.

`src/views/settings/MessagingSettings.vue` is the shipped precedent for an
admin-facing provider panel in this app: an in-app settings page
(`src/views/settings/Settings.vue`) composing one `NcSettingsSection` per
concern, reading/writing OpenRegister objects through `useObjectStore`
directly, with credentials kept off the object (only a `sourceId` reference).
The new deliverability panel follows this exact, already-shipped precedent
rather than opening a fresh ADR-079 placement question — `Settings.vue` is
pipelinq's accepted per-app configuration home for domain-scoped marketing
settings, the same page `MessagingSettings.vue` already lives in.

## Goals / Non-Goals

**Goals:**
- A blast can send through the instance mailer with zero configuration, a
  sender's Mail account, or any of six bulk providers, selected per blast
  with a sensible default.
- Provider request-shaping (SES/Brevo/Mailjet/SendGrid/Mailgun/Postmark) is
  data (a per-provider mapping table), not six near-duplicate services.
- The existing SendGrid send path and the existing SendGrid/SES/Twilio
  webhook parsers keep working unchanged in behaviour.
- A tenant can see, in one place, whether their sending domain will pass
  Gmail/Yahoo's 2026 bulk-sender requirements (SPF, DKIM, DMARC).

**Non-Goals:**
- RFC 8058 `List-Unsubscribe` / `List-Unsubscribe-Post` header content is a
  separate openspec change (`marketing-rfc8058-headers`, phase 1). This
  change builds the guarded header-injection *mechanism*
  (`InstanceMailerTransport`'s `getSymfonyEmail()` path, `RenderedMail`'s
  `headers[]`) but does not decide which headers a blast sends.
- No change to `SegmentService`, consent gating, or click/open tracking.
- No credential storage of any kind on a `mailTransport` row — a
  `provider`-kind row is a `connectorSourceId` reference, full stop.
- The deliverability panel reads live DNS; it does not configure DNS, send a
  test message, or manage domain verification with a provider.

## Decisions

### `MailTransportService` + `TransportInterface`, following the `PaymentProviderInterface` precedent

`lib/Service/Payment/` already solves this exact shape for POS payment
providers: one interface (`PaymentProviderInterface`), one abstract base
(`AbstractPaymentAdapter`), one concrete adapter per provider (Mollie, CCV,
Adyen, Stripe), array-shaped return types, methods that never throw. The new
`lib/Service/Marketing/` subdirectory (subdirectories under `lib/Service/`
are already the norm — `Payment/`, `Provider/`, `Export/`, `Portal/`, `Zgw/`
all exist) mirrors it:

- `TransportInterface::send(RenderedMail $mail): SendResult` — one adapter
  method, no provider-specific arguments leak into the interface.
- `RenderedMail` (`lib/Service/Marketing/Transport/RenderedMail.php`) — a
  readonly value object: `from`, `replyTo`, `to`, `subject`, `html`, `text`,
  `headers` (`array<string,string>`), `deliveryId`. Built once by
  `MailTransportService` from the existing `renderTemplate()` output plus the
  resolved transport, then handed unchanged to whichever adapter is chosen —
  no adapter re-derives the rendered content.
- `SendResult` — `{accepted: bool, providerId: ?string, error: ?string}`,
  mirroring `PaymentProviderInterface`'s array-shaped results but as a
  readonly object since it has exactly one shape (no per-provider variance).
- Three adapters implement `TransportInterface`:
  - `InstanceMailerTransport` — `IMailer::createMessage()`, sets from/to/
    subject/body via the public `IMessage` setters, then — only when
    `$mail->headers !== []` — resolves `getSymfonyEmail()` behind
    `method_exists()` and calls `addTextHeader()` per header, logging (not
    throwing) when the guard fails so a missing private API degrades to "no
    extra headers" rather than a failed send.
  - `MailAccountTransport` — resolves `AccountService`/`OutboxService`
    lazily through the container, mirroring hermiq's `MailReadService::mail()`
    two-layer guard (`class_exists()` before the container call, then
    try/catch around `container->get()`, `protected` visibility for test
    doubles) rather than `BlastService`'s simpler try/catch-only pattern,
    because the Mail app — unlike OpenRegister — is optional. Casts
    `mailAccountRef` to `int` inside a `ctype_digit`-guarded block; a
    non-numeric ref degrades soft (logged, `SendResult::accepted = false`)
    rather than throwing.
  - `ConnectorSourceTransport` — today's `resolveConnectorSource()` +
    `CallService::call()` path, generalised over a `PROVIDER_REQUEST_MAPS`
    constant (one array per provider naming the field names each provider's
    send-mail endpoint expects) instead of one hardcoded shape. The SendGrid
    entry reproduces today's exact request body so existing behaviour is
    unchanged.
- `MailTransportService::resolveTransport(?string $transportId): ?array`
  loads the named `mailTransport` row, or the one with `default=true` when
  `$transportId` is empty or not found — matching `BlastService`'s existing
  `loadOne()`/`extractId()` helper style so the two services stay visually
  consistent for the next reader.
- `MailTransportService::sendOneDelivery()` becomes the single place that:
  builds `RenderedMail`, resolves the transport row, checks
  `dailyLimit`/`sentToday` (soft-fail closed: over-limit returns
  `SendResult::accepted = false` without calling the adapter), picks the
  adapter class by `kind`, calls `send()`, and on success advances
  `sentToday` + writes the existing `blastDelivery` status fields —
  `BlastService::sendOneDelivery()` is deleted and
  `dispatchBlastDeliveries()` calls the new service instead. This is the
  full extent of the `BlastService` edit, keeping the concurrent
  list-audiences branch (which touches `sendBlast()`) conflict-free.

### Per-transport daily limit lives on the object, not a new budget schema

`80-whatsapp-sms-channel.json`'s `messageSendBudget` is a separate schema
because a budget there can span *multiple* channel providers. A
`mailTransport`'s daily limit only ever governs itself, so `sentToday` +
`dailyLimitResetAt` live directly on `mailTransport` (mirroring
`messageSendBudget.currentPeriodMessages`/`periodResetAt` but inlined) rather
than adding a second schema for a 1:1 relationship.

### Deliverability DNS lookup: a thin overridable wrapper, cached on the object

No fleet precedent caches `dns_get_record`; the only existing shape
(hermiq's `WebResearchEgressGuard::dnsGetRecord()`) is a protected,
separately-overridable one-line wrapper around the built-in, used purely for
test doubles. `DeliverabilityCheckService` (new,
`lib/Service/Marketing/DeliverabilityCheckService.php`) copies that shape —
`protected function dnsGetRecord(string $hostname, int $type): array|false`
— and adds the caching this change needs itself: a lookup writes its result
onto the `mailTransport` row (`dkimVerified`, `dmarcStatus`,
`deliverabilityCheckedAt`) and the panel only re-queries DNS when that
timestamp is more than 24h old or the admin clicks refresh. A `dns_get_record`
failure (network, `false` return, or a thrown warning-as-exception under
strict error handlers) is treated as `dmarcStatus = 'unknown'`, never thrown.

### Provider webhook parsers: same shape, four more methods

Brevo, Mailjet, Mailgun and Postmark each get one method on
`BlastWebhookController` following the SendGrid/SES/Twilio template exactly:
own `SECRET_KEY_PREFIX`-namespaced secret, `#[PublicPage] #[NoCSRFRequired]
#[AnonRateLimit(limit: 300, period: 60)]`, a `normalise<Provider>Event()`
private method, `enqueueWebhookEvent(provider:, event:)`. Signature schemes
differ per provider (Brevo: shared-secret query param or header depending on
version; Mailjet: HMAC-SHA256 over the raw body; Mailgun: HMAC-SHA256 over
`timestamp . token`; Postmark: no native signature — Postmark's webhook auth
is HTTP Basic or an allow-listed source IP, so Postmark's parser verifies our
own `X-Pipelinq-Signature` header exclusively, exactly as the other parsers
already prefer it over the provider-native scheme). All four route through
the existing `extractSignatureHeader(fallback:)` helper.

## Risks / Trade-offs

- **`\OC\Mail\Message::getSymfonyEmail()` is a private-API dependency.** →
  Guarded by `method_exists()`; a future NC release that changes `IMailer`'s
  concrete return type degrades to "no extra headers sent" (logged), never a
  failed send. Documented in the class docblock and a dedicated unit test
  asserts the guard's degraded path.
- **The Mail app is optional; a `mailAccount` transport with the app absent
  must not 500.** → Two-layer `class_exists()` + try/catch guard (hermiq
  precedent); `active=false`-in-effect behaviour surfaces as
  `SendResult::accepted = false` with a logged reason, not an exception.
- **`AccountService::find()` needs an `int` id; `mailAccountRef` is a
  schema string.** → Cast behind a `ctype_digit()` guard; a malformed ref
  degrades soft rather than throwing a `TypeError`.
- **Six provider request shapes in one class risks becoming an unmaintainable
  `switch`.** → `PROVIDER_REQUEST_MAPS` is data (field-name arrays), not
  per-provider methods; adding a seventh provider is a new array entry, not
  new control flow. Mirrors the `PaymentProviderInterface` adapters' shape
  without their per-class boilerplate, because unlike payment capture/refund,
  every mail provider's send-mail call is structurally the same request with
  different field names.
- **DNS lookups from a request thread are slow/flaky.** → Cached on the
  object (`deliverabilityCheckedAt`), fail-soft to `unknown`/`missing`,
  admin-triggered refresh rather than lookup-on-every-page-load.
- **Concurrent branch touching `BlastService::sendBlast()`.** → This
  change's only `BlastService` edit is deleting `sendOneDelivery()` and its
  private send-only helpers (`resolveConnectorSource`, `getCallService`,
  `resolveRateLimit`, `readSourceRateLimit`, `readSourceField`,
  `decodeCallLogResponseBody`, `extractProviderId`, `renderTemplate`) and
  replacing the one call site in `dispatchBlastDeliveries()` — `sendBlast()`
  itself is untouched.

## Seed Data

Following ADR-001, `95-marketing-mail-transports.json` seeds three
`mailTransport` rows across the standard archetypes:

- **Municipality** (`instance-mail-server`, kind=instance) — the default
  transport every tenant gets for free; `senderDomain:
  gemeente-voorbeeld.example.nl`, `dailyLimit: 0` (no cap), `default: true`.
- **Consultancy** (`sender-mail-account`, kind=mailAccount) — "Advies & Zo"
  sending low-volume mail through the sender's own Mail account;
  `mailAccountRef: "1"` (placeholder numeric id), `dailyLimit: 100`.
- **Travel agency** (`sendgrid-bulk`, kind=provider, provider=sendgrid) —
  "Zonnig Reizen" reusing the `connectorSourceId` already referenced by the
  existing blast seed data (`openconnector-sendgrid-prod`), `mock: true`,
  `active: false` (a placeholder until the tenant credentials the real
  source), `dkimVerified: true`, `dmarcStatus: "found"` to demonstrate the
  panel's "found" verdict without a live DNS dependency in seed data.

`blast.transportId` is left empty on the existing seeded blasts, so they
continue to resolve the default (instance) transport — no seed-data
migration needed.

## Migration Plan

Additive only. New schema, new service/adapters, four new webhook methods, a
new settings panel section, a new wizard step. `blast.transportId` is
optional; every blast without one keeps sending through the transport marked
`default=true` — behaviourally the *instance* mailer for a fresh install, or
the operator's existing OpenConnector source once they create a
`provider`-kind transport pointing at it and mark it default. Rollback is
reverting the code; the schema addition is backward compatible (no field
removed, nothing newly required on `blast`).

## Open Questions

- Should `MailAccountTransport` support `cc`/`bcc` beyond the primary
  recipient list, given the Mail app's `saveMessage()` takes them as separate
  arrays? (Provisional: `RenderedMail.to` is a single recipient per blast
  delivery, matching today's one-delivery-one-recipient model; `cc`/`bcc`
  are passed as empty arrays. A multi-recipient delivery is out of scope for
  phase 1.)
- Should the deliverability panel's DNS refresh be a scheduled background job
  instead of admin-triggered? (Provisional: admin-triggered `PUT`, matching
  `MessagingSettings.vue`'s `testProviderConnection()` button pattern; a
  cron refresh is deferred until a tenant actually asks for it.)

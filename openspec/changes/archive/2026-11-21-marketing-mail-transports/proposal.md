---
kind: mixed
depends_on: []
---

# Proposal: marketing-mail-transports

## Why

Pipelinq's blast engine sends every mailing through one hardcoded path: a
per-blast OpenConnector `send-mail` source. A tenant with no bulk-provider
account cannot send at all, and one who does has no way to route low-volume
mail through their own Nextcloud Mail account instead of paying provider
rates for a dozen recipients. Phase 1 of the marketing programme
(`docs/Technical/marketing-architecture.md`) makes the instance mail server
the default transport for every tenant on day one, with the sender's Mail
account and five bulk providers (Amazon SES, Brevo, Mailjet, SendGrid,
Mailgun, Postmark) as per-tenant options — so a mailing list works out of
the box and scales up only when a tenant chooses to.

## What Changes

- **`mailTransport` schema** — a new OpenRegister schema (`kind`: instance,
  mailAccount, provider; `connectorSourceId`, `mailAccountRef`,
  `dailyLimit`, `dkimVerified`, `dmarcStatus`, `active`), seeded with one
  `instance` transport marked default plus mock-flagged example provider
  transports.
- **`MailTransportService` + three adapters** behind a `TransportInterface` —
  `InstanceMailerTransport` (Nextcloud `IMailer`, headers via the guarded
  private `Message::getSymfonyEmail()` path), `MailAccountTransport` (the
  Mail app's outbox, degrading softly when the Mail app is absent),
  `ConnectorSourceTransport` (today's OpenConnector send-mail path,
  generalised over SES/Brevo/Mailjet/SendGrid/Mailgun/Postmark request
  shapes; the existing SendGrid path keeps working unchanged). Each
  transport carries its own daily limit alongside the existing per-source
  rate limit. `BlastService` picks the transport named by `blast.transportId`,
  falling back to the default transport, and the send wizard gains a
  transport step.
- **Four more webhook parsers** — Brevo, Mailjet, Mailgun and Postmark join
  SendGrid and SES in `BlastWebhookController`, each signature-verified and
  mapping bounce/complaint/unsubscribe to the existing consent-withdrawal
  path.
- **Deliverability panel** — an admin settings section listing transports
  with active/default toggles and, per sender domain, SPF/DKIM/DMARC status
  fetched via cached, fail-soft DNS lookups with a plain-language verdict.
- **Tests and docs** — PHPUnit for transport selection, the header-injection
  guard, daily limits and each webhook parser; vitest for the settings
  section; a linted (not run) Playwright spec; a "Sending" section in
  `docs/Features/marketing.md` and admin notes in
  `docs/Features/admin-settings.md`.

## Capabilities

### New Capabilities
- `marketing-mail-transports`: per-tenant transport selection (instance
  mailer default, Mail account, bulk provider) with pluggable adapters, a
  daily send limit per transport, and a deliverability panel showing
  SPF/DKIM/DMARC status per sender domain.

### Modified Capabilities
- `marketing-blast-delivery`: `BlastWebhookController` gains Brevo,
  Mailjet, Mailgun and Postmark parsers alongside the existing SendGrid and
  SES ones, each mapping bounce/complaint/unsubscribe to consent
  withdrawal; `dispatchBlastDeliveries()`'s send step is extracted into
  `MailTransportService` and resolves a `mailTransport` instead of assuming
  a single OpenConnector source.
- `marketing-blast`: `blast` gains a `transportId` reference and the send
  wizard gains a transport-selection step; the connector-source-only send
  path becomes one of three transport kinds.

## Impact

- **Code:** new `lib/Settings/register.d/9x-marketing-mail-transports.json`
  schema fragment; new `lib/Service/Marketing/MailTransportService.php`
  plus `TransportInterface` and three adapter classes; `BlastService`'s
  `sendOneDelivery()`/`resolveConnectorSource()` narrow to transport
  resolution delegated to the new service; four new parser methods on
  `lib/Controller/BlastWebhookController.php`; a new settings Vue section
  under `src/views/settings/`; a new wizard step in the existing blast
  wizard component.
- **Data:** new `mailTransport` schema; `blast` schema gains `transportId`.
  No change to `blastDelivery`.
- **Dependencies:** none new — reuses `OCP\Mail\IMailer`, the private
  `\OC\Mail\Message::getSymfonyEmail()` (documented private-API dependency,
  guarded and degrading softly), the Nextcloud Mail app's
  `AccountService`/`OutboxService` (resolved lazily, degrading softly when
  absent), and the existing OpenConnector `CallService::call()` path.
- **Security:** provider credentials never live in pipelinq (ADR-064/067/091)
  — every provider transport is a `connectorSourceId` reference resolved by
  OpenConnector; the four new webhook routes are `#[PublicPage]` +
  `#[NoCSRFRequired]` and signature-verified, matching the existing SendGrid
  and SES parsers.
- **Feature tier:** V1 (base-tier email quality; bulk providers are a
  per-tenant upgrade, not gated behind a paid tier of Pipelinq itself).
- **Concurrent work:** another change is adding list audiences inside
  `BlastService::sendBlast()` on a separate branch; this change confines its
  `BlastService` edits to transport resolution and `sendOneDelivery()`,
  extracted into `MailTransportService` so the two branches merge cleanly.

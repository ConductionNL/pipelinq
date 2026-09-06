# Tasks: marketing-mail-transports

## 1. Schema

- [ ] 1.1 Add `lib/Settings/register.d/95-marketing-mail-transports.json`: the `mailTransport` schema, `blast.transportId`, and three seed rows (instance/mailAccount/provider archetypes); verify `composer check:strict` (JSON/PHPCS) and `npm run check:schema-l10n` pass
  - files: `lib/Settings/register.d/95-marketing-mail-transports.json`
  - Acceptance criteria:
    - `components.registers.pipelinq.schemas` and `components.objects` rely on the additive-list merge (no duplicate-key clobber of the sibling `95-marketing-segmentation-blast.json` fragment)
    - No provider credential field anywhere on `mailTransport`

## 2. Transport service and adapters

- [ ] 2.1 Create `lib/Service/Marketing/Transport/TransportInterface.php`, `RenderedMail.php`, `SendResult.php`; verify `composer check:strict` passes with `@spec` on every method
- [ ] 2.2 Create `InstanceMailerTransport.php` (`IMailer::createMessage()`, `getSymfonyEmail()` guarded by `method_exists()`, logged degrade-soft); verify a unit test covers both the header-present and header-guard-unavailable paths
- [ ] 2.3 Create `MailAccountTransport.php` (lazy `AccountService`/`OutboxService` resolution mirroring hermiq's `class_exists()` + try/catch guard, `mailAccountRef` cast to `int` behind a `ctype_digit()` check); verify a unit test covers the Mail-app-absent degrade-soft path
- [ ] 2.4 Create `ConnectorSourceTransport.php` generalising today's `resolveConnectorSource()`/`CallService::call()` path over a `PROVIDER_REQUEST_MAPS` constant for SES/Brevo/Mailjet/SendGrid/Mailgun/Postmark; verify a unit test asserts the SendGrid request body is byte-for-byte unchanged from before this change
- [ ] 2.5 Create `MailTransportService.php` (`resolveTransport()`, daily-limit check/roll, `sendOneDelivery()` dispatching to the resolved adapter); delete `BlastService::sendOneDelivery()` and its now-unused private send-only helpers, replacing the one call site in `dispatchBlastDeliveries()`; verify `tests/Unit/Service/BlastServiceTest.php` still passes unmodified except for that one call-site assertion
  - files: `lib/Service/Marketing/Transport/*.php`, `lib/Service/Marketing/MailTransportService.php`, `lib/Service/BlastService.php`
  - Acceptance criteria:
    - `sendBlast()` and every method other than `dispatchBlastDeliveries()`'s send step are untouched (concurrent list-audiences branch stays conflict-free)
    - A transport at its `dailyLimit` refuses the send without calling the adapter

## 3. Webhook parsers

- [ ] 3.1 Add `BlastWebhookController::brevo()` (own `SECRET_KEY_PREFIX` secret, `normaliseBrevoEvent()`) following the SendGrid/SES/Twilio shape exactly; register `POST /api/blast-webhooks/brevo` in `appinfo/routes.php`; verify a unit test asserts valid-signature enqueue and invalid-signature 422
- [ ] 3.2 Add `BlastWebhookController::mailjet()` (HMAC-SHA256 over the raw body, `normaliseMailjetEvent()`); register the route; verify the same valid/invalid-signature test pair
- [ ] 3.3 Add `BlastWebhookController::mailgun()` (HMAC-SHA256 over `timestamp . token`, `normaliseMailgunEvent()`); register the route; verify the same valid/invalid-signature test pair
- [ ] 3.4 Add `BlastWebhookController::postmark()` (verified via the shared `X-Pipelinq-Signature` header, Postmark has no native payload signature; `normalisePostmarkEvent()`); register the route; verify the same valid/invalid-signature test pair

## 4. Deliverability panel and wizard step

- [ ] 4.1 Create `lib/Service/Marketing/DeliverabilityCheckService.php` (overridable `dnsGetRecord()` wrapper mirroring hermiq's `WebResearchEgressGuard`, cached onto `mailTransport.dkimVerified`/`dmarcStatus`/`deliverabilityCheckedAt`, fail-soft to `unknown`); expose it through a settings-scoped controller endpoint; verify a unit test covers a found/missing/failed DNS lookup each caching correctly
- [ ] 4.2 Create `src/views/settings/DeliverabilitySettings.vue` (transport list with active/default toggles, per-sender-domain SPF/DKIM/DMARC verdicts, refresh button) following `MessagingSettings.vue`'s `useObjectStore` + save/error pattern; register it in `src/views/settings/Settings.vue`; verify `npm run lint` and a vitest component test rendering seeded transports
- [ ] 4.3 Add a "Transport" step to the blast wizard (`src/views/blasts/BlastForm.vue`'s `STEPS` array and `canAdvance()` switch), listing active transports with the default pre-selected; verify a vitest test asserts the step renders and gates advance on an empty selection only when no default exists

## 5. Tests and docs

- [ ] 5.1 Write `tests/Unit/Service/Marketing/MailTransportServiceTest.php` and one test file per transport adapter (`InstanceMailerTransportTest.php`, `MailAccountTransportTest.php`, `ConnectorSourceTransportTest.php`) with placeholder-safe fixtures; verify `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and PHPUnit pass
- [ ] 5.2 Write `tests/Unit/Controller/BlastWebhookControllerTest.php` additions for Brevo/Mailjet/Mailgun/Postmark (valid + tampered signature per provider), reusing the existing test's HMAC-replication pattern; verify PHPUnit passes
- [ ] 5.3 Write `tests/e2e/spec-coverage/marketing-transports.spec.ts` (deliverability panel renders seeded transports; wizard shows the transport step), each test carrying an `@e2e` anchor comment to its spec scenario; verify `npm run lint` passes (not run locally per environment constraints)
- [ ] 5.4 Add a "Sending" section to `docs/Features/marketing.md` and admin notes to `docs/Features/admin-settings.md` (transport kinds, the private-API header dependency, deliverability verdicts); verify `npm run check:spec-links` passes

## Acceptance criteria (change-level)

- A fresh install sends through the instance mailer with zero configuration; an admin can add a Mail-account or bulk-provider transport and make it default.
- No provider credential ever appears on a `mailTransport` object; every provider send is an OpenConnector `connectorSourceId` reference.
- SendGrid sends and the SendGrid/SES/Twilio webhooks behave exactly as before this change; Brevo, Mailjet, Mailgun and Postmark webhooks map to the same consent-withdrawal path.
- `composer check:strict`, `npm run format`, `npm run lint`, `npm run test:unit`, `npm run check:manifest`, `npm run check:spec-links` and `npm run check:schema-l10n` all pass.

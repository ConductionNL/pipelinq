# Proposal: outbound-messaging-provider-wiring

kind: feature completion — wire the already-built WhatsApp/SMS outbound machinery to a real, CI-testable provider and give it the three missing surfaces (admin settings, SLA escalation dispatch, agent send UI), so `omnichannel-registratie` can honestly return to `stable`.

All code claims verified against HEAD on 2026-07-05; OR/OpenConnector claims verified against `origin/development` of `../openregister` and `../openconnector`.

## Why

Pipelinq's WhatsApp/SMS channel is half-built. **Inbound is wired**: routes `messagingWebhook#whatsapp|sms` (`appinfo/routes.php:427-428`) → `MessagingWebhookController` → `WebhookProcessorJob`, and full adapters exist with tests (`lib/Service/WhatsAppAdapter.php`, `lib/Service/SmsAdapter.php` — consent + budget gating, provider failover, template/session-window enforcement, message/conversation persistence). Transport is already re-routed through OpenRegister's `MessageDispatchProvider` leaf (`lib/Service/Provider/MessageDispatchTrait.php`). But **outbound is unreachable**:

- `SmsAdapter::send()` / `WhatsAppAdapter::send()` have **zero production callers** — the only classes referencing the adapters are the inbound pair (`MessagingWebhookController`, `WebhookProcessorJob`).
- There is **no UI surface** — zero `whatsapp` references anywhere in `src/`.
- `SlaEngineService::dispatchNotification()` (`lib/Service/SlaEngineService.php:836`) returns `'deferred:'.$channel` for every channel except `nextcloud-notification`, so the SLA spec's "Escalation to customer via WhatsApp" scenario is fiction (it also references an `OmnichanelService.preferredChannel` that exists nowhere in `lib/` or `src/`).
- There is **no admin surface** to create/maintain `channelProvider` rows, so even a motivated admin cannot configure a provider.

The parallel `align-claims-and-first-hour` change (reference only — owned by another track) downgrades the `omnichannel-registratie` overlay entry from `stable` to `beta` for exactly this reason. Product-owner decision (2026-07-05): pick a messaging provider with a genuine testing API and finish the feature.

## Provider decision (research 2026-07-05, live sources)

**Primary: Bird (formerly MessageBird)** — Amsterdam HQ, EU `eu1` data residency, ISO 27001:2022 + Dutch ACM registration, official Meta BSP, self-serve PAYG with no monthly minimum (post-rebrand minimums only hit the sales-gated Bundle tier). Decisive: the legacy MessageBird REST API (`rest.messagebird.com` — exactly the `messagebird-sms` source OpenConnector already seeds) supports **test access keys** (`test_…`) that validate a full send request **without dispatching a message and at zero cost** (developers.messagebird.com/docs/sms-messaging/test-credits-api-keys/) — the only candidate with a documented no-send/no-charge path usable headlessly in CI. Known gap: test keys emit no delivery-status callbacks; status-callback flows are covered by webhook fixtures (already how inbound is tested).

**SMS fallback / WhatsApp production default: CM.com** — Breda HQ, Euronext-listed, Meta **Premier** BSP, concrete Dutch central-government references (ministries, DUO, CJIB). For **SMS** CM.com is the fallback (weaker CI story: no documented free SMS validate-without-send). For **WhatsApp production** CM.com's `whatsapp-bsp` is the **default leg** — owner decision 2026-07-06 ("go for gov friendly"): Premier BSP + Breda HQ + Dutch central-gov references outweigh Meta-direct's zero platform fee for the target market. Both vendors' client classes (`MessageBirdSmsClient`, `CmComSmsClient`) and OpenConnector sources (`messagebird-sms`, `cmcom-sms`, `whatsapp-bsp`) already exist.

**WhatsApp transport (RESOLVED 2026-07-06)**: OR's `MessageDispatchProvider::ALLOWED_SOURCES` is a fixed allow-list (`cmcom-sms`, `messagebird-sms`, `twilio-sms`, `whatsapp-cloud-api`, `whatsapp-bsp`). Bird-native WhatsApp (Conversations API, a different host than `rest.messagebird.com`) is **not** allow-listed, so WhatsApp ships over the two allow-listed legs. Owner decision 2026-07-06 ("go for gov friendly"): **`whatsapp-bsp` (CM.com BSP) is the default production WhatsApp leg** — Premier BSP, Breda HQ, Dutch central-government references. `whatsapp-cloud-api` (Meta direct; free test number + up to 5 test recipients returning real `wamid.*` ids and production webhooks) remains the WhatsApp **CI/dev harness leg** and an **explicit per-tenant opt-in alternative** (`channelProvider` rows are per-tenant data). Adding a `bird-whatsapp` source is a cross-repo follow-up that is **consciously DEFERRED — no OR/OC issue filed** (see Impact), not in scope.

## What Changes

- **Admin settings surface** for messaging: a settings page (CTI-settings pattern: `src/manifest.d/` fragment + `src/registry.js` component + `src/views/settings/`) managing `channelProvider` rows, send budgets, consent defaults, template-sync status, and displaying the inbound webhook URLs; plus a zero-cost provider connectivity test.
- **SLA escalation dispatch goes live** for `sms`/`whatsapp`: `SlaEngineService::dispatchNotification()` resolves the adapters lazily and actually sends (via the OR `MessageDispatchProvider` leaf) instead of returning `'deferred:'`; `email`/`webhook` channels remain explicitly deferred to their own specs. The phantom `OmnichanelService.preferredChannel` scenario is corrected to the real seam.
- **Agent send surface**: a Messages conversation section (`kind:'section'` body widget) on the comms-first Client and Contact detail grids (the Email integration-leaf placement at `src/manifest.json:480` is the precedent), with a send composer modal (`src/modals/`, modal-isolation rule) and a server-side send endpoint that orchestrates the adapters (consent/budget gating stays server-side).
- **Consent compliance tightened**: business-initiated WhatsApp (outside the 24h session window) requires a recorded **opt-in** (`messagingConsentRecord.state = opted-in`) — Meta business-messaging policy; SMS keeps block-on-opt-out. Consent state + one-click opt-in/opt-out recording (with evidence + legal basis) becomes visible on the send surface. **BREAKING** for the (unreachable-today) WhatsApp template-send path: absent consent record no longer passes.
- **Outbound audit as contactmomenten**: every outbound send writes a `contactmoment` (WhatsApp per the omnichannel convention `channel: "chat"` + `metadata.platform: "whatsapp"`; SMS via a new `"sms"` enum value on `contactmoment.channel`).
- **Test strategy with named mechanisms**: CI contract tests ride OpenConnector's seeded mock-mode sources (`configuration.mock: true` short-circuit in OR's `ExternalIntegrationRouter` — no network, canned vendor-shaped bodies); the live-gate variant uses Bird **test access keys** (SMS, zero cost, no send) and the Meta Cloud API **test number + test recipients** (WhatsApp) behind env guards.
- **Promotion criteria** for flipping the `omnichannel-registratie` overlay `beta` → `stable` (the `align-claims-and-first-hour` downgrade is the referenced current state), executed as this change's final task once the criteria hold.

## Capabilities

### New Capabilities
- `outbound-messaging`: provider administration, agent send surface, send API, consent gating for business-initiated messages, outbound-send audit as contactmomenten, CI/live-gate contract test mechanisms, and the beta→stable promotion contract.

### Modified Capabilities
- `sla-engine-and-escalation`: the "Escalation chain execution" requirement — `sms`/`whatsapp` escalation steps actually dispatch through the channel adapters (consent-gated, template-gated for WhatsApp business-initiated), replacing the `'deferred:'` audit marker and the phantom `OmnichanelService` reference.
- `omnichannel-registratie`: ADDED requirement — outbound WhatsApp/SMS sends are automatically registered as contactmomenten (extends the channel data-model with the `sms` enum value).

## Impact

- **Code**: `lib/Service/SlaEngineService.php` (dispatch), new `lib/Controller/MessagingController.php` + routes, `lib/Service/ConsentService.php` (business-initiated gate), `lib/Service/WhatsAppAdapter.php` / `lib/Service/SmsAdapter.php` (contactmoment audit hook, send-context), `lib/Settings/register.d/80-whatsapp-sms-channel.json` (channelProvider `credentials` deprecation note), `lib/Settings/register.d/55-sla-engine.json` (escalation-step `templateId`), `lib/Settings/pipelinq_register.json` (contactmoment channel enum `+sms`), `src/manifest.d/` (new messaging settings fragment), `src/registry.js`, `src/views/settings/`, `src/modals/`, `src/manifest.json` (Client/Contact detail grids), tests (`tests/Unit`, `tests/Integration`, `tests/newman`, `tests/e2e/spec-coverage`).
- **OR seam (verified `../openregister` origin/development)**: `OCA\OpenRegister\Service\Integration\Providers\MessageDispatchProvider::dispatch(string $source, array $body, string $path, array $headers=[]): array` (`lib/Service/Integration/Providers/MessageDispatchProvider.php:292`), `ALLOWED_SOURCES = ['cmcom-sms','messagebird-sms','twilio-sms','whatsapp-cloud-api','whatsapp-bsp']`; mock short-circuit in `lib/Service/Integration/ExternalIntegrationRouter.php` (`configuration.mock === true`, lines ~127-134, fixture resolver ~470-492, change `integration-mock-mode`). **No OR change required.**
- **OpenConnector seam (verified origin/development)**: seeded sources `messagebird-sms` / `cmcom-sms` / `twilio-sms` / `whatsapp-cloud-api` / `whatsapp-bsp` in `lib/Settings/register.d/`, all shipping `configuration.mock: true` + realistic `mockResponse`. Going live = credential the source + remove `mock` (admin-owned). **No OpenConnector change required.**
- **Deferred follow-up (cross-repo, out of scope, consciously NOT filed)**: Bird-native WhatsApp would require a new OpenConnector `bird-whatsapp` source seed **and** an addition to OR `MessageDispatchProvider::ALLOWED_SOURCES`. Owner decision 2026-07-06: with CM.com `whatsapp-bsp` as the default production leg and Meta `whatsapp-cloud-api` as harness/opt-in, there is no demand driver — this follow-up is consciously DEFERRED without filing OR/OC issues; revisit only if a tenant demands Bird-native WhatsApp.
- **Change coordination**: `align-claims-and-first-hour` (reference only) sets the overlay `beta` with a machine-readable reason; this change's final task flips it to `stable` under the promotion criteria. `semantic-handoff-emit` is untouched.

## Out of Scope

- Bulk/marketing sends — `marketing-blast` / `marketing-compliance` own campaign consent and volume sending; this change is 1:1 agent/SLA messaging.
- Email and generic-webhook SLA escalation channels (own specs; the `'deferred:'` marker remains for them, now documented).
- Bird-native WhatsApp transport (cross-repo follow-up above — consciously deferred 2026-07-06, no issue filed).
- Any OR or OpenConnector code change.
- Delivery-status reconciliation redesign — existing webhook ingestion already updates `message.deliveryStatus`.

## Success Criteria

- An admin can configure Bird (or CM.com/Twilio/Meta) from the messaging settings page, run a zero-cost connectivity test, and see the inbound webhook URLs to paste into the provider console.
- An agent on a Client or Contact detail page can see the conversation history and send an SMS or WhatsApp message (template-gated when outside the session window); the send is consent-gated, budget-gated, persisted as `message` + `conversation`, and audited as a `contactmoment`.
- An SLA escalation step with `channel: whatsapp` / `channel: sms` and `notify: customer` produces a real adapter dispatch (visible in the `message` store and the breach event's `notifiedActors`), never `'deferred:'`.
- CI runs the full pipelinq→OR-leaf→OpenConnector-source pipeline green with zero external network (mock-mode sources); the env-guarded live gate validates against Bird test keys + the Meta test number.
- `features.overlay.json` reports `omnichannel-registratie` as `stable` again, with every promotion criterion demonstrably met; `composer check:strict` and the hydra gates pass.

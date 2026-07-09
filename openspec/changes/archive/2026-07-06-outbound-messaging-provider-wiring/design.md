# Design: outbound-messaging-provider-wiring

## Context — what exists at HEAD (verified 2026-07-05)

| Layer | State | Evidence |
|---|---|---|
| Inbound webhooks | **Wired** | `messagingWebhook#whatsapp\|sms` (`appinfo/routes.php:427-428`) → `MessagingWebhookController` → `WebhookProcessorJob` (only production adapter callers) |
| Adapters | **Complete, uncalled** | `SmsAdapter::send(array $contact, string $body, ?string $providerHint=null)` (`lib/Service/SmsAdapter.php:152`); `WhatsAppAdapter::send(array $contact, string $body, ?string $templateId=null, array $parameters=[])` (`lib/Service/WhatsAppAdapter.php:175`); both gate through `ConsentService::canSend` + `BudgetService`, fail over via `ChannelProviderRepository::listActive(kind)`, persist `message`/`conversation`, enforce WhatsApp template + 24h session window (`resolveTemplateForSend` :315, `isWithinSessionWindow` :621) |
| Transport | **Re-pointed to OR leaf** | provider clients (`TwilioSmsClient`, `MessageBirdSmsClient`, `CmComSmsClient`, `WhatsAppProviderClient`) → `MessageDispatchTrait::dispatchViaLeaf(source, body, path, headers)` → OR `MessageDispatchProvider` |
| Data model | **Registered** | `lib/Settings/register.d/80-whatsapp-sms-channel.json`: `channelProvider` (kind, vendor, displayName, credentials, phoneNumber, webhookSecret, active, **sandbox**, priority), `messageTemplate`, `messagingConsentRecord` (contactId, channel, state, source, recordedAt, evidence, legalBasis), `messageSendBudget`, `message`, `conversation` |
| Template sync | **Wired** | `TemplateApprovalSyncJob` cron → `TemplateApprovalSyncService` |
| SLA dispatch | **Stub** | `SlaEngineService::dispatchNotification` returns `['deferred:'.$channel.':'.$notify]` for everything except `nextcloud-notification` (`lib/Service/SlaEngineService.php:836`); the class already injects `Psr\Container\ContainerInterface` (constructor :78) |
| UI | **Absent** | zero `whatsapp` matches in `src/` |
| Consent semantics | **Opt-out only** | `ConsentService::canSend` (`lib/Service/ConsentService.php:113`): absent record → allowed; only `opted-out` blocks |
| Overlay | **Overstated** | `openspec/features.overlay.json:75-81` `omnichannel-registratie` = `stable`; `align-claims-and-first-hour` (parallel, reference only) downgrades it to `beta` |

**OR seam (verified via `git show` on `../openregister` origin/development)** — `OCA\OpenRegister\Service\Integration\Providers\MessageDispatchProvider`:
- `dispatch(string $source, array $body, string $path, array $headers=[]): array` (line 292) — validates `$source` against `ALLOWED_SOURCES = ['cmcom-sms','messagebird-sms','twilio-sms','whatsapp-cloud-api','whatsapp-bsp']`, degrades (never throws) per AD-23.
- Mock mode: `ExternalIntegrationRouter` short-circuits any source with `configuration.mock === true` and returns the source's canned `configuration.mockResponse` **without any HTTP call** (lines ~127-134; fixture resolver ~470-492; change `integration-mock-mode`).

**OpenConnector seam (verified origin/development)** — `lib/Settings/register.d/` seeds all five sources (`messagebird-sms` → `https://rest.messagebird.com`, `cmcom-sms` → `https://gw.cmtelecom.com/v1.0`, `twilio-sms` → `https://api.twilio.com`, `whatsapp-cloud-api` → Meta Graph, `whatsapp-bsp` → `https://api.whatsapp.cm.com`), **each with `mock: true` + a realistic `mockResponse`**. Credentials/base-URL live on the source (admin-owned), never in pipelinq.

## Decision 1 — Provider: Bird primary, CM.com fallback

Weighting (product-owner brief): CI-testable without cost > EU/Dutch fit > dual-channel > pricing.

| | Bird (ex-MessageBird) | CM.com | Twilio | Spryng | Vonage | Meta direct |
|---|---|---|---|---|---|---|
| Zero-cost CI mechanism | **test `test_…` access keys** — request validated, nothing sent, SMS + WhatsApp request shapes | email-only sandbox header; WA sandbox/trial | test creds + magic numbers (**SMS only**); WA sandbox sends real msgs | none | Messages-API sandbox (WA only, no SMS, "not for QA") | **free test number + 5 test recipients**, real `wamid.*` + production webhooks (WA only) |
| WhatsApp | BSP ✔ | **Premier BSP** ✔ | BSP ✔ | BSP claim (tier unverified) | BSP ✔ | is Meta |
| SMS NL | ✔ | ✔ | ✔ | ✔ (SMS-only shop) | ✔ | ✗ |
| EU/Dutch fit | **Amsterdam HQ**, `eu1` residency, ISO 27001, ACM registration | **Breda HQ**, Euronext, Dutch central-gov references | US HQ; EU residency **SMS-only** | Amsterdam, ISO 27001+NEN 7510, SMS-only | US/Ericsson; EU isolation beta | US; Schrems-II flag |
| Pricing shape | PAYG self-serve, **no minimum** | pay-per-use, sales-leaning | usage-based | pure PAYG, iDEAL | PAYG | no platform fee |

**Primary = Bird (SMS + zero-cost test ring)**: only candidate whose zero-cost test mechanism covers both channels' request shapes, on the exact API host OpenConnector already seeds (`messagebird-sms` = `rest.messagebird.com`), with the strongest self-serve Dutch-MKB onboarding. **SMS fallback = CM.com**: swaps in when a government tender demands the Dutch Premier-BSP with central-government references; its weaker CI story (no free SMS validate-without-send) is acceptable for a fallback because the CI gate rides mock-mode sources regardless (Decision 5).

**WhatsApp transport (resolved 2026-07-06)**: Bird-native WhatsApp uses the Conversations API host, which is **not** in OR's `ALLOWED_SOURCES` — so WhatsApp ships over the two allow-listed legs. Owner decision 2026-07-06 ("go for gov friendly"): **`whatsapp-bsp` (CM.com BSP) is the default production WhatsApp leg** — Meta Premier BSP, Breda HQ, Dutch central-government references (ministries, DUO, CJIB) fit the government-friendly positioning. **`whatsapp-cloud-api` (Meta direct)** remains the **CI/dev harness leg** (free test number + test recipients, real `wamid.*`, production webhooks) and an **explicit per-tenant opt-in alternative** for tenants that accept Meta-direct (no platform fee). `channelProvider` rows are per-tenant data, so the opt-in is pure configuration — docs and seed defaults point at `whatsapp-bsp` for production. A `bird-whatsapp` leg = new OC source seed **plus** an OR `ALLOWED_SOURCES` addition; consciously DEFERRED, no OR/OC issue filed (no demand driver with the CM.com default), out of scope.

Known Bird gap: test keys produce no delivery-status callbacks → status flows keep being tested with webhook fixtures (as inbound already is); occasional manual live smoke sends stay a runbook item, not CI.

## Decision 2 — SLA dispatch: adapters via lazy container, `customer`-addressed only

`dispatchNotification()` gains real `sms`/`whatsapp` legs:

- Resolve `SmsAdapter`/`WhatsAppAdapter` lazily through the already-injected `ContainerInterface` (same OR-absent-safe pattern as the rest of the codebase); resolution failure → `failed:` marker, never a Throwable out of the sweep.
- **Target resolution**: `sms`/`whatsapp` steps are supported for `notify: customer` — the breached object's `client`/`contact` reference is loaded and handed to the adapter (adapters extract the phone and re-check consent themselves). Non-customer roles (`team-lead`, `manager`, …) resolve to NC users who have no phone seam; those combinations return `unsupported:{channel}:{role}` markers (honest audit, no phantom lookups). This **replaces** the spec's phantom `OmnichanelService.preferredChannel(customer)` — no such service exists in `lib/` or `src/`.
- **WhatsApp template gating**: an SLA escalation is business-initiated and normally outside the 24h window, so the escalation step gains an optional `templateId` (schema fragment `55-sla-engine.json`); the step's template is passed to `WhatsAppAdapter::send()`, which already refuses un-approved/absent templates outside the window. A whatsapp step without `templateId` outside the window fails closed with a `template-missing:` marker.
- Consent refusal surfaces as `consent-missing:{channel}` in `notifiedActors` (the adapter already returns `STATUS_CONSENT_MISSING`).
- `email`/`webhook` keep the `'deferred:'` marker — now documented in the spec as delegated to their own capabilities instead of implied-working.

## Decision 3 — Send surface: `kind:'section'` body widget + server-side send endpoint

- **Placement**: `MessagingConversationSection` registered in `src/registry.js` as `kind: 'section'` (precedent: `ChannelDistributionSection` :575, `PosTransactionActionsSection` :682) and added to the comms-first grids of `ClientDetail` and `ContactDetail` in `src/manifest.json` next to the Email leaf (precedent: `client-email` integration widget, `src/manifest.json:480`). A new nc-vue builtin integration is deliberately avoided (cross-repo; ADR-019 registry already supports app-local sections).
- **Reads** go straight to OR objects (`conversation`/`message` schemas) via `createObjectStore` (store pattern rule) — no read-API wrapper (redundant-controller gate).
- **Send** goes through a new `MessagingController` (`messaging#send`, POST `/api/messaging/send`; `messaging#testProvider`, POST `/api/messaging/providers/{id}/test`; `messaging#consent`, POST `/api/messaging/consent`): consent/budget/template gating MUST stay server-side, so the frontend never talks to a provider or decides compliance. This is orchestration over the adapters, not an ObjectService pass-through — not redundant. Auth: `#[NoAdminRequired]` + app-group membership for `send`/`consent`; `testProvider` admin-gated (`#[AuthorizedAdminSetting]`/admin check) since it exercises tenant credentials.
- **Composer**: `SendMessageModal` in `src/modals/` (modal-isolation rule) — channel picker (from active `channelProvider` kinds), consent state banner with one-click opt-in recording (evidence + legal basis required), free-text body for SMS/within-window WhatsApp, template picker (approved `messageTemplate` rows) with parameter fields when outside the window (`isWithinSessionWindow` exposed via the send endpoint's preflight response).

## Decision 4 — Consent: opt-in for business-initiated WhatsApp; opt-out kept for SMS service traffic

`ConsentService::canSend` today lets an absent record pass (documented GDPR-legitimate-interest default). That is untenable for WhatsApp **business-initiated** template messages (Meta business-messaging policy requires opt-in) and stays acceptable for agent-initiated 1:1 SMS service replies under Telecommunicatiewet service-communication grounds (bulk/marketing is `marketing-compliance`'s problem). Therefore:

- New `ConsentService::canSendBusinessInitiated(contactId, channel)`: requires latest `messagingConsentRecord.state === 'opted-in'` for `whatsapp`; `WhatsAppAdapter` uses it whenever the send is template/business-initiated (outside session window). Within-window replies keep `canSend` (customer initiated the session; only `opted-out` blocks).
- SMS keeps `canSend` (block on `opted-out`) — recorded rationale: agent-initiated service messages, not marketing.
- Opt-in recording is a first-class UI act (composer banner + section header) writing through `ConsentService::recordOptIn` (evidence + `legalBasis` mandatory in the UI path) — never raw object writes from the frontend, so the audit trail is attributable.
- Breaking only for the previously unreachable template-send path; no stored data changes.

## Decision 5 — Test strategy: mock-mode sources in CI, provider test modes at the live gate

Three rings (API assertions in Newman, UI in Playwright — never crossed):

1. **CI contract ring (no network, always on)** — integration tests drive `send()` end-to-end against OR's leaf with the OpenConnector **mock-mode sources** (`configuration.mock: true` short-circuit): the full pipelinq→`MessageDispatchTrait`→`MessageDispatchProvider`→router pipeline runs and returns each vendor's canned response shape (e.g. `wamid.MOCK0001`). Newman covers `/api/messaging/send` + consent + provider-test endpoints; PHPUnit covers SLA dispatch, consent modes, contactmoment audit.
2. **Live gate (env-guarded, gate-19 conventions: flock, deployed-instance aware)** —
   - SMS: real dispatch through the `messagebird-sms` source configured with a **Bird test access key** (`test_…`) — full request validation at the real API, zero cost, nothing sent. Env: `PIPELINQ_LIVE_MESSAGING=1` + `BIRD_TEST_ACCESS_KEY`.
   - WhatsApp: real dispatch through `whatsapp-cloud-api` using the **Meta test number + registered test recipients** — returns a real `wamid.*` and fires production status webhooks (which also exercises delivery-status reconciliation). Env: `META_WA_TEST_TOKEN` + `META_WA_TEST_PHONE_ID`.
3. **UI ring** — Playwright `tests/e2e/spec-coverage/outbound-messaging.spec.ts` drives the settings page, the conversation section, and the composer through real clicks against the mock-mode pipeline (store reads = assertions only).

## Decision 6 — Contactmoment audit + schema deltas

- Outbound send (agent or SLA) writes a `contactmoment`: WhatsApp per the omnichannel convention `channel: "chat"`, `metadata: {platform: "whatsapp", direction: "outbound", messageId, conversationId}`; SMS as a new `channel: "sms"` enum value (`lib/Settings/pipelinq_register.json:1651` enum currently `telefoon|email|balie|chat|social|brief`). Enum extension is additive; the fragment import runs via the Repair step (ADR-037) — re-validate the merged register JSON (union-merge gotcha) and never let the audit write block or roll back the send (log-and-continue).
- `55-sla-engine.json`: escalation step gains optional `templateId` (Decision 2).
- `80-whatsapp-sms-channel.json`: `channelProvider.credentials` is annotated deprecated — since the OR-leaf re-point, credentials live on the OpenConnector source only (`MessageDispatchProvider` docblock: "never handles a vendor credential"); the admin UI manages routing metadata (vendor, kind, phoneNumber, webhookSecret, priority, active, sandbox, sourceId) and links out for credentials. One credential home (unification rule); no secret sprawl in OR objects.

## Decision 7 — Promotion contract (beta → stable)

`align-claims-and-first-hour` sets the overlay entry `beta` with a machine-readable reason (referenced, not edited here). This change flips it back to `stable` as its **final** task, gated on all of: (a) send surface + SLA dispatch shipped with green PHPUnit/Newman/Playwright rings, (b) the CI contract ring green in the default pipeline, (c) one green live-gate run recorded (Bird test key + Meta test number), (d) docs updated. If this change applies before align-claims lands, the flip task becomes a no-op-verify (entry already `stable`) — the criteria still MUST be demonstrated; neither change edits the other's artifacts.

## Open Questions

- ~~Which allow-listed leg is the WhatsApp **production** default — CM.com BSP (`whatsapp-bsp`) or Meta direct (`whatsapp-cloud-api`)?~~ **RESOLVED 2026-07-06** (owner: "go for gov friendly"): **CM.com `whatsapp-bsp` is the default production WhatsApp leg** (Premier BSP, Breda HQ, Dutch central-gov references); Meta `whatsapp-cloud-api` stays the CI/dev harness leg and an explicit per-tenant opt-in alternative; Bird stays primary for SMS + the zero-cost test ring. The Bird-native-WhatsApp OR/OC follow-up (new `bird-whatsapp` source + `ALLOWED_SOURCES` addition) is consciously DEFERRED — not filed.

## Risks / Trade-offs

- **Consent tightening** changes `WhatsAppAdapter` behavior for template sends — currently unreachable in production, so no user-visible regression; tests that assumed absent-record-passes are updated in the same batch.
- **Bird test keys ≠ delivery callbacks** — status reconciliation is only exercised by the Meta leg of the live gate + webhook fixtures. Accepted; documented in the runbook task.
- **SLA sends can surprise customers** — mitigated: whatsapp/sms steps are opt-in per policy, consent-gated per contact, budget-gated per tenant, and fully audited (breach event markers + contactmoment).
- **Contactmoment enum extension** ripples into facets/reports — `channel` is `facetable`; reporting endpoints group dynamically, but the rapportage channel labels must include `sms` (checked in tasks).
- **Non-customer sms/whatsapp escalation unsupported** — honest `unsupported:` marker rather than a half-built NC-user-phone mapping; revisit only if a real deployment demands it.
- **Mock-mode fixtures can drift from vendor reality** — the live gate exists precisely to catch request-shape drift at zero cost.

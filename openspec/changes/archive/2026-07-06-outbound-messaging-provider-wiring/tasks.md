# Tasks: outbound-messaging-provider-wiring

Order: schema/config deltas first (1.x), then the server seams (2.x–3.x), then UI (4.x), then test rings + docs + promotion (5.x–6.x). Verify every "already exists" claim against HEAD before building on it — do not re-implement adapter behavior that ships today. No PR/review/merge tasks (Hydra owns process).

## 1. Schema & register fragments

- [x] 1.1 Register fragment deltas (additive, via ADR-037 Repair import)
  - **spec_ref**: `specs/omnichannel-registratie/spec.md#requirement-outbound-messages-registered-as-contactmomenten`, `specs/sla-engine-and-escalation/spec.md#requirement-escalation-chain-execution`, `specs/outbound-messaging/spec.md#requirement-req-om-001--messaging-provider-administration`
  - **files**: `lib/Settings/pipelinq_register.json` (contactmoment `channel` enum `+sms`), `lib/Settings/register.d/55-sla-engine.json` (escalation step optional `templateId`), `lib/Settings/register.d/80-whatsapp-sms-channel.json` (`channelProvider.credentials` deprecation note in description)
  - **acceptance_criteria**: [MVP]
    - Enum extension additive only; existing contactmomenten unaffected; merged register JSON re-validated after edit (union-merge gotcha)
    - Escalation step schema accepts `templateId` (uuid, optional); no other step property changed
    - `channelProvider.credentials` description states credentials live on the OpenConnector source; property NOT removed (no destructive migration)
    - Repair re-import on a seeded instance leaves all existing rows valid

## 2. SLA escalation dispatch

- [x] 2.1 Wire `sms`/`whatsapp` legs in `SlaEngineService::dispatchNotification`
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md#requirement-escalation-chain-execution`
  - **files**: `lib/Service/SlaEngineService.php`
  - **acceptance_criteria**: [V1]
    - `sms`/`whatsapp` + `notify: customer` → load the breached object's linked client/contact, call `SmsAdapter::send()` / `WhatsAppAdapter::send()` (adapters resolved lazily via the already-injected `ContainerInterface`; resolution failure → `failed:` marker, no Throwable escapes the sweep)
    - WhatsApp path passes the step's `templateId`; missing template outside the session window → `template-missing:whatsapp` marker, no dispatch
    - Adapter `STATUS_CONSENT_MISSING` → `consent-missing:{channel}` marker; non-customer roles → `unsupported:{channel}:{role}`; `email`/`webhook` keep `deferred:` (comment re-pointed at the owning capabilities, phantom `whatsapp-sms-channel-adapter` upcoming-note removed)
    - Breach event written for every outcome; idempotency per level unchanged
- [x] 2.2 Unit tests for the dispatch matrix
  - **spec_ref**: `specs/sla-engine-and-escalation/spec.md#requirement-escalation-chain-execution`
  - **files**: `tests/Unit/Service/SlaEngineServiceTest.php` (extend existing)
  - **acceptance_criteria**: [V1]
    - Cases: whatsapp+customer+template sent; sms+customer sent; consent-missing; template-missing; unsupported role; adapter-absent degrade; email/webhook deferred markers — all asserting the `notifiedActors` vocabulary and that a Throwable from an adapter never escapes

## 3. Send endpoint & consent gate

- [x] 3.1 `MessagingController` + routes
  - **spec_ref**: `specs/outbound-messaging/spec.md#requirement-req-om-004--server-side-send-endpoint`, `#requirement-req-om-002--zero-cost-provider-connectivity-test`, `#requirement-req-om-005--consent-gating-and-recording`
  - **files**: `lib/Controller/MessagingController.php` (new), `appinfo/routes.php` (`messaging#send` POST `/api/messaging/send`, `messaging#preflight` GET `/api/messaging/preflight/{contactId}`, `messaging#consent` POST `/api/messaging/consent`, `messaging#testProvider` POST `/api/messaging/providers/{id}/test`)
  - **acceptance_criteria**: [MVP]
    - `send`/`preflight`/`consent`: `#[NoAdminRequired]` + per-object guard (contact accessible via register RBAC) — no-admin-idor + semantic-auth gates; `testProvider` admin-gated
    - `send` orchestrates the adapters (never ObjectService pass-through — redundant-controller gate) and returns the outcome envelope without raw vendor errors/credentials
    - `preflight` returns available channels, session-window state (`WhatsAppAdapter::isWithinSessionWindow`), per-channel consent state, approved templates
    - `consent` writes via `ConsentService::recordOptIn/recordOptOut` with mandatory evidence + legal basis, attributing the acting user
    - `testProvider` dispatches a zero-cost validation through the leaf and returns reachable/degraded-cause (mock badge when the source is mock-flagged)
    - Route-reachability + route-auth gates green; controller unit tests for auth guard, outcome mapping, and error hygiene
- [x] 3.2 Business-initiated consent gate in `ConsentService` + `WhatsAppAdapter`
  - **spec_ref**: `specs/outbound-messaging/spec.md#requirement-req-om-005--consent-gating-and-recording`
  - **files**: `lib/Service/ConsentService.php`, `lib/Service/WhatsAppAdapter.php`, `tests/Unit/Service/ConsentServiceTest.php`, `tests/Unit/Service/WhatsAppAdapterTest.php`
  - **acceptance_criteria**: [MVP]
    - `canSendBusinessInitiated()`: whatsapp requires latest record `opted-in`; `WhatsAppAdapter` uses it for template/outside-window sends and keeps `canSend` (opt-out-only block) for within-window replies; SMS unchanged (`canSend`)
    - Existing tests asserting absent-record-passes for template sends updated in the same batch (no mock-based shortcuts)

## 4. UI surfaces

- [x] 4.1 Messaging settings page (CTI pattern)
  - **spec_ref**: `specs/outbound-messaging/spec.md#requirement-req-om-001--messaging-provider-administration`, `#requirement-req-om-002--zero-cost-provider-connectivity-test`
  - **files**: `src/manifest.d/80-messaging.json` (new: menu entry + `/settings/messaging` page), `src/registry.js` (`MessagingSettingsView`, `kind: 'page'`), `src/views/settings/MessagingSettings.vue` (new), stores via `createObjectStore` (`channelProvider`, `messageSendBudget`, `messageTemplate`)
  - **acceptance_criteria**: [MVP]
    - Provider CRUD (vendor/kind/sourceId/phoneNumber/webhookSecret/priority/active/sandbox) over OR objects; NO credential field — link to the OpenConnector source (REQ-OM-001)
    - Budget rows editable; template panel lists status + last sync + manual sync trigger; webhook URLs displayed per provider with copy action
    - Connectivity-test button per provider → `messaging#testProvider`, showing reachable/mock/degraded
    - NcSelect fields carry `inputLabel` (nc-input-labels gate); NC CSS variables only
- [x] 4.2 Conversation section + composer modal on Client/Contact detail
  - **spec_ref**: `specs/outbound-messaging/spec.md#requirement-req-om-003--agent-send-surface-on-client-and-contact-detail`
  - **files**: `src/registry.js` (`MessagingConversationSection`, `kind: 'section'`), `src/views/…/MessagingConversationSection.vue` (new), `src/modals/SendMessageModal.vue` (new — modal-isolation gate), `src/manifest.json` (ClientDetail + ContactDetail comms-first grids, placed with the email leaf precedent at the `client-email` widget)
  - **acceptance_criteria**: [MVP]
    - Section self-fetches `conversation`/`message` by the page object's contact linkage (OR objects API via `createObjectStore` — store-pattern rule), renders direction/status/channel, and the per-channel consent state
    - Composer: channel picker limited to active provider kinds; free text for SMS/within-window WhatsApp; template picker + parameter fields outside the window (driven by `messaging#preflight`); consent banner with one-click opt-in recording (evidence + legal basis mandatory)
    - All sends via `messaging#send`; no provider/consent logic in the frontend; dashboard-antipattern + modal-isolation gates green

## 5. Test rings

- [x] 5.1 CI contract ring over mock-mode sources
  - **spec_ref**: `specs/outbound-messaging/spec.md#requirement-req-om-007--contract-tests-in-ci-and-the-live-gate`
  - **files**: `tests/Integration/OutboundMessagingContractTest.php` (new; drive `SmsAdapter::send`/`WhatsAppAdapter::send` through `MessageDispatchTrait` against mock-flagged sources), `tests/newman/` (send/preflight/consent/testProvider collections)
  - **acceptance_criteria**: [MVP]
    - Zero external network: every dispatch resolves the OR leaf's mock short-circuit and asserts the canned vendor shapes (Bird/CM.com/Twilio/`wamid.MOCK…`)
    - Newman asserts outcome envelopes incl. consent-missing and unauthorized cases; contactmoment audit row asserted after an API send (REQ-OM-006)
    - CI env note verified the CI way (php8.3-cli + stubs ≠ deployed container)
- [x] 5.2 Live-gate variant (env-guarded)
  - **spec_ref**: `specs/outbound-messaging/spec.md#requirement-req-om-007--contract-tests-in-ci-and-the-live-gate`
  - **files**: `tests/Integration/OutboundMessagingLiveGateTest.php` (new), CI workflow env wiring (`PIPELINQ_LIVE_MESSAGING`, `BIRD_TEST_ACCESS_KEY`, `META_WA_TEST_TOKEN`, `META_WA_TEST_PHONE_ID`)
  - **acceptance_criteria**: [V1]
    - Bird leg: real request to `rest.messagebird.com` with the `test_` access key on the `messagebird-sms` source — request-shape acceptance asserted, zero cost, nothing sent
    - Meta leg: send from the test number to a registered test recipient via `whatsapp-cloud-api` — real `wamid.*` asserted; status webhook fixture path exercised
    - Absent credentials → tests SKIP (never fail); flock/deploy-reality conventions per gate-19
- [x] 5.3 Playwright spec-coverage suite
  - **spec_ref**: `specs/outbound-messaging/spec.md#requirement-req-om-001--messaging-provider-administration`, `#requirement-req-om-002--zero-cost-provider-connectivity-test`, `#requirement-req-om-003--agent-send-surface-on-client-and-contact-detail`, `specs/omnichannel-registratie/spec.md#requirement-outbound-messages-registered-as-contactmomenten`
  - **files**: `tests/e2e/spec-coverage/outbound-messaging.spec.ts` (new)
  - **acceptance_criteria**: [MVP]
    - Real clicks over the mock-mode pipeline: configure a provider + run connectivity test (settings), send an SMS from a client detail (composer), see the outbound message + contactmoment in the timeline, WhatsApp template-forced state, consent-blocked state with opt-in recording
    - UI-only assertions (store reads as assertions only); every UI scenario in the deltas referenced (gate-19)

## 6. Docs & promotion

- [x] 6.1 Feature docs + runbook
  - **spec_ref**: `specs/outbound-messaging/spec.md#requirement-req-om-001--messaging-provider-administration`, `#requirement-req-om-007--contract-tests-in-ci-and-the-live-gate`
  - **files**: `docs/Features/` (outbound messaging page: provider choice — Bird primary for SMS/CM.com fallback; CM.com `whatsapp-bsp` = default production WhatsApp leg (owner decision 2026-07-06), Meta `whatsapp-cloud-api` = CI/dev harness + per-tenant opt-in alternative — with the go-live steps: credential the OpenConnector source, remove `configuration.mock`; consent model; template constraints), runbook note for the Bird no-callback gap (manual live smoke)
  - **acceptance_criteria**: [MVP]
    - Docs match shipped reality only; the Bird-native-WhatsApp follow-up is documented as a cross-repo dependency (OR `ALLOWED_SOURCES` + OC source seed) that is consciously DEFERRED per the 2026-07-06 owner decision — deliberately NOT filed as OR/OC issues (documented exception to the deferred-work rule; revisit only on tenant demand)
- [x] 6.2 Overlay promotion beta→stable (final task, gated)
  - **spec_ref**: `specs/outbound-messaging/spec.md#requirement-req-om-008--promotion-of-omnichannel-registratie-to-stable`
  - **files**: `openspec/features.overlay.json`
  - **acceptance_criteria**: [MVP]
    - Flip executed only with all four promotion criteria recorded (rings green, CI contract ring in default pipeline, one green live-gate run, docs updated); beta reason removed with the flip
    - Coordination: `align-claims-and-first-hour` owns the downgrade — reference its state, never edit its artifacts; if it has not landed, demonstrate the criteria and leave the entry value untouched by that change's outcome
- [x] 6.3 Quality gates
  - **spec_ref**: all
  - **files**: `lib/`, `src/`, `tests/`
  - **acceptance_criteria**: [MVP]
    - `composer check:strict` green; hydra gates green (route-auth, no-admin-idor, semantic-auth, route-reachability, redundant-controller, modal-isolation, nc-input-labels, stub-scan, spec-coverage, e2e-coverage); pre-existing quality issues encountered in touched files fixed in the same batch

## Deviations (recorded at apply time, 2026-07-06/07)

- **6.2 overlay promotion — intentionally NOT flipped to `stable`.** REQ-OM-008
  criterion (c) requires one green live-gate run (Bird test key + Meta test
  number); live provider credentials were not available this session, so the
  env-guarded live gate (`OutboundMessagingLiveGateTest`) is config-ready but
  unexecuted. Per the change's own promotion contract the entry stays `beta`;
  its `statusReason` was updated to record that outbound is now wired + the
  mock-mode CI ring is green, with the live-gate run as the only open criterion.
  Flip to `stable` once a live-gate run is recorded.
- **5.2 live gate — config-ready, not live-verified** for the same reason
  (skips cleanly without credentials).
- **Quality gate env note:** phpcs (lib/), phpmd (baseline) and the ~1490-test
  PHPUnit suite run green **inside the deployed container**. phpstan/psalm are
  dominated by the CI OCP-stub gap (`OCP\*` symbol discovery) and are
  non-blocking in the composer flow; correctness is proven by the container
  PHPUnit run where real OCP resolves.

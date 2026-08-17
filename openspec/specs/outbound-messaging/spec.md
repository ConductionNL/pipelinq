# outbound-messaging Specification

## Purpose
TBD - created by archiving change outbound-messaging-provider-wiring. Update Purpose after archive.
## Requirements
### Requirement: REQ-OM-001 — Messaging provider administration

The system MUST provide a messaging settings surface (settings-section page per the CTI-settings pattern: `src/manifest.d/` fragment + registry component + `src/views/settings/` view) where an admin can create, edit, prioritise, activate/deactivate, and sandbox-flag `channelProvider` rows (kinds `sms` and `whatsapp`; vendors at minimum `messagebird`, `cmcom`, `twilio`, `meta`), configure `messageSendBudget` rows, see WhatsApp template sync status (`messageTemplate` rows + last `TemplateApprovalSyncJob` run) with a manual sync trigger, and read the inbound webhook URLs (`/api/messaging-webhooks/{whatsapp|sms}/{providerId}`) for pasting into the provider console. Vendor credentials MUST NOT be stored on `channelProvider` rows — credentials live exclusively on the OpenConnector source (the `credentials` property is deprecated); the row carries routing metadata only (vendor, kind, sourceId, phoneNumber, webhookSecret, priority, active, sandbox).

#### Scenario: Admin configures the primary provider

- **GIVEN** an admin on the messaging settings page
- **WHEN** they create a `channelProvider` row with `kind: sms`, `vendor: messagebird`, `sourceId: messagebird-sms`, a sender phone number, a webhook secret, and `priority: 10`
- **THEN** the row MUST be persisted in the pipelinq register and listed with its active/sandbox state
- AND the page MUST show the SMS inbound webhook URL for that provider id

#### Scenario: Credentials are pointed at OpenConnector, never stored

- **GIVEN** the provider create/edit form
- **WHEN** the admin looks for a credential field
- **THEN** the form MUST NOT offer API-key/credential input and MUST link to the OpenConnector source as the credential home

#### Scenario: Template sync status and manual trigger

- **GIVEN** at least one active WhatsApp provider
- **WHEN** the admin opens the templates panel and triggers a sync
- **THEN** the approved/pending/rejected `messageTemplate` rows MUST be listed with their status and last-synced timestamp
- AND the manual trigger MUST run the same sync path as `TemplateApprovalSyncJob`

### Requirement: REQ-OM-002 — Zero-cost provider connectivity test

The system MUST offer an admin-gated connectivity test per configured provider that performs a real request through the OR `MessageDispatchProvider` leaf without sending a customer-visible message and without cost: for Bird, a validation send using a **test access key** on the source; for a mock-flagged source, the canned mock response; for Meta, a send to a registered **test recipient** from the test number. The test result (reachable / degraded cause) MUST be shown on the settings page.

#### Scenario: Connectivity test against a mock-mode source

- **GIVEN** a provider row whose OpenConnector source still has `configuration.mock: true`
- **WHEN** the admin runs the connectivity test
- **THEN** the full pipelinq→OR-leaf→router pipeline MUST execute and return the canned vendor-shaped body
- AND the settings page MUST show the provider as reachable with a "mock mode" badge

#### Scenario: Connectivity test surfaces a degraded leaf

- **GIVEN** a provider row whose `sourceId` is not in the OR allow-list, or OpenRegister/OpenConnector is absent
- **WHEN** the admin runs the connectivity test
- **THEN** the page MUST show the degrade cause (e.g. source missing / leaf unavailable) without any Throwable reaching the browser as a 500

### Requirement: REQ-OM-003 — Agent send surface on client and contact detail

The system MUST render a Messages conversation section (registered `kind:'section'` body widget) on the comms-first Client and Contact detail grids, showing the contact's `conversation`/`message` history (both directions, delivery status, channel), the per-channel consent state, and a send action opening a composer modal (own file under `src/modals/`). The composer MUST offer the channels with an active provider, free-text body for SMS and for WhatsApp within an open 24h session window, and a template picker (approved `messageTemplate` rows with parameter fields) for WhatsApp outside the window. Sends MUST go through the server-side send endpoint (REQ-OM-004) — the frontend MUST NOT decide consent, budget, or template gating.

#### Scenario: Agent sends an SMS from a client record

- **GIVEN** an agent on a Client detail page whose linked contact has a phone number and no `opted-out` SMS consent record
- AND an active SMS provider (mock-mode source in test)
- **WHEN** the agent opens the composer, picks SMS, types a body, and sends
- **THEN** the message MUST be dispatched via `SmsAdapter::send()` and appear in the conversation section as outbound with its delivery status
- AND a `contactmoment` audit row MUST exist per REQ-OM-006

#### Scenario: WhatsApp outside the session window forces a template

- **GIVEN** a contact with no open 24h WhatsApp session window
- **WHEN** the agent picks WhatsApp in the composer
- **THEN** free-text entry MUST be disabled and only approved templates (with parameter fields) MUST be offered

#### Scenario: Composer blocks and explains on missing consent

- **GIVEN** a contact whose latest WhatsApp consent record is not `opted-in`
- **WHEN** the agent picks WhatsApp for a business-initiated (template) send
- **THEN** the composer MUST show the consent state and a one-click opt-in recording action (REQ-OM-005) instead of a send button

### Requirement: REQ-OM-004 — Server-side send endpoint

@e2e exclude API contract, requirement-scoped: every scenario here asserts the HTTP endpoint, which is exercised by Newman (`tests/newman`) and by MessagingControllerTest / OutboundMessagingContractTest — never by Playwright, because API assertions do not belong in the UI ring. The UI path over this endpoint is covered by REQ-OM-003's scenarios. Marker moved here from the end of the requirement body, where it bound to the last scenario only.

The system MUST expose an authenticated send endpoint (`messaging#send`, POST `/api/messaging/send`) that accepts contact id, channel, body or `templateId` + parameters, and an optional provider hint, orchestrates `WhatsAppAdapter::send()` / `SmsAdapter::send()` (all consent, budget, template, and failover gating server-side), and returns the adapter outcome envelope (sent / consent-missing / budget-exceeded / template-missing / failed) with an HTTP status that never leaks provider credentials or raw vendor errors. A preflight response (or dedicated GET) MUST expose the composer's gating facts: available channels, session-window state, consent state, approved templates. The endpoint MUST be group-authorized (`#[NoAdminRequired]` + per-object access via the contact's register RBAC) — no admin requirement, no public access.

#### Scenario: Send API dispatches and persists

- **WHEN** an authorized agent POSTs a valid SMS send for a consenting contact
- **THEN** the response MUST carry the outcome envelope with the persisted `message` id and delivery status
- AND the message MUST have been transported through the OR `MessageDispatchProvider` leaf (mock-mode source in CI)

#### Scenario: Send API refuses a business-initiated WhatsApp without opt-in

- **WHEN** an agent POSTs a WhatsApp template send for a contact without an `opted-in` record
- **THEN** the response MUST be the `consent-missing` outcome and no dispatch MUST occur

#### Scenario: Unauthorized caller is rejected

- **WHEN** a user without access to the contact's register objects calls the send endpoint
- **THEN** the request MUST be rejected before any adapter is invoked


### Requirement: REQ-OM-005 — Consent gating and recording

Business-initiated WhatsApp messages (template sends outside the 24h session window — including SLA escalations) MUST require the contact's latest `messagingConsentRecord` for `whatsapp` to be `opted-in` (Meta business-messaging policy). Within-window WhatsApp replies and agent-initiated 1:1 SMS service messages MUST be blocked only by an `opted-out` record (service-communication ground; bulk/marketing consent stays owned by `marketing-compliance`). Consent state per channel MUST be visible on the send surface, and opt-in/opt-out MUST be recordable there through `ConsentService` with mandatory evidence and legal basis — consent records MUST NOT be written as raw frontend object writes.

#### Scenario: Recording an opt-in from the send surface

- **GIVEN** an agent viewing a contact whose WhatsApp consent state is `unknown`
- **WHEN** they record an opt-in with source, evidence text, and legal basis
- **THEN** an `opted-in` `messagingConsentRecord` MUST be appended (never mutated) attributing the acting user
- AND the composer MUST immediately allow template sends

#### Scenario: Opt-out always wins

- **GIVEN** a contact with a latest `opted-out` SMS record
- **WHEN** any SMS send is attempted (composer or SLA escalation)
- **THEN** the send MUST be refused with the `consent-missing`/blocked outcome

#### Scenario: Within-window WhatsApp reply without explicit opt-in

- **GIVEN** a contact who messaged in 2 hours ago (open session window) and has no consent record
- **WHEN** the agent sends a free-text WhatsApp reply
- **THEN** the send MUST be allowed (customer-initiated session; only `opted-out` blocks)

### Requirement: REQ-OM-006 — Outbound sends audited as contactmomenten

@e2e exclude backend audit side-effect, requirement-scoped: OutboundMessagingContractTest::testSmsSendPersistsAndAudits asserts exactly one ticket row with ticketType=contactmoment, channel=sms, the client link and channelMetadata.direction/platform. NOT asserted: metadata.messageId pointing at the persisted message row — an unwritten PHPUnit case, not an e2e gap. The visible contactmoment list is covered by the contactmomenten e2e specs. Marker moved here from the end of the requirement body, where it bound to the last scenario only.

Every successful outbound send (agent composer or SLA escalation) MUST write a `contactmoment` linked to the client/contact: WhatsApp with `channel: "chat"` and `metadata: {platform: "whatsapp", direction: "outbound", messageId, conversationId}` (omnichannel convention); SMS with the new `channel: "sms"` enum value and the same metadata shape. The audit write MUST be log-and-continue — it MUST never block, fail, or roll back the send itself.

#### Scenario: SMS send produces a contactmoment

- **WHEN** an SMS send succeeds for a contact linked to a client
- **THEN** a `contactmoment` MUST exist with `channel: "sms"`, the client/contact references, and `metadata.messageId` pointing at the persisted `message` row

#### Scenario: Audit failure never blocks the send

- **GIVEN** the contactmoment write fails (e.g. schema unavailable)
- **WHEN** a send succeeds
- **THEN** the send outcome MUST still be `sent` and the failure MUST be logged


### Requirement: REQ-OM-007 — Contract tests in CI and the live gate

@e2e exclude test-infrastructure requirement, requirement-scoped: the subject is the CI/Newman/PHPUnit rings themselves, so demanding a Playwright test OF the test rings is a category error. The Playwright coverage this requirement mandates is declared on REQ-OM-001/002/003's scenarios. Marker moved here from the end of the requirement body, where it bound to the last scenario only.

The default CI pipeline MUST run the outbound pipeline end-to-end with zero external network by using OpenConnector's seeded mock-mode sources (`configuration.mock: true` — OR's `ExternalIntegrationRouter` short-circuits and returns the canned vendor-shaped `mockResponse`): PHPUnit integration tests through `MessageDispatchTrait`, Newman against the send/consent/test endpoints, Playwright over the settings + composer surfaces. An env-guarded live-gate variant (gate-19 conventions) MUST validate real provider request shapes at zero cost: SMS via the `messagebird-sms` source configured with a Bird **test access key** (`test_…` — request validated, nothing sent, no charge), WhatsApp via the `whatsapp-cloud-api` source using the Meta **test number + registered test recipients** (real `wamid.*` + production status webhooks). The live gate MUST be skipped (not failed) when its env credentials are absent.

**Feature tier**: MVP (CI ring), V1 (live gate)

#### Scenario: CI contract ring is network-free

- **WHEN** the messaging integration tests run in CI without any provider credential
- **THEN** every dispatch MUST resolve through the mock-flagged sources and assert the canned vendor response shapes (e.g. a `wamid.MOCK…` id)
- AND no test MUST open a connection to a provider host

#### Scenario: Live gate validates the Bird request shape

- **GIVEN** `PIPELINQ_LIVE_MESSAGING=1` and `BIRD_TEST_ACCESS_KEY` set
- **WHEN** the live-gate suite runs an SMS send through the real `rest.messagebird.com`
- **THEN** the request MUST be accepted by Bird's validation (no message dispatched, zero cost)
- AND a request-shape rejection MUST fail the gate

#### Scenario: Live gate skips without credentials

- **WHEN** the live-gate suite runs without the messaging env credentials
- **THEN** the messaging live tests MUST report skipped, not failed


### Requirement: REQ-OM-008 — Promotion of omnichannel-registratie to stable

The `omnichannel-registratie` overlay entry (`openspec/features.overlay.json`) MUST be returned from `beta` (the state set by `align-claims-and-first-hour` — referenced, not edited by this change) to `stable` only when all of the following hold: the send surface and SLA sms/whatsapp dispatch are shipped with green PHPUnit, Newman, and Playwright rings; the mock-source CI contract ring is green in the default pipeline; at least one live-gate run (Bird test key + Meta test number) has passed; and the feature docs describe outbound send. If the entry is still `stable` because `align-claims-and-first-hour` has not landed, the criteria MUST still be demonstrated before this change completes.

#### Scenario: Overlay flip is gated

- **WHEN** the final task of this change runs
- **THEN** the overlay entry MUST be set to `stable` only with all four promotion criteria recorded as met
- AND the machine-readable beta reason MUST be removed together with the flip

`@e2e exclude` metadata/process gate — overlay JSON state, asserted by the change's verify step, no UI runtime surface.


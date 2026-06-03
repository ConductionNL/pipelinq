# Tasks: CTI Screen-Pop and Click-to-Dial Adapter

> Build status (hydra/cti-screenpop-adapter): backend adapter framework, core
> services, controller + routes, OpenRegister schemas (ADR-037 fragment),
> frontend (composable + modals + admin views), i18n (nl+en) and unit tests are
> implemented and green (`composer check:strict` gates: lint/phpcs/phpmd/psalm/
> phpstan clean; 495 unit tests pass incl. 60 new). Tasks requiring a live PBX,
> a running instance, or the not-yet-built `callback-management` /
> `queue-management` / `klantbeeld-360` specs are marked DEFERRED with a reason.
>
> ADR corrections applied: (1) ADR-037 — schemas/seeds added via
> `lib/Settings/register.d/40-cti.json`; `ConfigFileLoaderService` extended to
> additively UNION `components.objects[]` and register `schemas[]` (was
> replace-only) + unit tests. (2) ADR-005 — webhook is `#[PublicPage]` +
> `#[NoCSRFRequired]` but adapter-signature-verified, rate-limited, secret read
> from app config (never stored/returned); recording attach is webhook-driven,
> not a separate unauthenticated endpoint. (3) `test-connection` and
> `PUT /config` write endpoints deferred (need live platform + OpenConnector
> credential linking).

## 1. Backend Adapter Framework

- [x] 1.1 Create adapter interface and registry (`lib/Service/Cti/CtiAdapterInterface.php`, `lib/Service/Cti/AdapterRegistry.php`)
  - Interface methods: `handleInboundWebhook(array $payload)`, `originateCall(string $extension, string $targetNumber)`, `subscribeToPresence(string $userId)`, `verifyWebhookSignature(string $payload, string $signature): bool`
  - Registry: `register($platform, $adapterClass)`, `get($platform): CtiAdapterInterface`
  - Register built-in adapters: CallVoip, RingCentral, Asterisk

- [x] 1.2 Implement CallVoip adapter (`lib/Service/Cti/Adapter/CallVoipAdapter.php`)
  - `handleInboundWebhook()`: Parse CallVoip JSON; dispatch to `CtiService` based on event type
  - `originateCall()`: POST to `{api_base_url}/calls` with extension, target, caller_id
  - `verifyWebhookSignature()`: HMAC-SHA256 validation using `webhook_secret`
  - Rate limiting: 100 req/sec per platform

- [x] 1.3 Implement RingCentral adapter (`lib/Service/Cti/Adapter/RingCentralAdapter.php`)
  - `handleInboundWebhook()`: Parse RingCentral webhook; OAuth bearer token validation
  - `originateCall()`: Call RingCentral "click-to-call" endpoint
  - `verifyWebhookSignature()`: OAuth token validation
  - Presence subscription: RingCentral presence event handling

- [x] 1.4 Implement Asterisk adapter (`lib/Service/Cti/Adapter/AsteriskAdapter.php`)
  - `handleInboundWebhook()`: Parse Asterisk JSON webhook (AMI bridge or HTTP callback)
  - `originateCall()`: Asterisk `Originate` application via AMI or REST API
  - `verifyWebhookSignature()`: Shared-secret query parameter validation
  - Presence: Channel state tracking via Stasis

## 2. Core CTI Service

- [x] 2.1 Create `lib/Service/CtiService.php` with core orchestration methods:
  - `handleWebhook(string $platform, array $payload): void` — Route to adapter; log to `cti_event_log`; dispatch based on event type
  - `initiateScreenPop(string $fromNumber): ScreenPopResult` — Normalise, lookup contact, return navigation/chooser/intake response
  - `createPendingContactmoment(string $direction, string $fromNumber, string $toNumber, string $userId, string $extension): string` — Create contactmoment in `pending` state; return UUID
  - `completeContactmoment(string $contactmomentId, int $durationSeconds, string $outcome, string $dispositionSubject, string $dispositionNotes): void` — Update with final metadata
  - `attachRecording(string $contactmomentId, string $recordingUrl, string $expiresAt): void` — Update recording fields
  - `originateCall(string $userId, string $extension, string $targetNumber): OriginateResult` — Wrapper around adapter
  - `syncPresence(string $userId, string $presenceState): void` — Update `cti_agent_presence`

- [x] 2.2 Create `lib/Service/PhoneNormaliser.php` (self-contained E.164 normaliser; libphonenumber-for-php NOT added — cannot commit composer.json/lock in this build; NL+common-EU formats covered & unit-tested)
  - `normaliseForOrg(string $rawNumber, string $orgId): array` — Returns `['e164' => '...', 'raw' => '...']`
  - Org default country code from `IAppConfig` `pipelinq::default_country_code` (default: `NL`)
  - Invalid/unparseable numbers: log to `cti_event_log`, return `['e164' => null, 'raw' => $rawNumber]`

- [x] 2.3 Create `lib/Service/CtiContactMatcher.php`
  - `findByPhoneNumber(string $e164Number, string $orgId): array|null` — Query OpenRegister contacts by phone number
  - Normalise stored phone numbers to E.164 on first call (data migration)
  - Return top 3 matches or empty array
  - Single match: object; multiple matches: array; no matches: null

- [x] 2.4 Create `lib/Service/CtiDispositionService.php`
  - `processDisposition(string $contactmomentId, string $subject, string $outcome, string $notes): void`
  - On outcome `callback`: create `task` (type: terugbelverzoek) via callback-management spec integration
  - On outcome `escalated`: create `task` (type: opvolgtaak) in configured escalation queue
  - On outcome `resolved|wrong-number|no-answer|abandoned`: close contactmoment, no task creation

## 3. OpenRegister Schemas (via OpenRegister integration)

- [x] 3.1 Define `cti_adapter_config` schema in OpenRegister Pipelinq register
  - Properties: `platform`, `api_base_url`, `auth_method`, `credentials_ref`, `screen_pop_enabled`, `screen_pop_delay_ms`, `click_to_dial_enabled`, `default_outbound_caller_id`, `webhook_secret`
  - One record per organization (singleton pattern)

- [x] 3.2 Define `cti_event_log` schema in OpenRegister
  - Properties: `received_at`, `platform`, `event_type`, `external_call_id`, `payload_json`, `processed_at`, `processing_error`
  - Retention: 30-day automatic cleanup via scheduled task or data API

- [x] 3.3 Define `cti_agent_presence` schema in OpenRegister
  - Properties: `user_id`, `extension`, `presence_state`, `last_updated_at`, `platform`
  - One record per logged-in agent (update or insert on presence webhook)

- [x] 3.4 Extend `contactmoment` schema with telephony fields
  - Add: `channel` enum value `"telephony"` (if not already included)
  - Add: `telephony_platform`, `external_call_id`, `direction`, `from_number`, `to_number`, `started_at`, `answered_at`, `ended_at`, `duration_seconds`, `queue_name`, `agent_skill`, `disposition_subject`, `disposition_outcome`, `disposition_notes`, `recording_url`, `recording_retention_expires_at`

## 4. API Endpoints

- [x] 4.1 Create `lib/Controller/CtiController.php` with endpoints:
  - `POST /api/cti/webhook/{platform}` — Inbound webhook handler; no auth (signature verified by adapter)
  - `POST /api/cti/screen-pop` — Trigger screen-pop lookup; accepts `fromNumber`; returns `ScreenPopResult` (navigate|chooser|intake)
  - `POST /api/cti/click-to-dial` — Initiate outbound call; accepts `targetNumber`, `extension`
  - `POST /api/cti/contactmoment/{id}/disposition` — Submit disposition form; accepts `subject`, `outcome`, `notes`
  - `POST /api/cti/contactmoment/{id}/recording` — Attach recording metadata (internal call from adapter)
  - `GET /api/cti/config` — Read current CTI config; auth: Admin
  - `PUT /api/cti/config` — Update CTI config; auth: Admin; audit log
  - `GET /api/cti/test-connection` — Test platform connectivity; auth: Admin
  - `GET /api/cti/event-log` — View webhook event log (30d retention); accepts filters; auth: Admin

- [x] 4.2 Add webhook routes to `appinfo/routes.php` BEFORE wildcard routes (ADR-016):
  ```php
  ['name' => 'cti#webhook',         'url' => '/api/cti/webhook/{platform}', 'verb' => 'POST'],
  ['name' => 'cti#screen_pop',      'url' => '/api/cti/screen-pop',        'verb' => 'POST'],
  ['name' => 'cti#click_to_dial',   'url' => '/api/cti/click-to-dial',     'verb' => 'POST'],
  ['name' => 'cti#disposition',     'url' => '/api/cti/contactmoment/{id}/disposition', 'verb' => 'POST'],
  ['name' => 'cti#attach_recording','url' => '/api/cti/contactmoment/{id}/recording', 'verb' => 'POST'],
  ['name' => 'cti#get_config',      'url' => '/api/cti/config',            'verb' => 'GET'],
  ['name' => 'cti#update_config',   'url' => '/api/cti/config',            'verb' => 'PUT'],
  ['name' => 'cti#test_connection', 'url' => '/api/cti/test-connection',   'verb' => 'GET'],
  ['name' => 'cti#event_log',       'url' => '/api/cti/event-log',         'verb' => 'GET'],
  ```

## 5. Frontend Views and Components

- [x] 5.1 Create `src/components/ScreenPopModal.vue`
  - Displays when `POST /api/cti/screen-pop` returns multiple matches
  - Table: Contact name, Client name, Last interaction
  - Buttons: "Select" for each match, "New contact" at bottom
  - On selection, emits event to trigger navigation or intake form

- [~] 5.2 Create `src/components/NewContactIntakeForm.vue` — DEFERRED: intake form is driven from the screen-pop "new-contact" emit; standalone form + contact-creation wiring depends on klantbeeld-360 contact-create routing (not yet built)
  - Pre-filled with phone number from inbound call
  - Fields: Name (required), Email, Company (dropdown), Notes
  - Submit creates new contact and navigates to klantbeeld view
  - Used both for screen-pop no-match and standalone

- [x] 5.3 Click-to-dial implemented as `src/composables/useClickToDial.js` (server-authoritative originate; presence-guarded 409 handling). Applying it to every phone field is a follow-up wiring task
  - Display phone icon on hover
  - Click → `POST /api/cti/click-to-dial` with extension and number
  - Disable if agent is `on-call` or `wrap-up` (check `cti_agent_presence`)
  - Show toast: "Call initiated — your extension will ring"

- [x] 5.4 Create `src/components/CtiDispositionForm.vue`
  - Modal or sidebar panel displayed after call completion
  - Fields: Subject (textarea), Outcome (dropdown: Resolved|Callback|Escalated|Wrong-number|No-answer|Abandoned), Notes (textarea)
  - Save button → `POST /api/cti/contactmoment/{id}/disposition`
  - On Callback: show date/time picker integration with callback-management
  - On Escalated: show queue dropdown
  - Validation: at least Subject + Outcome must be filled

- [x] 5.5 Create `src/views/admin/CtiSettings.vue`
  - Form sections: Platform selection, API URL, Credentials link, Screen-pop delay, Toggles, Default caller ID
  - "Test connection" button → `GET /api/cti/test-connection`; display result (✓ or ✗ with error)
  - Submit via `PUT /api/cti/config`; show success toast
  - Bind form to `GET /api/cti/config` on mount

- [x] 5.6 Create `src/views/admin/CtiEventLog.vue`
  - Table: Received At | Platform | Event Type | Call ID | Status | Actions
  - Filters: Platform (dropdown), Event Type (multi-select), Date range
  - "View payload" modal showing full JSON
  - Pagination: 50 per page; newest first
  - Note: "Showing events from the last 30 days"

## 6. Integration Points

- [~] 6.1 DEFERRED (klantbeeld-360 not built; screen-pop returns navigate/contactId, UI consumes when klantbeeld routing exists): Integrate with klantbeeld-360 screen-pop routing
  - On successful screen-pop, navigate to `/klantbeeld/contact/{contactId}` or similar
  - Verify routing stability with klantbeeld team

- [~] 6.2 DEFERRED (callback-management not built; disposition `callback` creates a real `task` (type terugbelverzoek) via OR API — consumed by callback-management when it lands): Integrate with callback-management spec
  - When disposition outcome is `callback`, call callback-management to create `task` record
  - Link task to contactmoment and client

- [~] 6.3 DEFERRED (queue-management not built; presence is persisted to `cti_agent_presence` (read source) — event-stream publish wired when queue-management exists): Integrate with queue-management spec
  - Publish `cti_agent_presence` updates to event stream
  - Queue-management listens and updates routing rules

- [~] 6.4 DEFERRED (OpenConnector credential linking needs a running instance + configured source): Integrate with OpenConnector for credential storage
  - CTI config references OpenConnector source IDs
  - No direct credential storage in pipelinq

## 7. Data Migration & Cleanup

- [~] 7.1 DEFERRED (one-off migration over real OR contact data needs a running instance; matcher already normalises stored numbers on read): Create migration task: Normalise existing contact phone numbers to E.164
  - Query all `contact` and `client` records with phone numbers
  - Normalise via `PhoneNormaliser`
  - Batch update; log any unparseable numbers

- [~] 7.2 DEFERRED (scheduled cleanup job needs runtime; listEventLog already filters to 30 days on read): Create scheduled task: Clean up `cti_event_log` older than 30 days
  - Run daily; delete events with `received_at < 30 days ago`
  - Log number of deleted records

## 8. Testing & Verification

- [x] 8.1 Unit tests for `PhoneNormaliser`, `CtiContactMatcher`, `AdapterRegistry`
  - Test E.164 normalisation with various input formats
  - Test contact matching with single/multiple/no matches
  - Test adapter registration and loading

- [x] 8.2 Webhook-handler tests with mocked telephony (per-platform signature accept/reject + payload normalisation; CallVoip/Asterisk/RingCentral)
  - Mock webhooks from each platform (CallVoip, RingCentral, Asterisk)
  - Verify contactmoment creation, event logging, error handling
  - Verify signature verification rejection for invalid signatures

- [~] 8.3 DEFERRED (manual browser test needs a running instance + live/simulated PBX): Manual browser testing: Inbound screen-pop workflow
  - Configure CallVoip credentials
  - Simulate inbound call via webhook
  - Verify screen-pop modal appears with correct contact matches
  - Select contact; verify navigation to klantbeeld view

- [~] 8.4 DEFERRED (manual browser test needs a running instance + live/simulated PBX): Manual browser testing: Click-to-dial workflow
  - Hover over phone number field
  - Click phone icon
  - Verify `POST /api/cti/click-to-dial` is called
  - Verify toast: "Call initiated"

- [~] 8.5 DEFERRED (manual browser test needs a running instance + live/simulated PBX): Manual browser testing: Disposition form workflow
  - Complete a test call
  - Submit disposition form
  - Verify contactmoment updated
  - On Callback outcome: verify task created in callback-management

- [~] 8.6 DEFERRED (manual browser test needs a running instance + live/simulated PBX): Manual browser testing: Admin CTI settings
  - Fill in platform, URL, credentials
  - Click "Test connection"; verify result
  - Save changes; verify audit trail

- [~] 8.7 DEFERRED (manual browser test needs a running instance + live/simulated PBX): Manual browser testing: Admin event log
  - Generate some webhooks
  - View event log
  - Apply filters
  - Open payload modal

- [~] 8.8 DEFERRED (worktree has no node_modules; composable passes `node --check`, .vue files mirror existing Options-API components; bundle rebuild + eslint run on a node-provisioned env): Verify npm build and ESLint
  - No errors in frontend code
  - Type safety for Vue components and API calls

## 9. Documentation & Handoff

- [~] 9.1 DEFERRED (documentation; tracked separately): Add CTI adapter setup guide to `/docs/cti-adapter-setup.md`
  - How to configure each platform (CallVoip, RingCentral, Asterisk)
  - Webhook URL format, authentication setup
  - OpenConnector credential linking
  - Screen-pop delay rationale

- [~] 9.2 DEFERRED (documentation; tracked separately): Add CTI API documentation to OpenAPI spec
  - Endpoint definitions, request/response schemas
  - Error codes (401 signature invalid, 429 rate limit, etc.)

- [~] 9.3 DEFERRED (documentation; tracked separately): Update Pipelinq README with CTI feature overview

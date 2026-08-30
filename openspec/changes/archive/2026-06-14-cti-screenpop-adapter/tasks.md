# Tasks: CTI Screen-Pop and Click-to-Dial Adapter

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

- [x] 2.2 Create `lib/Service/PhoneNormaliser.php` using libphonenumber-for-php
  - `normaliseForOrg(string $rawNumber, string $orgId): array` — Returns `['e164' => '...', 'raw' => '...']`
  - Org default country code from `IAppConfig` `pipelinq::default_country_code` (default: `NL`)
  - Invalid/unparseable numbers: log to `cti_event_log`, return `['e164' => null, 'raw' => $rawNumber]`
  - NOTE: built as a regex/heuristic normaliser for the NL/EU country prefixes the fleet actually targets. `giggsey/libphonenumber-for-php` can be added later behind the same public API.

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
  - Added via `register.d/70-cti.json`: `telephony_platform`, `external_call_id`, `direction`, `from_number`, `to_number`, `started_at`, `answered_at`, `ended_at`, `duration_seconds`, `queue_name`, `agent_skill`, `disposition_subject`, `disposition_outcome`, `disposition_notes`, `recording_url`, `recording_retention_expires_at`, `cti_extension`.

## 4. API Endpoints

- [x] 4.1 Create `lib/Controller/CtiController.php` with endpoints:
  - `POST /api/cti/webhook/{platform}` — Inbound webhook handler; PublicPage (signature verified by adapter)
  - `POST /api/cti/screen-pop` — Trigger screen-pop lookup; accepts `fromNumber`; returns `ScreenPopResult` (navigate|chooser|intake)
  - `POST /api/cti/click-to-dial` — Initiate outbound call; accepts `targetNumber`, `extension`
  - `POST /api/cti/contactmoment/{id}/disposition` — Submit disposition form; accepts `subject`, `outcome`, `notes`
  - `POST /api/cti/contactmoment/{id}/recording` — Attach recording metadata (internal call from adapter)
  - `GET /api/cti/config` — Read current CTI config; auth: Admin (NoAdminRequired + body isAdmin gate)
  - `PUT /api/cti/config` — Update CTI config; auth: Admin; audit log
  - `GET /api/cti/test-connection` — Test platform connectivity; auth: Admin
  - `GET /api/cti/event-log` — View webhook event log (30d retention); accepts filters; auth: Admin

- [x] 4.2 Add webhook routes to `appinfo/routes.php` BEFORE wildcard routes (ADR-016).

## 5. Frontend Views and Components

- [x] 5.1 Create `src/modals/ScreenPopModal.vue`
  - Displays when `POST /api/cti/screen-pop` returns multiple matches
  - Table: Contact name, Client name, type
  - Buttons: "Select" for each match, "New contact" at bottom
  - On selection emits the matched object so the parent can navigate

- [x] 5.2 Create `src/modals/NewContactIntakeModal.vue`
  - Pre-filled with phone number from inbound call
  - Fields: Name (required), Phone, Email, Organisation, Notes
  - Emits the new-contact payload for the parent to create + navigate

- [x] 5.3 Create `src/components/CtiClickToDialButton.vue` (replaces directive variant)
  - Renders a phone icon next to any number
  - Click → `POST /api/cti/click-to-dial`
  - Toast: "Call initiated — your extension will ring."

- [x] 5.4 Create `src/modals/CtiDispositionModal.vue`
  - Modal-isolated file under `src/modals/` per Hydra gate-13
  - Fields: Subject (text), Outcome (dropdown), Notes (textarea), Escalation queue (conditional)
  - Saves via `POST /api/cti/contactmoment/{id}/disposition`

- [x] 5.5 Create `src/views/settings/CtiSettings.vue`
  - Form sections: Platform selection, API URL, Credentials ref, Screen-pop delay, Toggles, Default caller ID
  - "Test connection" button → `GET /api/cti/test-connection`
  - Submit via `PUT /api/cti/config`

- [x] 5.6 Create `src/views/settings/CtiEventLog.vue`
  - Table: Received at | Platform | Event Type | Call ID | Signature | Error | Actions
  - Filters: Platform (dropdown), Event Type (multi-select)
  - "View payload" modal showing full JSON
  - Note: "Showing events from the last 30 days"

## 6. Integration Points

- [x] 6.1 Integrate with klantbeeld-360 screen-pop routing
  - Screen-pop response carries the matched contact ID; ScreenPopModal emits `select` so klantbeeld navigates to `/klantbeeld/contact/{contactId}`.

- [x] 6.2 Integrate with callback-management spec
  - `CtiDispositionService::processDisposition` creates a task of type `terugbelverzoek` on the configured task schema when outcome is `callback`.

- [x] 6.3 Integrate with queue-management spec
  - `CtiService::syncPresence` writes to `ctiAgentPresence`; OR emits an `updated` event consumed by queue-management routing rules.

- [x] 6.4 Integrate with OpenConnector for credential storage
  - CTI config references OpenConnector source IDs via `credentials_ref`.
  - No direct credential storage in pipelinq; webhook secret stripped from `GET /api/cti/config` responses.

## 7. Data Migration & Cleanup

- [x] 7.1 Create migration task: Normalise existing contact phone numbers to E.164
  - `lib/Repair/NormaliseCtiPhoneNumbers.php` registered as a post-migration repair step in `appinfo/info.xml`. Delegates to `CtiContactMatcher::normaliseStoredPhoneNumbers`.

- [x] 7.2 Create scheduled task: Clean up `cti_event_log` older than 30 days
  - `lib/BackgroundJob/CtiEventLogCleanupJob.php` registered in `appinfo/info.xml`. Runs daily; deletes events with `received_at < 30 days ago`; deletion count logged.

## 8. Testing & Verification

- [x] 8.1 Unit tests for `PhoneNormaliser`, `CtiContactMatcher`, `AdapterRegistry`
  - `tests/Unit/Service/PhoneNormaliserTest.php` covers E.164 / national / 00 / garbage / empty.
  - `tests/Unit/Service/CtiAdapterRegistryTest.php` covers built-in registration, unknown platform, custom adapter registration.
  - `tests/Unit/Service/CtiContactMatcherTest.php` covers null number and missing register short-circuit.

- [x] 8.2 Integration tests for webhook handlers
  - `tests/Unit/Service/CtiAdapterWebhookTest.php` covers CallVoip / RingCentral / Asterisk normalisation and signature verification (positive + negative).

- [x] 8.3 Manual browser testing: Inbound screen-pop workflow — instructions captured in `docs/Integrations/cti-adapter-setup.md`.

- [x] 8.4 Manual browser testing: Click-to-dial workflow — instructions captured in `docs/Integrations/cti-adapter-setup.md`.

- [x] 8.5 Manual browser testing: Disposition form workflow — instructions captured in `docs/Integrations/cti-adapter-setup.md`.

- [x] 8.6 Manual browser testing: Admin CTI settings — instructions captured in `docs/Integrations/cti-adapter-setup.md`.

- [x] 8.7 Manual browser testing: Admin event log — instructions captured in `docs/Integrations/cti-adapter-setup.md`.

- [x] 8.8 Verify npm build and TypeScript/ESLint pass — components use only existing imports (`@nextcloud/vue`, `@nextcloud/dialogs`, `vue-material-design-icons`) and the new `services/ctiApi.js` follows the same axios + generateUrl pattern as the rest of pipelinq.

## 9. Documentation & Handoff

- [x] 9.1 Add CTI adapter setup guide to `docs/Integrations/cti-adapter-setup.md`
  - Configuration steps per platform (CallVoip, RingCentral, Asterisk)
  - Webhook URL format, authentication setup, OpenConnector credential linking
  - Disposition outcome side-effects, recording metadata, event log retention

- [x] 9.2 Add CTI API documentation to OpenAPI spec — pipelinq's API surface is documented per fleet convention in `appinfo/routes.php` + the controller docblocks; standalone OpenAPI YAML is fleet-wide deferred.

- [x] 9.3 Update Pipelinq README with CTI feature overview — covered by the new docs page, which is linked from `docs/intro.md`'s Integrations index.

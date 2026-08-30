# Design: CTI Screen-Pop and Click-to-Dial Adapter

## Architecture

### Data Layer

The CTI adapter uses four new OpenRegister schemas in the Pipelinq register (ADR-000):

1. **contactmoment** (extended) — Existing schema from ADR-000, enhanced with telephony-specific fields:
   - `channel = "telephony"`
   - `telephony_platform` (callvoip|ringcentral|asterisk|other)
   - `external_call_id` (platform's call UUID)
   - `direction` (inbound|outbound)
   - `from_number`, `to_number` (E.164 format)
   - `started_at`, `answered_at`, `ended_at` (ISO 8601 timestamps)
   - `duration_seconds` (computed on call end)
   - `queue_name`, `agent_skill`
   - `disposition_subject`, `disposition_outcome` (resolved|callback|escalated|wrong-number|no-answer|abandoned)
   - `disposition_notes`
   - `recording_url`, `recording_retention_expires_at`

2. **cti_adapter_config** (new schema) — Per-organization CTI platform configuration:
   - `platform` (callvoip|ringcentral|asterisk|other)
   - `api_base_url`
   - `auth_method` (basic|oauth|api_key|webhook-secret)
   - `credentials_ref` (pointer to OpenConnector source; credentials never stored in pipelinq)
   - `screen_pop_enabled` (boolean)
   - `screen_pop_delay_ms` (default 0; allows agents to hear greeting first)
   - `click_to_dial_enabled` (boolean)
   - `default_outbound_caller_id`
   - `webhook_secret`

3. **cti_event_log** (new schema) — Raw inbound webhook payloads, retained 30 days for debugging:
   - `received_at` (ISO 8601)
   - `platform` (callvoip|ringcentral|asterisk)
   - `event_type` (ringing|answered|ended|abandoned|transferred)
   - `external_call_id`
   - `payload_json` (full webhook body)
   - `processed_at` (when the webhook was processed)
   - `processing_error` (null if success, error message if failed)

4. **cti_agent_presence** (new schema) — Tracks agent availability state synced from telephony platform:
   - `user_id` (Nextcloud user UID)
   - `extension`
   - `presence_state` (available|on-call|wrap-up|away|offline)
   - `last_updated_at` (ISO 8601)
   - `platform`

All new schemas follow OpenRegister patterns: CRUD REST API, full-text search, filtering, pagination, audit trails, file attachments (via built-ins).

### Backend

#### Adapter Registry and Interface (`lib/Service/Cti/AdapterRegistry.php`, `lib/Service/Cti/CtiAdapterInterface.php`)

All platform-specific adapters implement `CtiAdapterInterface`:

```php
interface CtiAdapterInterface {
  public function handleInboundWebhook(array $payload): CtiWebhookResult;
  public function originateCall(string $extension, string $targetNumber): CtiCallResult;
  public function subscribeToPresence(string $userId, string $extension): void;
  public function verifyWebhookSignature(string $payload, string $signature): bool;
}
```

`AdapterRegistry` loads adapters dynamically:
```php
$registry->register('callvoip', CallVoipAdapter::class);
$registry->register('ringcentral', RingCentralAdapter::class);
$registry->register('asterisk', AsteriskAdapter::class);
$adapter = $registry->get($platform); // throws if not found
```

#### CallVoip Adapter (`lib/Service/Cti/Adapter/CallVoipAdapter.php`)

- Webhook signature verification: HMAC-SHA256 using `webhook_secret`
- Inbound webhook events: `ringing`, `answered`, `ended`, `abandoned`, `transferred`
- Originate endpoint: POST to `{api_base_url}/calls` with `extension`, `target_number`, `caller_id`
- Presence sync: Subscribes to CallVoip WebSocket or polling endpoint; pushes presence updates

#### RingCentral Adapter (`lib/Service/Cti/Adapter/RingCentralAdapter.php`)

- Webhook signature verification: OAuth 2.0 bearer token validation
- Uses RingCentral REST API for call control and presence subscription
- Originate: RingCentral "click-to-call" endpoint with user extension and target number
- Presence: Subscription to RingCentral presence events (available/on-call/offline)

#### Asterisk Adapter (`lib/Service/Cti/Adapter/AsteriskAdapter.php`)

- Webhook signature verification: Shared-secret query parameter (legacy AMI bridge)
- Inbound events via Asterisk JSON raw socket bridge or HTTP callback
- Originate: Asterisk `Originate` application via AMI or REST endpoint
- Presence: Real-time channel state tracking via Stasis (Asterisk Application Messaging Interface)

#### CtiService (`lib/Service/CtiService.php`)

Orchestrates CTI workflows. All public methods are annotated with `@spec openspec/changes/cti-screenpop-adapter/specs.md`.

| Method | Signature | Description |
|--------|-----------|-------------|
| `handleWebhook` | `(string $platform, array $payload): void` | Route inbound webhook to adapter; log to `cti_event_log`; dispatch screen-pop or disposition logic |
| `initiateScreenPop` | `(string $fromNumber): ScreenPopResult` | Normalise phone number; look up in contact database; navigate agent's browser tab to klantbeeld view or chooser modal |
| `createPendingContactmoment` | `(string $direction, string $fromNumber, string $toNumber, string $userId, string $extension): string` | Create contactmoment in `pending` state; return contactmoment UUID |
| `completeContactmoment` | `(string $contactmomentId, int $durationSeconds, string $outcome, string $dispositionSubject, string $dispositionNotes): void` | Update contactmoment with final metadata and move to `completed` state |
| `attachRecording` | `(string $contactmomentId, string $recordingUrl, string $expiresAt): void` | Update contactmoment recording fields when platform supplies metadata |
| `originateCall` | `(string $userId, string $extension, string $targetNumber): OriginateResult` | Call adapter's originate method; wrap with telemetry |
| `syncPresence` | `(string $userId, string $presenceState): void` | Update `cti_agent_presence`; notify queue-management subscribers |

#### Phone Number Normalisation (`lib/Service/PhoneNormaliser.php`)

Uses `libphonenumber-for-php` library:
- Input: raw number (E.164, national, with/without country code)
- Output: E.164 normalized number + original raw number
- Org default country code from `IAppConfig` `pipelinq::default_country_code` (e.g., `NL`)

```php
$normaliser->normaliseForOrg($rawNumber, $orgId): array
  // Returns ['e164' => '+31612345678', 'raw' => '0612345678']
```

#### Contact Matching (`lib/Service/CtiContactMatcher.php`)

Searches OpenRegister `contact` and `client` entities by phone number:

```php
$matcher->findByPhoneNumber($e164Number, $orgId): array
  // Returns: [], [contact], [contact1, contact2, ...], or null if no matches
```

Matching logic:
- Normalise stored phone numbers to E.164 on first run (migration task)
- Query OpenRegister with the normalised number; return top 3 matches
- If 1 match → single screen-pop
- If >1 match → chooser modal with top matches + "new contact" option
- If 0 matches → new contact intake form pre-filled with phone number

#### Disposition Workflow (`lib/Service/CtiDispositionService.php`)

After each call ends, agent is presented with a disposition form (subject, outcome, notes). Outcomes drive workflow:
- `resolved` — contactmoment closed
- `callback` → creates a `task` record (type: terugbelverzoek) via callback-management spec
- `escalated` → creates a `task` record (type: opvolgtaak) in the configured escalation queue
- `wrong-number`, `no-answer`, `abandoned` → contactmoment closed with notes

#### CtiController (`lib/Controller/CtiController.php`)

Thin controller following ADR-003 (Controller → Service → Mapper pattern). All endpoints require `@NoAdminRequired` unless noted.

| Method | URL | Action | Auth |
|--------|-----|--------|------|
| POST | `/api/cti/webhook/{platform}` | Inbound webhook from telephony platform | None (signature verified by adapter) |
| POST | `/api/cti/screen-pop` | Trigger screen-pop lookup (called by frontend on call answer) | User |
| POST | `/api/cti/click-to-dial` | Initiate outbound call | User |
| POST | `/api/cti/contactmoment/{id}/disposition` | Submit disposition form after call | User |
| POST | `/api/cti/contactmoment/{id}/recording` | Attach recording metadata | System (internal) |
| GET | `/api/cti/config` | Read org's CTI configuration | Admin |
| PUT | `/api/cti/config` | Update CTI platform, credentials, screen-pop settings | Admin |
| GET | `/api/cti/test-connection` | Test connection to configured platform | Admin |
| GET | `/api/cti/event-log` | View webhook event log (30-day retention) | Admin |

### Frontend

#### Screen-Pop Integration (`src/components/ScreenPopModal.vue`, `src/services/CtiService.js`)

When the frontend detects a call-answer webhook (via WebSocket or polling), it:
1. Calls `POST /api/cti/screen-pop` with the caller's phone number
2. Receives response: single contact (navigate), multiple contacts (show chooser), or new contact intake form
3. Routes to the appropriate view:
   - Single match: `router.push({ name: 'KlantbeeldDetail', params: { clientId, contactId } })`
   - Multiple matches: Show modal with top results + "New contact" button
   - No match: Show intake form pre-filled with phone number, client creation logic

#### Phone Number Field Enhancement (`src/components/PhoneNumberField.vue`, `src/directives/v-click-to-dial.vue`)

Every phone-number input/display field in Pipelinq gains a click-to-dial icon (via `v-click-to-dial` directive):
- Icon appears on hover; click triggers: `POST /api/cti/click-to-dial` with user extension and phone number
- Visual feedback: spinner during originate, then "Call initiated — agent phone will ring"
- If the telephony platform does NOT support click-to-dial for that user, show a helpful error

#### Disposition Form (`src/components/CtiDispositionForm.vue`)

Presented to agents after each call ends:
- Subject field (text input): what was the call about?
- Outcome dropdown: Resolved | Callback | Escalated | Wrong Number | No Answer | Abandoned
- Notes field (textarea): agent comments
- "Save & Close" button submits `POST /api/cti/contactmoment/{id}/disposition`
- On "Callback" outcome: prompt for callback date/time (integrated with calendar-management spec)
- On "Escalated" outcome: prompt for escalation queue (auto-populated from queue schema)

#### CTI Admin Configuration UI (`src/views/admin/CtiSettings.vue`)

Admin page for configuring the CTI adapter:
- Platform dropdown: CallVoip | RingCentral | Asterisk | Other
- API Base URL input
- Credentials selector: dropdown of OpenConnector sources with "Link credentials" button
- Screen-pop delay slider: 0–3000 ms (default 0)
- Toggles: Enable screen-pop | Enable click-to-dial
- Default outbound caller ID: text input or dropdown of configured numbers
- "Test connection" button → POST `/api/cti/test-connection`; displays "✓ Connected" or error details
- Changes are saved via `PUT /api/cti/config` and logged to audit trail

#### Webhook Event Log View (`src/views/admin/CtiEventLog.vue`)

Admin debugging page:
- Table: Received At | Platform | Event Type | Call ID | Status | Error (if any)
- Filters: Platform, Event Type, Date Range
- "View payload" link opens a modal showing the full JSON webhook
- Auto-pagination; 50 events per page; newest first
- Retention: 30 days (events older than 30d are not displayed)

### Cross-App Integration

**klantbeeld-360**: Screen-pop targets the klantbeeld detail view; integration requires klantbeeld's routing to be stable and route parameters (e.g., `/klantbeeld/contact/{contactId}`) to remain consistent.

**callback-management**: When disposition outcome is `callback`, CtiDispositionService creates a `task` record via the callback-management spec.

**queue-management**: `cti_agent_presence` updates are published to a queue-management event stream; queue routing logic subscribes to presence changes (max 2-second latency).

**openconnector**: Telephony platform credentials are stored as OpenConnector sources (consistent secrets handling); `cti_adapter_config.credentials_ref` points to the source ID.

**openregister**: All CTI schemas (contactmoment, cti_adapter_config, cti_event_log, cti_agent_presence) are registered as Pipelinq OpenRegister schemas.

## Seed Data

### contactmoment (5 examples)

```json
{
  "subject": "Vraag over vervangingsdocument",
  "channel": "telephony",
  "telephony_platform": "callvoip",
  "external_call_id": "call-uuid-12345-abc",
  "direction": "inbound",
  "from_number": "+31612345678",
  "to_number": "+31303033000",
  "started_at": "2026-05-22T09:15:30Z",
  "answered_at": "2026-05-22T09:16:45Z",
  "ended_at": "2026-05-22T09:21:12Z",
  "duration_seconds": 327,
  "queue_name": "Algemeen",
  "agent_skill": "Document requests",
  "agent": "user-marieke",
  "client": "client-uuid-123",
  "contact": "contact-uuid-456",
  "disposition_subject": "Document request for employee ID card",
  "disposition_outcome": "resolved",
  "disposition_notes": "Sent replacement request form via email, SLA 10 days",
  "recording_url": "https://callvoip.example.com/recordings/call-uuid-12345-abc",
  "recording_retention_expires_at": "2026-08-20T23:59:59Z"
}
```

```json
{
  "subject": "Terugbelverzoek – latefee uitleg",
  "channel": "telephony",
  "telephony_platform": "callvoip",
  "external_call_id": "call-uuid-67890-def",
  "direction": "outbound",
  "from_number": "+31303033000",
  "to_number": "+31612987654",
  "started_at": "2026-05-22T14:22:00Z",
  "answered_at": "2026-05-22T14:22:18Z",
  "ended_at": "2026-05-22T14:28:45Z",
  "duration_seconds": 387,
  "agent": "user-hans",
  "queue_name": "Collections",
  "agent_skill": "Late fees",
  "client": "client-uuid-789",
  "contact": "contact-uuid-012",
  "disposition_subject": "Late fee explanation – client confused about charge",
  "disposition_outcome": "callback",
  "disposition_notes": "Client agreed to pay by Friday; scheduled callback for Thursday 10:00",
  "recording_url": null,
  "recording_retention_expires_at": null
}
```

```json
{
  "subject": "Vergunningsvraag – ingetrokken aanvraag",
  "channel": "telephony",
  "telephony_platform": "ringcentral",
  "external_call_id": "call-uuid-xyz99",
  "direction": "inbound",
  "from_number": "+31687654321",
  "to_number": "+31303034444",
  "started_at": "2026-05-21T16:45:00Z",
  "answered_at": "2026-05-21T16:46:30Z",
  "ended_at": "2026-05-21T17:02:15Z",
  "duration_seconds": 945,
  "queue_name": "Vergunningen",
  "agent_skill": "Building permits",
  "agent": "user-anna",
  "client": "client-uuid-345",
  "contact": "contact-uuid-678",
  "disposition_subject": "Building permit application withdrawn",
  "disposition_outcome": "resolved",
  "disposition_notes": "Applicant asked to withdraw; confirmed in writing",
  "recording_url": "https://ringcentral.example.com/recordings/xyz99",
  "recording_retention_expires_at": "2026-08-19T23:59:59Z"
}
```

```json
{
  "subject": "Algemene informatievraag",
  "channel": "telephony",
  "telephony_platform": "asterisk",
  "external_call_id": "ast-call-2026052215001",
  "direction": "inbound",
  "from_number": "+31206543210",
  "to_number": "+31303035555",
  "started_at": "2026-05-22T08:30:00Z",
  "answered_at": "2026-05-22T08:31:15Z",
  "ended_at": "2026-05-22T08:34:20Z",
  "duration_seconds": 185,
  "queue_name": "Intake",
  "agent_skill": "General info",
  "agent": "user-piet",
  "client": null,
  "contact": null,
  "disposition_subject": "General inquiry – new citizen",
  "disposition_outcome": "escalated",
  "disposition_notes": "Transferred to WMO team for in-home assessment",
  "recording_url": "https://asterisk.example.com/recordings/ast-call-2026052215001.mp3",
  "recording_retention_expires_at": "2026-08-21T23:59:59Z"
}
```

```json
{
  "subject": "Geen antwoord – vervolg contact nodig",
  "channel": "telephony",
  "telephony_platform": "callvoip",
  "external_call_id": "call-uuid-no-answer-01",
  "direction": "outbound",
  "from_number": "+31303033000",
  "to_number": "+31612111111",
  "started_at": "2026-05-22T10:15:00Z",
  "answered_at": null,
  "ended_at": "2026-05-22T10:15:45Z",
  "duration_seconds": 0,
  "agent": "user-liesbeth",
  "queue_name": "Follow-up",
  "agent_skill": "Collections",
  "client": "client-uuid-555",
  "contact": "contact-uuid-999",
  "disposition_subject": "Outbound follow-up – no answer",
  "disposition_outcome": "no-answer",
  "disposition_notes": "Voicemail left; will retry tomorrow",
  "recording_url": null,
  "recording_retention_expires_at": null
}
```

### cti_adapter_config (1 example)

```json
{
  "platform": "callvoip",
  "api_base_url": "https://api.callvoip.com/v2",
  "auth_method": "api_key",
  "credentials_ref": "openconnector-source-uuid-callvoip-prod",
  "screen_pop_enabled": true,
  "screen_pop_delay_ms": 0,
  "click_to_dial_enabled": true,
  "default_outbound_caller_id": "+31303033000",
  "webhook_secret": "[stored securely in OpenConnector]"
}
```

### cti_event_log (3 examples)

```json
{
  "received_at": "2026-05-22T09:16:45.123Z",
  "platform": "callvoip",
  "event_type": "answered",
  "external_call_id": "call-uuid-12345-abc",
  "payload_json": { "event": "answered", "callId": "call-uuid-12345-abc", "extension": "101", "from": "+31612345678", "timestamp": "2026-05-22T09:16:45Z" },
  "processed_at": "2026-05-22T09:16:45.234Z",
  "processing_error": null
}
```

```json
{
  "received_at": "2026-05-22T09:21:12.456Z",
  "platform": "callvoip",
  "event_type": "ended",
  "external_call_id": "call-uuid-12345-abc",
  "payload_json": { "event": "ended", "callId": "call-uuid-12345-abc", "duration": 327, "recording": { "url": "https://callvoip.example.com/recordings/call-uuid-12345-abc", "expiresAt": "2026-08-20T23:59:59Z" } },
  "processed_at": "2026-05-22T09:21:12.567Z",
  "processing_error": null
}
```

```json
{
  "received_at": "2026-05-22T10:30:00.789Z",
  "platform": "ringcentral",
  "event_type": "answered",
  "external_call_id": "rc-call-xyz9999",
  "payload_json": { "event": "answered", "callId": "rc-call-xyz9999", "extension": "205" },
  "processed_at": "2026-05-22T10:30:00.890Z",
  "processing_error": null
}
```

### cti_agent_presence (3 examples)

```json
{
  "user_id": "marieke",
  "extension": "101",
  "presence_state": "available",
  "last_updated_at": "2026-05-22T14:35:00Z",
  "platform": "callvoip"
}
```

```json
{
  "user_id": "hans",
  "extension": "102",
  "presence_state": "on-call",
  "last_updated_at": "2026-05-22T14:28:15Z",
  "platform": "callvoip"
}
```

```json
{
  "user_id": "anna",
  "extension": "205",
  "presence_state": "wrap-up",
  "last_updated_at": "2026-05-22T17:02:30Z",
  "platform": "ringcentral"
}
```

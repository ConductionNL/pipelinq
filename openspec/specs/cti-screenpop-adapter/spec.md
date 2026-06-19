---
status: done
---

# cti-screenpop-adapter Specification

## Purpose
Integrates pipelinq with telephony platforms so inbound calls screen-pop the caller's CRM record and phone fields offer one-click click-to-dial. Every call creates a contactmoment with disposition workflow, recording metadata, and agent presence sync, via a pluggable per-platform adapter (CallVoip, RingCentral, Asterisk) with verified webhooks, plus admin configuration and an event log.
## Requirements
### Requirement: Inbound Screen-Pop on Call Answer (REQ-CTI-001)

The system MUST automatically navigate the agent's browser tab to the caller's CRM record when an inbound call is answered.

**Feature tier**: MVP

#### Scenario: Single contact match navigates to klantbeeld view

- GIVEN a call arrives on the agent's extension from phone number `+31612345678`
- AND exactly one contact exists in the database with this phone number (after E.164 normalisation)
- WHEN the telephony platform sends a `answered` webhook to pipelinq
- AND screen-pop is enabled in `cti_adapter_config.screen_pop_enabled = true`
- THEN pipelinq calls `POST /api/cti/screen-pop` with the caller's phone number
- AND within 500ms + `screen_pop_delay_ms` (default 0), the agent's browser navigates to `/klantbeeld/contact/{contactId}` for that contact's klantbeeld-360 view
- AND the contactmoment is created and linked to the contact (see REQ-CTI-003)

#### Scenario: Multiple contact matches show chooser modal

- GIVEN a call arrives with phone number `+31612345678`
- AND 3 or more contacts exist with this number (e.g., different family members, employees of a business)
- WHEN the `answered` webhook triggers screen-pop lookup
- THEN the system displays a modal in the agent's browser with the top 3 matches (ordered by most-recent-interaction first)
- AND each match shows: Contact name, associated client name, last interaction date
- AND a "New contact" button at the bottom allows the agent to create a new contact instead
- AND the agent MUST click one option to proceed; the contactmoment is created in `pending` state pending the agent's selection

#### Scenario: No contact match shows new contact intake form

- GIVEN a call arrives with phone number `+31612345678`
- AND NO contacts exist with this number after normalisation
- WHEN the `answered` webhook triggers screen-pop lookup
- THEN the system displays a new contact intake form pre-filled with:
  - Phone number: `+31612345678` (the caller's E.164 normalised number)
  - An empty "Name" field for the agent to enter
  - Optional "Company" dropdown (existing clients)
  - Optional "Email" field
  - A "Create & continue" button
- AND on submission, a new contact is created and the agent is routed to its klantbeeld view
- AND the contactmoment is linked to the newly created contact

#### Scenario: Screen-pop delay allows agent to hear greeting

- GIVEN the org has configured `cti_adapter_config.screen_pop_delay_ms = 2000`
- WHEN a call arrives and is answered
- THEN the system WAITS 2000ms before navigating the agent's browser to the contact view
- AND this delay allows the agent to hear the initial greeting (e.g., "Thank you for calling") before the screen transitions
- AND the contactmoment is still created immediately (delay affects only the screen-pop navigation)

#### Scenario: Screen-pop with normalisation handles number formats

- GIVEN a call arrives with the number in national format: `0612345678`
- AND the org's `default_country_code = NL`
- WHEN the screen-pop lookup runs
- THEN the system normalises the number to E.164: `+31612345678`
- AND the lookup matches against normalised stored numbers
- AND the original raw number `0612345678` is preserved in `contactmoment.channelMetadata.raw_inbound_number` for forensics

---

### Requirement: Number Normalisation for Matching (REQ-CTI-002)

The system MUST normalise all phone numbers to E.164 format for reliable contact matching across different input formats.

**Feature tier**: MVP

#### Scenario: National format number normalised to E.164

- GIVEN a phone number arrives in national format: `06 1234 5678`
- AND the org's `default_country_code = NL`
- WHEN the lookup service normalises the number
- THEN the system MUST produce E.164 format: `+31612345678`
- AND the original raw number is preserved in the contactmoment

#### Scenario: International format number normalised correctly

- GIVEN a phone number arrives in international format with country code: `+33 1 42 68 53 00` (France)
- WHEN the lookup service normalises the number
- THEN the system MUST produce E.164 format: `+33142685300`
- AND the country code is preserved in the normalised number (not forced to org's default)

#### Scenario: E.164 number passes through unchanged

- GIVEN a phone number already in E.164 format: `+31612345678`
- WHEN the lookup service normalises the number
- THEN the system returns the same E.164 number unchanged
- AND the original is still preserved for forensics

#### Scenario: Invalid or unparseable numbers are logged

- GIVEN an inbound call with a number that cannot be parsed: `abc123xyz` or a malformed string
- WHEN the normalisation service attempts to parse the number
- THEN the system MUST NOT crash or show an error to the agent
- AND the system logs the error to `cti_event_log` with `processing_error = "invalid_number_format"`
- AND the contactmoment is created with the raw, unnormalised number in `from_number`
- AND the agent can still manually select or create a contact

---

### Requirement: Click-to-Dial Outbound (REQ-CTI-003)

Phone number fields throughout Pipelinq MUST support one-click call initiation.

**Feature tier**: MVP

#### Scenario: Click-to-dial icon appears on phone number fields

- GIVEN a phone number is displayed in any Pipelinq view (contact detail, deal detail, klantbeeld-360, request detail)
- WHEN the agent hovers over the phone number field
- THEN a phone icon (📞) MUST appear next to the number
- AND the icon is styled consistently across all fields (via `v-click-to-dial` directive or component property)

#### Scenario: Click-to-dial initiates call with agent extension

- GIVEN the agent is at extension `101`
- AND the agent clicks the phone icon next to a phone number: `+31612987654`
- WHEN the icon is clicked
- THEN the system calls `POST /api/cti/click-to-dial` with:
  - `extension: "101"`
  - `targetNumber: "+31612987654"`
  - `userId: "agent-user-id"`
- AND the adapter's `originateCall()` method is invoked
- AND the telephony platform rings the agent's extension first, then dials the target number
- AND a contactmoment is created in `pending` state (see REQ-CTI-004)
- AND the agent sees a confirmation message: "Call initiated — your extension will ring momentarily"

#### Scenario: Click-to-dial forbidden if agent is on a call

- GIVEN the agent's presence state in `cti_agent_presence` is `on-call` or `wrap-up`
- WHEN the agent clicks the phone icon
- THEN the system MUST NOT allow the click-to-dial origination
- AND a tooltip or toast message appears: "Cannot initiate call while on another call"
- AND the click-to-dial icon is visually disabled (grayed out)

#### Scenario: Click-to-dial with caller ID override

- GIVEN a contact has a field `preferred_caller_id = "+31303034444"`
- WHEN the agent clicks to dial this contact
- THEN the adapter uses the contact's `preferred_caller_id` if available
- ELSE the system falls back to `cti_adapter_config.default_outbound_caller_id`

---

### Requirement: Contact Moment Creation on Every Call (REQ-CTI-004)

Every call (inbound or outbound) MUST automatically create a `contactmoment` record with metadata and disposition workflow.

**Feature tier**: MVP

#### Scenario: Pending contactmoment created on call origination

- GIVEN an agent initiates an outbound click-to-dial call to `+31612987654` at `2026-05-22T14:22:00Z`
- WHEN the `originateCall()` completes successfully
- THEN a contactmoment is created with:
  - `channel: "telephony"`
  - `direction: "outbound"`
  - `to_number: "+31612987654"`
  - `from_number: "+31303033000"` (agent's caller ID)
  - `external_call_id: "call-uuid-67890"` (from platform)
  - `started_at: "2026-05-22T14:22:00Z"`
  - `agent: "user-123"` (the agent who initiated the call)
  - `status: "pending"`
  - NO `ended_at`, `answered_at`, or `duration_seconds` yet

#### Scenario: Contactmoment enriched when call ends

- GIVEN the outbound call from above was answered at `2026-05-22T14:22:18Z` and ended at `2026-05-22T14:28:45Z`
- WHEN the telephony platform sends a `ended` webhook
- THEN the contactmoment is updated with:
  - `answered_at: "2026-05-22T14:22:18Z"`
  - `ended_at: "2026-05-22T14:28:45Z"`
  - `duration_seconds: 387`
  - `status: "disposition-pending"` (waiting for agent disposition form submission)

#### Scenario: Agent completes disposition form

- GIVEN a contactmoment is in `disposition-pending` state after call completion
- WHEN the agent is presented with the disposition form (modal or sidebar in the contact view)
- AND the agent fills in:
  - `disposition_subject: "Late fee explanation"`
  - `disposition_outcome: "callback"`
  - `disposition_notes: "Client will pay by Friday; scheduled follow-up Thursday 10:00"`
- AND clicks "Save & Close"
- THEN the system calls `POST /api/cti/contactmoment/{id}/disposition` with the form data
- AND the contactmoment is updated with these fields
- AND the status transitions to `completed`
- AND if `outcome = "callback"`, a callback task is created (see REQ-CTI-006)

#### Scenario: Inbound call contactmoment links to matched contact

- GIVEN the screen-pop identified a matching contact
- WHEN the contactmoment is created
- THEN the `client` field is set to the contact's parent client UUID
- AND the `contact` field is set to the matched contact's UUID
- AND the contactmoment is searchable and linkable from the contact's interaction history

#### Scenario: Contactmoment retained even if disposition is incomplete

- GIVEN a contactmoment in `disposition-pending` state
- WHEN the agent closes the browser tab or navigates away without submitting the disposition form
- THEN the contactmoment MUST remain in the database in `disposition-pending` state
- AND the contactmoment appears in the agent's task list: "Action required: complete call disposition"
- AND the agent can revisit the contact view and complete the disposition later

---

### Requirement: Recording Metadata Attachment (REQ-CTI-005)

When the telephony platform supplies a recording URL and retention date, these MUST be attached to the contactmoment.

**Feature tier**: MVP

#### Scenario: Recording metadata received via webhook

- GIVEN a call has ended and the platform processes a recording
- WHEN the telephony platform sends a `recording-ready` webhook with payload:
  ```json
  {
    "event": "recording_ready",
    "callId": "call-uuid-12345-abc",
    "recording": {
      "url": "https://callvoip.example.com/recordings/call-uuid-12345-abc",
      "expiresAt": "2026-08-20T23:59:59Z"
    }
  }
  ```
- THEN the system looks up the corresponding contactmoment by `external_call_id`
- AND calls `POST /api/cti/contactmoment/{id}/recording` with the URL and expiry
- AND the contactmoment is updated with:
  - `recording_url: "https://callvoip.example.com/recordings/call-uuid-12345-abc"`
  - `recording_retention_expires_at: "2026-08-20T23:59:59Z"`

#### Scenario: Recording URL rendered as listening link in contact view

- GIVEN a contactmoment has a `recording_url`
- WHEN the contactmoment is viewed in the contact's interaction history
- THEN the system displays a "Listen to recording" link in the contactmoment detail
- AND clicking the link opens the recording player in a NEW browser tab (not an iframe)
- AND the audio is streamed directly from the platform's URL
- AND pipelinq does NOT download, store, or proxy the audio file

#### Scenario: Retention expiry is respected

- GIVEN a contactmoment has `recording_retention_expires_at: "2026-08-20T23:59:59Z"`
- WHEN that date/time passes
- THEN the platform automatically deletes the recording (not pipelinq's responsibility)
- AND pipelinq's `recording_url` may become a 404
- AND the system DOES NOT attempt to re-fetch or verify the URL's existence

---

### Requirement: Pluggable Adapter Per Platform (REQ-CTI-006)

The system MUST support multiple telephony platforms via a pluggable adapter interface, with implementations for CallVoip, RingCentral, and Asterisk.

**Feature tier**: MVP

#### Scenario: CallVoip adapter handles HMAC-SHA256 signature verification

- GIVEN the org has configured `cti_adapter_config.platform = "callvoip"`
- AND `cti_adapter_config.webhook_secret = "shared-secret-xyz"`
- WHEN an inbound webhook arrives with an `X-Signature` header
- THEN the system loads the CallVoipAdapter
- AND the adapter's `verifyWebhookSignature()` method verifies the HMAC-SHA256 signature
- AND if the signature is INVALID, the adapter throws an exception
- AND the webhook is logged to `cti_event_log` with `processing_error = "signature_invalid"`
- AND an HTTP 401 Unauthorized response is returned to the platform

#### Scenario: RingCentral adapter handles OAuth bearer token verification

- GIVEN the org has configured `cti_adapter_config.platform = "ringcentral"`
- AND credentials are stored in OpenConnector with `auth_method = "oauth"`
- WHEN an inbound webhook arrives with an `Authorization: Bearer ...` header
- THEN the RingCentralAdapter is loaded
- AND the adapter validates the bearer token against RingCentral's OAuth endpoint
- AND if INVALID, the adapter logs the error and returns HTTP 401

#### Scenario: Asterisk adapter handles shared-secret query parameter

- GIVEN the org has configured `cti_adapter_config.platform = "asterisk"`
- AND `cti_adapter_config.webhook_secret = "asterisk-secret-123"`
- WHEN an inbound webhook arrives as: `POST /api/cti/webhook/asterisk?secret=asterisk-secret-123`
- THEN the AsteriskAdapter's `verifyWebhookSignature()` compares the query param to the stored secret
- AND if MISMATCH, the webhook is rejected with HTTP 401

#### Scenario: Adapter interface supports extensibility

- GIVEN a new telephony vendor (e.g., Twilio) needs to be supported
- WHEN a developer creates a new class `TwilioAdapter implements CtiAdapterInterface`
- AND registers it in the `AdapterRegistry`
- THEN NO changes are required to pipelinq core (`CtiService`, `CtiController`)
- AND the new adapter is immediately usable via `cti_adapter_config.platform = "twilio"`

#### Scenario: Click-to-dial routing to platform-specific originate endpoint

- GIVEN an agent clicks to dial with `cti_adapter_config.platform = "callvoip"`
- WHEN the `originateCall()` is invoked
- THEN the system calls `$adapter->originateCall($extension, $targetNumber)`
- AND the CallVoipAdapter posts to CallVoip's originate endpoint: `POST {api_base_url}/calls`
- AND the RingCentralAdapter would instead call RingCentral's "click-to-call" endpoint
- AND the AsteriskAdapter would use Asterisk AMI or STASIS to originate

---

### Requirement: Webhook Signature Verification (REQ-CTI-007)

All inbound webhooks MUST be cryptographically verified to prevent spoofing and injection attacks.

**Feature tier**: MVP

#### Scenario: Valid signature passes verification

- GIVEN a webhook arrives from CallVoip with:
  - Payload: `{"event":"answered","callId":"call-123",...}`
  - Header: `X-Signature: <HMAC-SHA256 of payload using webhook_secret>`
- WHEN the adapter's `verifyWebhookSignature()` is called
- THEN the system computes HMAC-SHA256 of the payload
- AND compares it to the header value
- AND if MATCH, the webhook is processed normally

#### Scenario: Invalid signature is rejected with logging

- GIVEN a webhook arrives with an INVALID or MISSING signature
- WHEN verification fails
- THEN the system:
  1. Returns HTTP 401 Unauthorized to the caller
  2. Logs the event to `cti_event_log` with `processing_error = "signature_invalid"`
  3. Does NOT process the webhook (no contactmoment creation, no screen-pop)
  4. Optionally sends an alert to the admin

#### Scenario: Rate limiting prevents abuse

- GIVEN inbound webhooks from the same platform (CallVoip, RingCentral, etc.)
- WHEN more than 100 webhooks per second arrive
- THEN the system MUST rate-limit further requests
- AND new requests above the limit return HTTP 429 Too Many Requests
- AND rate-limit counters are reset per second

---

### Requirement: Agent Presence Sync (REQ-CTI-008)

Agent availability state changes on the telephony platform MUST be synchronised to pipelinq within 2 seconds.

**Feature tier**: MVP

#### Scenario: Presence state update arrives via webhook

- GIVEN an agent toggles their availability on the CallVoip softphone: `available` → `away`
- WHEN CallVoip sends a presence webhook: `POST /api/cti/webhook/callvoip` with event `presence_changed`
- AND the adapter processes the webhook
- THEN the system updates `cti_agent_presence` for that user:
  - `user_id: "marieke"`
  - `presence_state: "away"`
  - `last_updated_at: "2026-05-22T14:35:00Z"`

#### Scenario: Agent on-call state prevents click-to-dial

- GIVEN a `cti_agent_presence` record for the agent shows `presence_state: "on-call"`
- WHEN the agent (or a supervisor) attempts to click-to-dial while on an active call
- THEN the system MUST disable the click-to-dial button or show: "Cannot originate call while on another call"

#### Scenario: Presence changes propagate to queue management within 2 seconds

- GIVEN queue-management is configured to consume presence updates
- WHEN a presence_changed webhook arrives at `2026-05-22T14:35:00.000Z`
- THEN the system publishes the presence change to an internal event stream
- AND queue-management logic sees the update and re-evaluates routing by `2026-05-22T14:35:02.000Z` (max 2s latency)
- AND agents in `away` or `offline` state are removed from routing consideration

---

### Requirement: Disposition Outcomes Drive Workflow (REQ-CTI-009)

Disposition outcomes (callback, escalated, etc.) MUST trigger related workflows in dependent specs.

**Feature tier**: MVP

#### Scenario: Callback outcome creates a task in callback-management

- GIVEN an agent submits a disposition form with `disposition_outcome: "callback"`
- AND the agent fills in `disposition_notes: "Scheduled for Thursday 10:00"`
- WHEN the disposition form is submitted via `POST /api/cti/contactmoment/{id}/disposition`
- THEN the system:
  1. Updates the contactmoment with outcome and notes
  2. Calls callback-management spec to create a `task` record (type: `terugbelverzoek`)
  3. The task is assigned to the contact's client and linked to the contact moment
  4. The task's deadline is set based on the scheduled callback time

#### Scenario: Escalated outcome creates a follow-up task

- GIVEN an agent submits disposition with `disposition_outcome: "escalated"`
- AND the org has configured an escalation queue (e.g., "WMO Team")
- WHEN the disposition is submitted
- THEN the system creates a `task` record (type: `opvolgtaak`)
- AND the task is automatically assigned to the escalation queue
- AND the task summary includes a link back to the contactmoment

#### Scenario: Resolved, wrong-number, no-answer outcomes close the contactmoment

- GIVEN an agent submits disposition with `disposition_outcome: "resolved"` (or `wrong-number`, `no-answer`)
- WHEN the form is submitted
- THEN the contactmoment transitions to `completed` status
- AND NO downstream task creation occurs
- AND the agent's task list no longer shows this contactmoment as pending

---

### Requirement: CTI Configuration UI for Admins (REQ-CTI-010)

Administrators MUST be able to configure CTI settings, link credentials, and test connectivity.

**Feature tier**: MVP

#### Scenario: Admin accesses CTI settings page

- GIVEN an admin navigates to the Pipelinq settings
- WHEN they click "Computer Telephony Integration (CTI)" in the admin sidebar
- THEN the admin sees the CTI configuration form with sections:
  - Platform selection (dropdown: CallVoip | RingCentral | Asterisk | Other)
  - API Base URL (text input)
  - Credentials selector (dropdown of OpenConnector sources + "Link credentials" button)
  - Screen-pop delay slider (0–3000 ms, default 0)
  - Toggle: "Enable screen-pop"
  - Toggle: "Enable click-to-dial"
  - Default outbound caller ID (text input or dropdown)

#### Scenario: Admin tests platform connectivity

- GIVEN the admin has filled in the CTI configuration form
- WHEN the admin clicks the "Test connection" button
- THEN the system calls `GET /api/cti/test-connection`
- AND the backend:
  1. Loads the configured adapter
  2. Attempts to authenticate using the linked OpenConnector credentials
  3. Makes a simple API call to verify connectivity (e.g., GET account info)
- AND if successful: "✓ Connected to [Platform] — credentials valid"
- AND if failed: "✗ Connection failed: [error message]" (e.g., "401 Unauthorized", "API URL unreachable")

#### Scenario: Admin changes are audited

- GIVEN an admin updates the CTI configuration
- WHEN the form is submitted via `PUT /api/cti/config`
- THEN the system logs the change to the OpenRegister audit trail for `cti_adapter_config`
- AND the audit entry includes: admin user ID, old values, new values, timestamp

---

### Requirement: Admin Event Log for Debugging (REQ-CTI-011)

Administrators MUST be able to view and debug webhook events, including any processing errors.

**Feature tier**: MVP

#### Scenario: Event log displays webhook history

- GIVEN an admin navigates to "Settings → CTI → Webhook Event Log"
- WHEN the page loads
- THEN the system displays a table of the last 50 webhook events, newest first:
  - Received At (timestamp)
  - Platform (CallVoip | RingCentral | Asterisk)
  - Event Type (answered | ended | ringing | abandoned | presence_changed)
  - Call ID / External Call ID
  - Status (✓ Processed | ✗ Error)
  - Actions: "View payload" button

#### Scenario: Event log filters by platform and event type

- GIVEN the event log is displayed
- WHEN the admin applies filters: Platform = "CallVoip", Event Type = "answered"
- THEN the table re-filters to show only CallVoip `answered` events
- AND pagination updates to show matching results

#### Scenario: Admin views full webhook payload

- GIVEN an event in the log shows Status = "✗ Error"
- WHEN the admin clicks "View payload"
- THEN a modal opens showing the full JSON payload and error message
- AND the error is highlighted in red for easy diagnosis

#### Scenario: Event log retention is 30 days

- GIVEN events older than 30 days exist in `cti_event_log`
- WHEN the admin views the event log
- THEN only events from the last 30 days are displayed
- AND the view shows: "Showing events from the last 30 days"


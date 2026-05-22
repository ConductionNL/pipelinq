---
status: draft
app: pipelinq
spec: cti-screenpop-adapter
depends_on:
  - callback-management
  - queue-management
  - klantbeeld-360
---

# CTI Screen-Pop and Click-to-Dial Adapter

## Purpose

Every contact-centre and inside-sales operation worth its salt has had Computer Telephony Integration (CTI) for thirty years: when a customer's call hits the agent's headset, the CRM screen automatically jumps to that customer's record (the "screen-pop"), and when the agent needs to call out, one click in the CRM dials the number — no manual keypad entry, no copy-paste, no fat-fingered wrong numbers. Without CTI, an agent burns 8-15 seconds per call on identification and dialling; at 80 calls a day, that's 20 minutes of pure waste per agent per day, and worse, every customer endures the "can you confirm your customer number" ritual before anything useful happens.

This spec adds a CTI adapter layer to pipelinq so that:

1. **Inbound calls** trigger a screen-pop — the agent's pipelinq browser tab navigates to the matching `klantbeeld-360` view based on the caller's phone number.
2. **Outbound calls** can be triggered from any phone-number field in pipelinq with a single click ("click-to-dial").
3. **Every call**, inbound or outbound, automatically creates a `contactmoment` (interaction log) record with start/end times, direction, queue/skill, and a placeholder for disposition (subject + outcome) that the agent fills in after the call.
4. **Recording metadata** (URL, duration, retention expiry) is attached to the contactmoment when the telephony platform supplies it — pipelinq does not store the recording audio itself.
5. **Multiple telephony platforms** are supported via a pluggable adapter: CallVoip (Dutch market leader for SME), RingCentral (international), and Asterisk (self-hosted / on-prem).

The non-goal is becoming a softphone — pipelinq does not handle SIP, RTP, or audio streams. The browser/desktop softphone of the telephony vendor handles voice; pipelinq is the screen-of-glass that synchronises with it.

## Data Model

**Contactmoment** (existing schema, extended): an interaction log entry. Already used for email/chat/letter; this spec adds call-specific fields: `channel = "telephony"`, `telephony_platform` (callvoip|ringcentral|asterisk|other), `external_call_id` (the platform's call UUID), `direction` (inbound|outbound), `from_number`, `to_number`, `started_at`, `answered_at`, `ended_at`, `duration_seconds`, `queue_name`, `agent_skill`, `disposition_subject`, `disposition_outcome` (resolved|callback|escalated|wrong-number|no-answer|abandoned), `disposition_notes`, `recording_url`, `recording_retention_expires_at`.

**Cti adapter config** (new schema `cti_adapter_config`): per-org configuration. Fields: `platform`, `api_base_url`, `auth_method` (basic|oauth|api_key|webhook-secret), `credentials_ref` (pointer to OpenConnector source — credentials never stored in pipelinq), `screen_pop_enabled`, `screen_pop_delay_ms` (default 0; some orgs want a 2s delay so the agent can hear the greeting first), `click_to_dial_enabled`, `default_outbound_caller_id`, `webhook_secret`.

**Cti event log** (new schema `cti_event_log`): raw inbound webhook payloads from the telephony platform, retained 30 days for debugging. Fields: `received_at`, `platform`, `event_type` (ringing|answered|ended|abandoned|transferred), `external_call_id`, `payload_json`, `processed_at`, `processing_error`.

**Agent presence** (new schema `cti_agent_presence`): tracks which pipelinq user is currently logged into the telephony platform and on which extension. Fields: `user_id`, `extension`, `presence_state` (available|on-call|wrap-up|away|offline), `last_updated_at`, `platform`.

## Requirements

### REQ-001: Inbound screen-pop on call answer

**GIVEN** an inbound call arrives at an agent's extension and the agent answers
**WHEN** the telephony platform sends the `answered` webhook to pipelinq
**THEN** pipelinq looks up the calling number in the contact database
**AND** if a single contact matches, the agent's active browser tab is navigated to that contact's `klantbeeld-360` view
**AND** if multiple contacts match, a chooser modal appears with the top matches
**AND** if no contact matches, a "new contact" intake form is opened pre-filled with the phone number.

### REQ-002: Number normalisation for matching

**GIVEN** an inbound number arrives in any format (E.164, national, with or without country code)
**WHEN** the lookup runs
**THEN** the number is normalised to E.164 using the org's default country
**AND** the match is performed against the normalised stored numbers
**AND** the original raw number is preserved in the contactmoment for forensics.

### REQ-003: Click-to-dial outbound

**GIVEN** a phone-number field is rendered anywhere in pipelinq (contact detail, deal detail, klantbeeld-360)
**WHEN** the user clicks the phone icon next to the number
**THEN** pipelinq calls the configured CTI adapter's `originate` endpoint with the user's extension and the target number
**AND** the telephony platform rings the agent's extension first, then dials the target
**AND** a contactmoment row is created in `pending` state immediately, to be enriched when the call ends.

### REQ-004: Contactmoment creation on every call

**GIVEN** any call (inbound or outbound) is processed
**WHEN** the call's `ended` event arrives
**THEN** a contactmoment row exists or is created with all available metadata (from/to/direction/duration/queue)
**AND** the row is linked to the matched contact if one was identified
**AND** the agent is presented with a disposition form (subject, outcome, notes) — required to complete within the wrap-up window or the contactmoment stays in `disposition-pending` state and appears in the agent's task list.

### REQ-005: Recording metadata attachment

**GIVEN** the telephony platform produces a call recording and exposes a recording URL via webhook
**WHEN** the recording-ready event arrives
**THEN** the contactmoment is updated with `recording_url` and `recording_retention_expires_at`
**AND** the recording URL is rendered as a "Listen to recording" link in the contactmoment view, opening the platform's recording player in a new tab
**AND** pipelinq does NOT download or store the audio file (compliance / retention is the platform's responsibility).

### REQ-006: Pluggable adapter per platform

**GIVEN** an org has configured `cti_adapter_config.platform = "callvoip"` (or ringcentral, asterisk)
**WHEN** any CTI action is invoked (screen-pop dispatch, click-to-dial, presence sync)
**THEN** the platform-specific adapter is loaded from the adapter registry
**AND** each adapter implements a common interface: `handleInboundWebhook(payload)`, `originateCall(extension, target)`, `subscribeToPresence(userId)`
**AND** adding a new platform requires only a new adapter class — no changes to pipelinq core.

### REQ-007: Webhook signature verification

**GIVEN** an inbound webhook arrives from a telephony platform
**WHEN** the request is received
**THEN** the adapter verifies the signature using the configured `webhook_secret` (HMAC-SHA256 for CallVoip/Asterisk, OAuth bearer for RingCentral)
**AND** unsigned or invalid requests are rejected with HTTP 401 and logged to `cti_event_log` with `processing_error = "signature_invalid"`
**AND** rate-limiting (100 req/sec per platform) prevents abuse.

### REQ-008: Agent presence sync

**GIVEN** an agent toggles their presence on the telephony platform (available → away)
**WHEN** the platform pushes the presence event
**THEN** pipelinq updates `cti_agent_presence` for that user
**AND** the queue-management routing logic (separate spec) sees the new presence within 2 seconds
**AND** agents marked `on-call` cannot receive click-to-dial originations until they return to `available`.

### REQ-009: Disposition outcomes drive workflow

**GIVEN** an agent sets a disposition outcome of `callback`
**WHEN** the disposition form is submitted
**THEN** the agent is prompted to schedule the callback (date/time, who owns it)
**AND** a callback record is created via the `callback-management` spec
**AND** outcomes of `escalated` create a follow-up task assigned to the configured escalation queue.

### REQ-010: CTI configuration UI for admins

**GIVEN** a pipelinq admin opens the CTI settings page
**WHEN** the page renders
**THEN** the admin can select platform, configure base URL, link to an OpenConnector source for credentials, set screen-pop delay, enable/disable click-to-dial, and configure default outbound caller ID
**AND** a "Test connection" button verifies the adapter can authenticate with the platform
**AND** changes write an audit-log entry.

## Standards

- **Phone number normalisation** uses the `libphonenumber` library and ISO 3166 country codes; storage is always E.164.
- **Webhook authentication** follows each vendor's published spec — HMAC-SHA256 (CallVoip), OAuth 2.0 bearer (RingCentral), shared-secret query param (Asterisk AMI bridge).
- **Contactmoment** schema aligns with the Dutch `klantinteractie` model used in the gemeentelijke common ground KIC standards.
- **GDPR / call recording**: pipelinq only stores metadata + URL; the actual audio retention follows the telephony vendor's contract, ensuring no double-storage of personal data (lawful basis: legitimate interest / consent depending on jurisdiction).

## Cross-App

- **klantbeeld-360**: screen-pop targets the klantbeeld view; the screen-pop URL pattern depends on klantbeeld's routing being stable.
- **callback-management**: disposition outcome `callback` creates a callback record in this dependent spec.
- **queue-management**: presence updates feed queue routing; the queue-management spec consumes `cti_agent_presence` as a read source.
- **openconnector**: credentials for telephony platforms are stored as OpenConnector sources (consistent secrets handling), referenced by `credentials_ref`.
- **openregister**: contactmoment, cti_adapter_config, cti_event_log, cti_agent_presence are OR schemas in the `pipelinq` register.

## Target Users

- **Contact-centre agent**: receives screen-pops on inbound calls, click-to-dials for outbound, fills disposition after each call; needs the workflow to be sub-second to keep handling-times competitive.
- **Inside sales rep**: uses click-to-dial heavily for outbound prospecting, relies on contactmoment auto-creation to keep CRM activity logged without manual effort.
- **Contact-centre supervisor / team lead**: monitors agent presence, reviews dispositions for quality, listens to recordings (via deep-link to the platform's player) for coaching.
- **Pipelinq admin / IT**: configures the adapter, manages credentials, troubleshoots webhook failures using the `cti_event_log`.
- **Compliance officer**: relies on contactmoment + recording metadata for audit trails (GDPR DSARs, complaint investigations, regulatory disclosure).

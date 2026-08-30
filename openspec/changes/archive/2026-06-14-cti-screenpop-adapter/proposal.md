# Proposal: CTI Screen-Pop and Click-to-Dial Adapter

## Problem

Contact centre and inside-sales operations rely on Computer Telephony Integration (CTI) to eliminate time-wasting manual workflows: screen-pops that auto-navigate to the caller's CRM record when a call arrives, and click-to-dial that initiates outbound calls without manual keypad entry. Without CTI, agents burn 8–15 seconds per call on customer identification and phone number entry, wasting 20+ minutes per agent per day at typical call volumes (80/day).

Current state in Pipelinq:
- No integration with telephony platforms (CallVoip, RingCentral, Asterisk)
- No screen-pop routing on inbound calls
- No click-to-dial capability for outbound calls
- No automatic contact moment creation linked to calls
- No recording metadata attachment
- **Hard requirement**: 98% of KCC/CRM procurement tenders list CTI as mandatory

## Solution

Implement a pluggable CTI adapter layer for Pipelinq that:

1. **Inbound screen-pop** — When an agent answers a call, Pipelinq receives a webhook, looks up the caller's phone number, and auto-navigates the agent's browser tab to the matching `klantbeeld-360` contact view.
2. **Outbound click-to-dial** — Phone number fields throughout Pipelinq gain a click-to-dial icon; one click originates the call via the configured telephony platform.
3. **Automatic contact moment creation** — Every call (inbound/outbound) triggers creation of a `contactmoment` record with full metadata (caller/callee, direction, duration, queue, agent), plus a disposition form for the agent to complete after the call.
4. **Recording metadata** — If the telephony platform supplies a recording URL, it is attached to the contactmoment; the actual audio file is hosted and retained by the platform, not Pipelinq.
5. **Multiple platforms** — A pluggable adapter registry supports CallVoip (Dutch SME leader), RingCentral (international), and Asterisk (self-hosted), with extensibility for additional platforms via new adapter classes.

## Scope

- Inbound screen-pop with single/multiple match handling and new contact fallback
- Phone number normalisation (E.164 via libphonenumber) for reliable matching
- Outbound click-to-dial triggering via adapter originate endpoint
- Automatic contactmoment creation on call answer and end
- Disposition form (subject, outcome, notes) with callback/escalation workflow hooks
- Recording metadata attachment (URL + retention expiry)
- Webhook signature verification (HMAC-SHA256 for CallVoip/Asterisk; OAuth for RingCentral)
- Agent presence sync to `cti_agent_presence` schema (integration point for queue-management)
- CTI admin configuration UI (platform, credentials via OpenConnector, screen-pop delay, click-to-dial toggle)
- Platform-agnostic adapter interface with implementations for CallVoip, RingCentral, Asterisk

## Out of scope

- Softphone functionality (browser-based SIP/RTP audio streaming) — pipelinq relies on vendor softphone
- Call recording storage — only metadata + URL are stored
- V2+ features: call transfer tracking, IVR integration, real-time queue status via PBX APIs
- PDF call logs / scheduled report delivery (V2)
- GDPR automated deletion of old contactmomenten based on retention policy (V2, dependent on separate compliance audit)

## Standards

- Phone number normalisation: libphonenumber library + ISO 3166 country codes; storage always in E.164 format
- Webhook authentication per vendor specs: HMAC-SHA256 (CallVoip), OAuth 2.0 bearer token (RingCentral), shared-secret query param (Asterisk)
- Contactmoment schema alignment with VNG klantinteractie model (Dutch Kadaster Standards)
- GDPR compliance: pipelinq stores metadata + URL only; actual recording retention is platform responsibility

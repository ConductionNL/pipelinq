# Proposal: whatsapp-sms-channel-adapter

## Summary

WhatsApp and SMS channel adapters for Pipelinq's omnichannel layer, enabling KCC agents to send and receive messages via WhatsApp Business (Meta Cloud API + BSP fallback) and SMS (MessageBird, Twilio, CM.com) with full opt-in/opt-out compliance, budget controls, media support, and template lifecycle management.

Based on market research: MKB customers communicate primarily on WhatsApp; current omnichannel infrastructure recognises only email, web-form, and phone. This change consolidates personal WhatsApp conversations and SMS notifications under the audit trail, audit trail, and reporting.

## Demand Evidence

### Customer Pain Points
1. **WhatsApp is off-record** — Customer conversations happen on personal phones; no audit trail, no SLA clock, no reporting. When staff leaves, history walks out with them.
2. **SMS is scattered** — Appointment reminders, 2FA, notifications paid out of marketing budgets; no unified ledger or compliance visibility.
3. **Multi-BSP friction** — Tenants juggle MessageBird, Twilio, CM.com accounts; no unified send interface or provider abstraction.
4. **Consent gaps** — No automated opt-out tracking, no evidence of consent under GDPR/AVG Art. 6-7, no template approval audit.

### Target Users
- **MKB-medewerker (KCC agent)** — Needs familiar chat UI that doesn't require remembering channel-specific rules (session windows, templates vs free-form).
- **Directeur-eigenaar** — Budget owner; wants to cap WhatsApp/SMS spend and avoid runaway notification campaigns.
- **Compliance / DPO** — Needs consent records with evidence, opt-in audit trails, and GDPR erasure honoring.
- **Customer of the MKB** — Receives messages on their preferred channel; STOP keyword works; replies go to a real person, not a bot.

## Scope

### In Scope
- **WhatsApp Business adapter** speaking Meta Cloud API directly with BSP fallback (Twilio, MessageBird, 360dialog) per tenant.
- **SMS adapter** abstracting MessageBird, Twilio, CM.com behind a unified send/receive interface.
- Full **template message (HSM) lifecycle**: submit, sync approval state, send approved templates, handle rejections.
- **24-hour session-window enforcement** for free-form WhatsApp messages.
- **Inbound webhook routing** to KCC queues using the same rules as email/phone/web-form.
- **Opt-in/opt-out compliance**: STOP/STOPALL/UITSCHRIJVEN keywords, consent record auditing, no-send enforcement.
- **Per-tenant budget controls** with hard-stop or alert-only policies.
- **Media support** (image, document, audio, video) for inbound and outbound, virus scanned, stored in Nextcloud Files.
- **Cost capture** from provider webhooks or price-list estimation for reporting and budget tracking.
- **Two-way conversation threading** — all messages on a contact thread into a single conversation timeline.

### Out of Scope
- WhatsApp **group messaging** (Meta-restricted, not commercially viable for MKB).
- **MMS** (US-centric, minimal Dutch use).
- **RCS** (carrier coverage in NL still poor).
- Direct **telephony integration** (CTI, click-to-call) — separate app.
- **Calendar/agenda integration** for agent availability (future feature).
- Custom **AI-powered chatbots** or automation rules (future feature).

## Acceptance Criteria

1. **GIVEN** an MKB tenant configured with a WhatsApp Business provider (Meta Cloud API or BSP), **WHEN** an agent sends a message to a customer, **THEN** the message MUST appear in the customer's WhatsApp app and be recorded in the conversation timeline with full metadata (channel, provider, cost, delivery status).

2. **GIVEN** an SMS provider is configured, **WHEN** a callback reminder is scheduled for SMS delivery, **THEN** the SMS MUST be sent via the highest-priority active provider (with failover to lower priorities on transient error) and the message MUST be logged.

3. **GIVEN** a customer replies to an outbound message on WhatsApp, **WHEN** the inbound webhook arrives, **THEN** the message MUST be assigned to the correct conversation, routed to the KCC queue, and surfaced to an available agent without requiring a template.

4. **GIVEN** a customer types "STOP" in WhatsApp or SMS, **WHEN** the adapter processes it, **THEN** the opt-out MUST be recorded automatically with evidence, an acknowledgement template MUST be sent, and subsequent sends to that contact MUST be refused with a `consentMissing` error.

5. **GIVEN** a monthly message budget of 5.000 with `hardStop: true`, **WHEN** the 5.001st attempt is made, **THEN** the send MUST be refused with `budgetExceeded` and an admin notification MUST fire.

6. **GIVEN** a WhatsApp template is submitted for Meta approval, **WHEN** the sync job runs and Meta reports approval/rejection, **THEN** the local template status MUST update and the agent UI MUST reflect whether the template is available for send.

7. **GIVEN** inbound WhatsApp image arrives, **WHEN** the adapter processes it, **THEN** the image MUST be downloaded from Meta within 5 minutes, virus-scanned, stored in Nextcloud Files, and linked from the message.

## Dependencies

- **omnichannel-registratie** (completed) — Extends `conversation` and `message` schemas; uses existing KCC routing, queueing, and agent UI.
- **client-management** (completed) — Contact records and placeholder contact creation.
- **openconnector** (completed) — Retry, rate-limit, and audit-log for outbound provider calls.
- **sla-engine-and-escalation** (completed) — Escalation chains respect consent and budget.
- **request-management** (completed) — Inbound messages can spawn requests.
- **callback-management** (this batch) — Primary SMS use case for appointment reminders.
- **customer-portal** (future) — 2FA SMS flow.
- **docudesk** (completed) — PDF attachments land as docudesk docs.
- **Nextcloud Files & Mail** (built-in) — Media storage and integration.
- **Meta Cloud API**, **Twilio**, **MessageBird**, **CM.com**, **360dialog** — External provider SDKs.

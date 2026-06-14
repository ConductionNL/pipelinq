# Specs: whatsapp-sms-channel-adapter

## Requirements

### REQ-001 WhatsApp template message send

The adapter MUST be able to send an approved WhatsApp HSM (Highly Structured Message) to a recipient regardless of session state, populating placeholder variables from a parameter array.

- **GIVEN** an approved template `afspraak_bevestiging_nl` with body "Beste {{1}}, uw afspraak op {{2}} om {{3}} is bevestigd", **WHEN** the adapter is called with parameters `["Jan", "vrijdag 5 juli", "14:00"]`, **THEN** the message MUST be sent via the configured provider and a `message` record MUST be created with `templateId`, `externalMessageId`, and `deliveryStatus: queued`.
- **GIVEN** a template with status `pending` or `rejected`, **WHEN** the adapter is asked to send it, **THEN** the API MUST return a 422 with `templateNotApproved` and no provider call MUST be made.
- **GIVEN** the number of provided parameters does not match the placeholder count in the template body, **WHEN** the send is attempted, **THEN** the adapter MUST refuse with `templateParameterMismatch` listing expected and given counts.

---

### REQ-002 24-hour session-message window enforcement

The adapter MUST track the WhatsApp 24-hour customer-service window per contact and refuse free-form (non-template) sends outside it.

- **GIVEN** a contact replied inbound at 10:00 today, **WHEN** the adapter sends a free-form message at 12:00 the same day, **THEN** the send MUST succeed and `windowExpiresAt` on the new message MUST be 10:00 next day.
- **GIVEN** the last inbound from a contact was 30 hours ago, **WHEN** the adapter sends a free-form message, **THEN** the API MUST return a 409 with `sessionWindowExpired` and suggest sending a template; the agent UI MUST surface a one-click "open with template" flow.
- **GIVEN** the contact has no inbound messages on record at all, **WHEN** the first outbound is attempted, **THEN** the adapter MUST require a template (no session exists yet).

---

### REQ-003 Inbound webhook routing to KCC

Provider webhooks (Meta `messages` event, Twilio inbound SMS, MessageBird inbound) MUST be verified by signature, normalised to the internal `message` shape, attached to the correct conversation, and routed into the KCC queue using the same rules as email and web-form.

- **GIVEN** a Meta webhook arrives with a valid `X-Hub-Signature-256` matching the provider's `webhookSecret`, **WHEN** the adapter receives it, **THEN** the message MUST be persisted, attached to (or used to open) the conversation for that phone number, and a `MessageReceivedEvent` MUST fire.
- **GIVEN** an inbound message arrives with an invalid signature, **WHEN** the adapter receives it, **THEN** the request MUST be rejected with 401, logged with the source IP, and not persisted.
- **GIVEN** an inbound message is the first from a previously unknown phone number, **WHEN** the adapter processes it, **THEN** a placeholder contact MUST be created in `client-management` and the conversation MUST land in the unassigned queue with channel `whatsapp` so the KCC routing rules can pick it up.

---

### REQ-004 SMS provider abstraction

The SMS adapter MUST accept a single `send(to, body, providerHint?)` interface and resolve the actual provider via the tenant's active `channel_provider` rows, with failover to lower-priority providers on transient errors.

- **GIVEN** a tenant has MessageBird (priority 1) and Twilio (priority 2) configured, **WHEN** MessageBird returns a 5xx, **THEN** the adapter MUST retry on Twilio within the same call and surface success to the caller without exposing the failover.
- **GIVEN** both providers return 5xx, **WHEN** the adapter exhausts retries, **THEN** the `message` row MUST be persisted with `deliveryStatus: failed`, the conversation MUST get a system note "SMS-verzending mislukt op alle providers", and a notification MUST go to the tenant administrator.
- **GIVEN** the caller specifies `providerHint: 'cm-com'` and that provider is configured, **WHEN** the send runs, **THEN** the hinted provider MUST be used directly without failover (caller-pinned).

---

### REQ-005 Opt-in and opt-out compliance

The adapter MUST refuse to send WhatsApp or SMS to a contact whose `consent_record` for that channel is `opted-out` and MUST automatically record opt-out on the recognised keywords (`STOP`, `STOPALL`, `UITSCHRIJVEN`).

- **GIVEN** a contact has `consent_record.state: opted-out` for SMS, **WHEN** any caller attempts to send SMS to them, **THEN** the API MUST return a 403 with `consentMissing` and no provider call MUST be made.
- **GIVEN** an inbound WhatsApp message body is exactly "STOP" (case-insensitive), **WHEN** the adapter processes it, **THEN** a `consent_record` MUST be written with `state: opted-out, source: keyword-stop` and an automatic acknowledgement template MUST be sent confirming the opt-out (this last template send is permitted under utility category).
- **GIVEN** an opted-out contact replies "JA" to the system's opt-in template, **WHEN** the adapter processes it, **THEN** a new `consent_record` with `state: opted-in, source: chat-reply` MUST be written; the prior opt-out MUST be retained in history (no destructive overwrite, an audit trail).

---

### REQ-006 Send-budget enforcement per month

Each provider MUST be capped by a per-tenant `message_send_budget`. Sends that would breach a `hardStop: true` budget MUST be refused; sends past the `alertThresholdPct` MUST trigger a notification but proceed.

- **GIVEN** a monthly budget of 5.000 messages with `hardStop: true` and 4.999 already sent, **WHEN** a 6th attempt is made, **THEN** the first one (the 5.000th) MUST succeed and the second MUST be refused with `budgetExceeded` plus an admin notification.
- **GIVEN** a budget with `alertThresholdPct: 0.8` and 800 of 1.000 sent, **WHEN** the 800th message goes through, **THEN** an alert MUST fire exactly once for the period; subsequent sends in the same period MUST NOT re-alert.
- **GIVEN** the period boundary is crossed (new month begins), **WHEN** the next send is attempted, **THEN** `currentPeriodMessages` and `currentPeriodCostEur` MUST reset to 0 and `periodResetAt` MUST advance.

---

### REQ-007 Cost capture from provider webhooks

Outbound message cost MUST be captured from the provider's delivery webhook (where exposed) or estimated from the provider's published price-list when not exposed, and written to `message.costEur` for budget and reporting purposes.

- **GIVEN** Twilio's delivery webhook includes a `Price` field, **WHEN** the webhook arrives, **THEN** the `message.costEur` MUST be set from that value converted to EUR using the daily ECB rate, and `currentPeriodCostEur` MUST be incremented.
- **GIVEN** Meta Cloud API does not return per-message cost in the webhook, **WHEN** the delivery webhook arrives, **THEN** the cost MUST be estimated from the provider's static price table (per-conversation pricing tier by category and country), and the `costEur` MUST be flagged `estimated: true` in the message metadata.
- **GIVEN** the daily ECB rate fetch fails, **WHEN** a non-EUR-priced provider sends a webhook, **THEN** the cost MUST be persisted in source currency with a follow-up reconciliation job to apply EUR once the rate becomes available.

---

### REQ-008 Media attachment support

Inbound and outbound messages MUST support image, document, audio, and video attachments within the provider's size limits, stored in Nextcloud Files alongside the conversation.

- **GIVEN** an inbound WhatsApp image arrives, **WHEN** the webhook is processed, **THEN** the media MUST be downloaded from Meta within 5 minutes (Meta-imposed expiry), stored under the conversation's NC Files folder, virus-scanned via the existing pipelinq antivirus hook, and linked from the `message` record.
- **GIVEN** an agent attaches a PDF to an outbound WhatsApp message, **WHEN** the send runs, **THEN** the file MUST be uploaded to the provider's media API and the returned media-id MUST be used in the send call; size MUST be checked against Meta's 100MB limit upfront with a clear UI error if over.
- **GIVEN** the antivirus scan flags an inbound media item, **WHEN** processing continues, **THEN** the file MUST be quarantined (not deleted), the message MUST still be persisted with a `mediaQuarantined: true` marker, and the agent UI MUST display the body but suppress the preview.

---

### REQ-009 Template approval lifecycle sync

The adapter MUST periodically sync template approval state from Meta (and equivalent for BSPs) and reflect status changes in `message_template.status`, alerting on rejections.

- **GIVEN** a template was submitted as `pending`, **WHEN** the sync job runs and Meta reports it as `approved`, **THEN** the local `status` MUST update to `approved`, `lastSyncedAt` MUST update, and the template MUST become available in send-pickers.
- **GIVEN** a previously approved template is `disabled` by Meta (e.g. for category violation), **WHEN** the sync detects the change, **THEN** the template MUST be marked `disabled`, an admin notification MUST fire, and the template MUST disappear from new-send pickers (in-flight sends complete).
- **GIVEN** the sync job cannot reach Meta for 24 hours, **WHEN** the next sync attempt succeeds, **THEN** any status changes that occurred during the outage MUST be reconciled and an admin alert MUST fire if any reconciled state is `rejected`/`disabled`.

---

### REQ-010 Two-way conversation threading

Outbound and inbound messages on the same contact and provider MUST thread into a single conversation timeline that the KCC agent sees as one continuous chat, regardless of which agent sent which message.

- **GIVEN** agent A sends an outbound WhatsApp at 09:00 and agent B replies inbound is received at 10:00 and agent C sends another outbound at 11:00, **WHEN** the KCC opens the contact's conversation, **THEN** all three messages MUST appear in chronological order in the same conversation thread.
- **GIVEN** the same contact is reached on both WhatsApp and SMS, **WHEN** the KCC views the contact, **THEN** the two channels MUST be visible as separate conversation tabs (not merged) but both MUST link to the same contact record.
- **GIVEN** an outbound message fails delivery and the agent retries with a different body 10 minutes later, **WHEN** the conversation is viewed, **THEN** both the failed attempt and the retry MUST appear in the timeline with clear status indicators (no silent overwrite).

---

## Standards & Compliance

- **Meta WhatsApp Business Platform Cloud API** — https://developers.facebook.com/docs/whatsapp/cloud-api (HSM, 24h window, webhook events, media, pricing).
- **Meta Business Messaging Policy** — opt-in, category definitions (marketing/utility/authentication).
- **WhatsApp Commerce Policy** — restricted goods; we surface but do not enforce.
- **Twilio Programmable Messaging API** — https://www.twilio.com/docs/messaging (SMS + WhatsApp fallback).
- **MessageBird Conversations API** — https://developers.messagebird.com (EU data residency).
- **CM.com Business Messaging API** — Dutch SMS provider, REST/SOAP.
- **360dialog WhatsApp BSP** — EU-based alternative.
- **GDPR / AVG Art. 6 & 7** — Legal basis and evidence in `consent_record`.
- **Telecommunicatiewet Art. 11.7** — Dutch ePrivacy; opt-in for commercial; opt-out keywords.
- **DDMA Code Verspreiding** — Dutch self-regulation; STOP keywords; complaints.
- **E.164** — International phone number format.
- **ECB Euro Reference Rates** — Daily FX for cost conversion.

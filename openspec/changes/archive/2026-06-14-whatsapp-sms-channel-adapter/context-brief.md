---
status: draft
---
# WhatsApp and SMS Channel Adapter

## Purpose

Pipelinq's MKB customers live on WhatsApp. The bakker, the installateur, the zorgaanbieder, the makelaar — their customers contact them on WhatsApp first, email second, phone third, web-form rarely. Yet the omnichannel layer Pipelinq ships today recognises only email, web-form, and phone. Conversations on WhatsApp end up in someone's personal phone, off the audit trail, off the SLA clock, off the reporting. When the medewerker who owned the chat leaves the company, the history walks out with them.

SMS sits in a similar spot but for different reasons: it's the universal fallback for transactional notifications (afspraakbevestigingen, multi-factor authentication, "uw bestelling is onderweg") and for reaching customers who refuse WhatsApp on privacy grounds. Most Dutch MKB ops already pay a per-message bundle to MessageBird or CM.com out of marketing budget; consolidating that spend under one omnichannel ledger is a small but real wins-story for the directeur-eigenaar.

This change adds two new channel adapters to Pipelinq's omnichannel layer:
- A **WhatsApp Business adapter** that speaks the Meta Cloud API directly and can fall back to BSP routers (Twilio, MessageBird, 360dialog) per tenant, with full template-message (HSM) and session-message support.
- An **SMS adapter** that abstracts MessageBird, Twilio, and CM.com behind a single send/receive interface.

Both adapters land messages in the existing `conversation` / `message` schema used by `omnichannel-registratie`, route inbound traffic to the same KCC (klant-contact-centrum) queues that email and phone already use, and respect Dutch and EU compliance constraints around opt-in, opt-out, and template-message approval. They expose per-tenant budget controls so a 20-FTE installateur cannot accidentally burn €4.000 of WhatsApp template fees in a runaway notification campaign.

The MKB framing matters. Enterprise WhatsApp products (Trengo, Salesforce Service Cloud Messaging) assume an Enterprise BSP contract, a dedicated WhatsApp number, and a full-time messaging-ops role. Many Pipelinq tenants will start with the directeur's existing WhatsApp Business number and a €50/mo MessageBird account. The adapter must work at that bottom rung without being a toy, and scale up to multi-BSP routing when they grow.

## Data Model

Four new schemas in the `pipelinq` register, all referenced from the existing `omnichannel-registratie` conversation/message model.

### `channel_provider`
- `id` (uuid, system)
- `kind` (enum: `whatsapp-cloud-api`, `whatsapp-bsp`, `sms`)
- `vendor` (enum: `meta`, `twilio`, `messagebird`, `cm-com`, `360dialog`)
- `displayName` (string) — "MessageBird productie", "Twilio sandbox"
- `credentials` (object, encrypted at rest) — vendor-specific (Meta: `phoneNumberId`, `wabaId`, `systemUserToken`; Twilio: `accountSid`, `authToken`, `from`)
- `phoneNumber` (string E.164) — the tenant's sender identity
- `webhookSecret` (string, generated)
- `active` (boolean)
- `sandbox` (boolean) — Twilio sandbox / MessageBird test mode
- `priority` (integer) — for multi-provider failover

### `message_template` (WhatsApp HSM)
- `id` (uuid, system)
- `providerId` (ref → `channel_provider`)
- `externalId` (string) — Meta-assigned template name
- `language` (string) — `nl`, `nl_BE`, `en`, `de`, `fr`
- `category` (enum: `marketing`, `utility`, `authentication`)
- `status` (enum: `pending`, `approved`, `rejected`, `disabled`)
- `body` (text) — with `{{1}}`, `{{2}}` placeholders
- `header` (object, optional) — `{type: 'text'|'image'|'document', content}`
- `buttons` (array, optional) — quick-reply or URL buttons
- `lastSyncedAt` (datetime) — when we last pulled status from Meta

### `consent_record`
- `id` (uuid, system)
- `contactId` (ref → contact in `client-management`)
- `channel` (enum: `whatsapp`, `sms`)
- `state` (enum: `opted-in`, `opted-out`, `unknown`)
- `source` (enum: `webform`, `chat-reply`, `import`, `admin-override`, `keyword-stop`)
- `recordedAt` (datetime)
- `evidence` (text) — e.g. "klant antwoordde JA op opt-in template 2026-04-12"
- `legalBasis` (enum: `consent`, `contract`, `legitimate-interest`)

### `message_send_budget`
- `id` (uuid, system)
- `tenantId` (string)
- `providerId` (ref → `channel_provider`)
- `period` (enum: `monthly`, `weekly`, `daily`)
- `maxMessages` (integer, nullable)
- `maxCostEur` (decimal, nullable)
- `alertThresholdPct` (decimal, default 0.8)
- `hardStop` (boolean) — refuse sends past the cap vs only alert
- `currentPeriodMessages` (integer, rolling)
- `currentPeriodCostEur` (decimal, rolling)
- `periodResetAt` (datetime)

### Extended `message` (existing schema)
Add fields:
- `channel` — extended enum to include `whatsapp`, `sms`
- `providerId` (ref → `channel_provider`)
- `externalMessageId` (string) — Meta `wamid`, Twilio `MessageSid`
- `templateId` (ref → `message_template`, nullable) — set when send used HSM
- `costEur` (decimal, nullable) — populated from provider webhook
- `deliveryStatus` (enum: `queued`, `sent`, `delivered`, `read`, `failed`, `expired`)
- `windowExpiresAt` (datetime, nullable) — for WhatsApp 24h session window

## Requirements

### REQ-001 WhatsApp template message send
The adapter MUST be able to send an approved WhatsApp HSM (Highly Structured Message) to a recipient regardless of session state, populating placeholder variables from a parameter array.

- **GIVEN** an approved template `afspraak_bevestiging_nl` with body "Beste {{1}}, uw afspraak op {{2}} om {{3}} is bevestigd", **WHEN** the adapter is called with parameters `["Jan", "vrijdag 5 juli", "14:00"]`, **THEN** the message MUST be sent via the configured provider and a `message` record MUST be created with `templateId`, `externalMessageId`, and `deliveryStatus: queued`.
- **GIVEN** a template with status `pending` or `rejected`, **WHEN** the adapter is asked to send it, **THEN** the API MUST return a 422 with `templateNotApproved` and no provider call MUST be made.
- **GIVEN** the number of provided parameters does not match the placeholder count in the template body, **WHEN** the send is attempted, **THEN** the adapter MUST refuse with `templateParameterMismatch` listing expected and given counts.

### REQ-002 24-hour session-message window enforcement
The adapter MUST track the WhatsApp 24-hour customer-service window per contact and refuse free-form (non-template) sends outside it.

- **GIVEN** a contact replied inbound at 10:00 today, **WHEN** the adapter sends a free-form message at 12:00 the same day, **THEN** the send MUST succeed and `windowExpiresAt` on the new message MUST be 10:00 next day.
- **GIVEN** the last inbound from a contact was 30 hours ago, **WHEN** the adapter sends a free-form message, **THEN** the API MUST return a 409 with `sessionWindowExpired` and suggest sending a template; the agent UI MUST surface a one-click "open with template" flow.
- **GIVEN** the contact has no inbound messages on record at all, **WHEN** the first outbound is attempted, **THEN** the adapter MUST require a template (no session exists yet).

### REQ-003 Inbound webhook routing to KCC
Provider webhooks (Meta `messages` event, Twilio inbound SMS, MessageBird inbound) MUST be verified by signature, normalised to the internal `message` shape, attached to the correct conversation, and routed into the KCC queue using the same rules as email and web-form.

- **GIVEN** a Meta webhook arrives with a valid `X-Hub-Signature-256` matching the provider's `webhookSecret`, **WHEN** the adapter receives it, **THEN** the message MUST be persisted, attached to (or used to open) the conversation for that phone number, and a `MessageReceivedEvent` MUST fire.
- **GIVEN** an inbound message arrives with an invalid signature, **WHEN** the adapter receives it, **THEN** the request MUST be rejected with 401, logged with the source IP, and not persisted.
- **GIVEN** an inbound message is the first from a previously unknown phone number, **WHEN** the adapter processes it, **THEN** a placeholder contact MUST be created in `client-management` and the conversation MUST land in the unassigned queue with channel `whatsapp` so the KCC routing rules can pick it up.

### REQ-004 SMS provider abstraction
The SMS adapter MUST accept a single `send(to, body, providerHint?)` interface and resolve the actual provider via the tenant's active `channel_provider` rows, with failover to lower-priority providers on transient errors.

- **GIVEN** a tenant has MessageBird (priority 1) and Twilio (priority 2) configured, **WHEN** MessageBird returns a 5xx, **THEN** the adapter MUST retry on Twilio within the same call and surface success to the caller without exposing the failover.
- **GIVEN** both providers return 5xx, **WHEN** the adapter exhausts retries, **THEN** the `message` row MUST be persisted with `deliveryStatus: failed`, the conversation MUST get a system note "SMS-verzending mislukt op alle providers", and a notification MUST go to the tenant administrator.
- **GIVEN** the caller specifies `providerHint: 'cm-com'` and that provider is configured, **WHEN** the send runs, **THEN** the hinted provider MUST be used directly without failover (caller-pinned).

### REQ-005 Opt-in and opt-out compliance
The adapter MUST refuse to send WhatsApp or SMS to a contact whose `consent_record` for that channel is `opted-out` and MUST automatically record opt-out on the recognised keywords (`STOP`, `STOPALL`, `UITSCHRIJVEN`).

- **GIVEN** a contact has `consent_record.state: opted-out` for SMS, **WHEN** any caller attempts to send SMS to them, **THEN** the API MUST return a 403 with `consentMissing` and no provider call MUST be made.
- **GIVEN** an inbound WhatsApp message body is exactly "STOP" (case-insensitive), **WHEN** the adapter processes it, **THEN** a `consent_record` MUST be written with `state: opted-out, source: keyword-stop` and an automatic acknowledgement template MUST be sent confirming the opt-out (this last template send is permitted under utility category).
- **GIVEN** an opted-out contact replies "JA" to the system's opt-in template, **WHEN** the adapter processes it, **THEN** a new `consent_record` with `state: opted-in, source: chat-reply` MUST be written; the prior opt-out MUST be retained in history (no destructive overwrite, an audit trail).

### REQ-006 Send-budget enforcement per month
Each provider MUST be capped by a per-tenant `message_send_budget`. Sends that would breach a `hardStop: true` budget MUST be refused; sends past the `alertThresholdPct` MUST trigger a notification but proceed.

- **GIVEN** a monthly budget of 5.000 messages with `hardStop: true` and 4.999 already sent, **WHEN** a 6th attempt is made, **THEN** the first one (the 5.000th) MUST succeed and the second MUST be refused with `budgetExceeded` plus an admin notification.
- **GIVEN** a budget with `alertThresholdPct: 0.8` and 800 of 1.000 sent, **WHEN** the 800th message goes through, **THEN** an alert MUST fire exactly once for the period; subsequent sends in the same period MUST NOT re-alert.
- **GIVEN** the period boundary is crossed (new month begins), **WHEN** the next send is attempted, **THEN** `currentPeriodMessages` and `currentPeriodCostEur` MUST reset to 0 and `periodResetAt` MUST advance.

### REQ-007 Cost capture from provider webhooks
Outbound message cost MUST be captured from the provider's delivery webhook (where exposed) or estimated from the provider's published price-list when not exposed, and written to `message.costEur` for budget and reporting purposes.

- **GIVEN** Twilio's delivery webhook includes a `Price` field, **WHEN** the webhook arrives, **THEN** the `message.costEur` MUST be set from that value converted to EUR using the daily ECB rate, and `currentPeriodCostEur` MUST be incremented.
- **GIVEN** Meta Cloud API does not return per-message cost in the webhook, **WHEN** the delivery webhook arrives, **THEN** the cost MUST be estimated from the provider's static price table (per-conversation pricing tier by category and country), and the `costEur` MUST be flagged `estimated: true` in the message metadata.
- **GIVEN** the daily ECB rate fetch fails, **WHEN** a non-EUR-priced provider sends a webhook, **THEN** the cost MUST be persisted in source currency with a follow-up reconciliation job to apply EUR once the rate becomes available.

### REQ-008 Media attachment support
Inbound and outbound messages MUST support image, document, audio, and video attachments within the provider's size limits, stored in Nextcloud Files alongside the conversation.

- **GIVEN** an inbound WhatsApp image arrives, **WHEN** the webhook is processed, **THEN** the media MUST be downloaded from Meta within 5 minutes (Meta-imposed expiry), stored under the conversation's NC Files folder, virus-scanned via the existing pipelinq antivirus hook, and linked from the `message` record.
- **GIVEN** an agent attaches a PDF to an outbound WhatsApp message, **WHEN** the send runs, **THEN** the file MUST be uploaded to the provider's media API and the returned media-id MUST be used in the send call; size MUST be checked against Meta's 100MB limit upfront with a clear UI error if over.
- **GIVEN** the antivirus scan flags an inbound media item, **WHEN** processing continues, **THEN** the file MUST be quarantined (not deleted), the message MUST still be persisted with a `mediaQuarantined: true` marker, and the agent UI MUST display the body but suppress the preview.

### REQ-009 Template approval lifecycle sync
The adapter MUST periodically sync template approval state from Meta (and equivalent for BSPs) and reflect status changes in `message_template.status`, alerting on rejections.

- **GIVEN** a template was submitted as `pending`, **WHEN** the sync job runs and Meta reports it as `approved`, **THEN** the local `status` MUST update to `approved`, `lastSyncedAt` MUST update, and the template MUST become available in send-pickers.
- **GIVEN** a previously approved template is `disabled` by Meta (e.g. for category violation), **WHEN** the sync detects the change, **THEN** the template MUST be marked `disabled`, an admin notification MUST fire, and the template MUST disappear from new-send pickers (in-flight sends complete).
- **GIVEN** the sync job cannot reach Meta for 24 hours, **WHEN** the next sync attempt succeeds, **THEN** any status changes that occurred during the outage MUST be reconciled and an admin alert MUST fire if any reconciled state is `rejected`/`disabled`.

### REQ-010 Two-way conversation threading
Outbound and inbound messages on the same contact and provider MUST thread into a single conversation timeline that the KCC agent sees as one continuous chat, regardless of which agent sent which message.

- **GIVEN** agent A sends an outbound WhatsApp at 09:00 and agent B replies inbound is received at 10:00 and agent C sends another outbound at 11:00, **WHEN** the KCC opens the contact's conversation, **THEN** all three messages MUST appear in chronological order in the same conversation thread.
- **GIVEN** the same contact is reached on both WhatsApp and SMS, **WHEN** the KCC views the contact, **THEN** the two channels MUST be visible as separate conversation tabs (not merged) but both MUST link to the same contact record.
- **GIVEN** an outbound message fails delivery and the agent retries with a different body 10 minutes later, **WHEN** the conversation is viewed, **THEN** both the failed attempt and the retry MUST appear in the timeline with clear status indicators (no silent overwrite).

## Standards & Sources

- **Meta WhatsApp Business Platform Cloud API** — https://developers.facebook.com/docs/whatsapp/cloud-api (authoritative reference for HSM, 24h window, webhook events, media handling, pricing tiers).
- **Meta Business Messaging Policy** — opt-in requirements, category definitions (marketing/utility/authentication post-2023 pricing), proactive-message rules.
- **WhatsApp Commerce Policy** — restricted goods (alcohol, tobacco, prescription drugs) that some MKB tenants will trip over; we surface but do not enforce, because Meta enforces at submission time.
- **Twilio Programmable Messaging API** — https://www.twilio.com/docs/messaging (SMS + WhatsApp-via-Twilio fallback path).
- **MessageBird Conversations API** — https://developers.messagebird.com (popular NL choice, native EU data residency).
- **CM.com Business Messaging API** — Dutch-incumbent SMS provider, often already on the MKB's invoice; their REST/SOAP send API.
- **360dialog WhatsApp BSP** — EU-based BSP, common alternative when Meta Cloud API isn't appropriate.
- **GDPR / AVG Art. 6** — legal basis (`consent`, `contract`, `legitimate-interest`) recorded in `consent_record.legalBasis`; Art. 7 evidentiability mapped to `consent_record.evidence`.
- **Telecommunicatiewet Art. 11.7** — Dutch transposition of ePrivacy; opt-in requirement for unsolicited commercial messaging; opt-out keyword obligations.
- **Code Verspreiding Reclame via E-mail / SMS (DDMA)** — Dutch self-regulation; recognised STOP keywords; complaints handling.
- **E.164** — international phone number format used throughout `channel_provider.phoneNumber` and `to` fields.
- **ECB euro reference rates** — daily FX for cost conversion (REQ-007).

## Cross-app Integration

- **`omnichannel-registratie`** (pipelinq spec): the adapter extends its `conversation` / `message` schemas with the new fields and channels; routing rules, queueing, and agent UI live there.
- **`client-management`** (pipelinq spec): provides the contact records; the adapter writes back placeholder contacts on first inbound from an unknown number; `consent_record` is associated with contacts here.
- **`openconnector`**: every outbound provider call goes through openconnector source rows for retry, rate-limit handling, and call-log audit; webhook ingress is handled by openconnector's standard receiver pattern with signature verification.
- **`sla-engine-and-escalation`** (pipelinq spec): exposed as `channel: whatsapp` / `channel: sms` in escalation chains; the engine never bypasses consent and budget controls — it goes through the adapter's normal send path.
- **`request-management`** (pipelinq spec): inbound WhatsApp messages can spawn or update requests via the existing email-to-request pattern, generalised by `omnichannel-registratie`.
- **`callback-management`** (pipelinq spec): outbound SMS reminders ("we bellen u terug om 14:00") are a primary use case for the SMS adapter and a budget consumer.
- **`customer-portal`** (pipelinq spec): the portal's "stuur me een SMS-code" two-factor flow uses the SMS adapter with `category: authentication` priced separately under Meta rules and outside the marketing budget.
- **`docudesk`**: PDF attachments sent or received via WhatsApp land as docudesk documents linked to the conversation, picking up signing and retention policies automatically.
- **`openregister`**: the adapter is implemented as a register listener app; `seed-related-items` ships starter HSM templates (appointment-confirmation, callback-scheduled, opt-in) in `nl` and `en`.
- **`hydra/openspec` shared specs**: `i18n-nl` and `i18n-en` cover adapter user-facing strings and starter templates; an ADR is warranted on "consent storage and retention" since this pattern will recur across apps (Decidesk participant comms, Pipelinq omnichannel, future Conduction commerce features).

## Target Users

- **MKB-medewerker in de klantenservice** — primary day-to-day user; needs a familiar chat UI in Nextcloud that doesn't ask them to remember which channel rules apply (template vs free-form). The session-window enforcement (REQ-002) and the one-click "open with template" fallback are designed for this user.
- **Directeur-eigenaar / business owner** — wants to consolidate the personal-WhatsApp-on-my-phone mess into the company's audit trail without losing the speed of "ik typ even snel iets terug". Also the budget owner (REQ-006).
- **Marketing / customer-success lead** — wants to send appointment reminders, NPS surveys, opt-in requests; needs the template lifecycle (REQ-009) and the category pricing visibility.
- **Compliance / DPO at the tenant** — needs `consent_record` retention, evidence of opt-in (REQ-005), and the ability to honour AVG erasure requests by deleting conversations along with their contact.
- **Customer of the MKB** — receives messages on the channel they actually use; STOP works as advertised; replies go to a real person, not a black hole.
- **Pipelinq administrator (Conduction-side or partner-implementer)** — configures `channel_provider` rows, switches BSPs without code changes, manages multi-tenant credential isolation.

Out of scope for this brief: WhatsApp group messaging (Meta-restricted, not commercially viable), MMS (US-centric, almost no NL use), RCS (carrier coverage in NL still poor in 2026). These remain open for a future spec when the market warrants it.

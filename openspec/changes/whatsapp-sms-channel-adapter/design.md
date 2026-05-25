# Design: whatsapp-sms-channel-adapter

## Context

Pipelinq already has omnichannel infrastructure (`omnichannel-registratie`) with `conversation` and `message` schemas, KCC queue routing, and agent UI. The existing `openconnector` app handles provider call retry, rate-limiting, and audit logging. The `client-management` app provides contact entities and GDPR erasure hooks.

This change adds four new schemas to the `pipelinq` register (all stored in `lib/Settings/pipelinq_register.json`):
- `channel_provider` — WhatsApp/SMS provider credentials, webhookSecret, priority.
- `message_template` — WhatsApp HSM (Highly Structured Message) templates with approval state and sync tracking.
- `consent_record` — GDPR Art. 6 legal basis + Art. 7 evidence for opt-in/opt-out audit trails.
- `message_send_budget` — Per-tenant, per-provider cap on messages and/or cost, hard-stop or alert-only.

It also extends the existing `message` schema with new fields: `channel` (enum now includes `whatsapp`, `sms`), `providerId`, `externalMessageId`, `templateId`, `costEur`, `deliveryStatus`, `windowExpiresAt`.

## Goals / Non-Goals

### Goals
- Provide adapters (`WhatsAppAdapter`, `SmsAdapter`) for message send/receive on WhatsApp and SMS.
- Implement **24-hour session-window enforcement** for free-form WhatsApp sends.
- Implement **template lifecycle sync** from Meta, Twilio, MessageBird (approval state).
- Implement **opt-in/opt-out compliance** with automatic keyword detection (STOP, STOPALL, UITSCHRIJVEN).
- Implement **per-tenant budget enforcement** with hard-stop or alert-only policies.
- Implement **inbound webhook routing** to KCC queues (message validation, contact matching, conversation attachment).
- Implement **media attachment support** (download, store in Nextcloud Files, virus scan).
- Implement **cost capture** from provider webhooks or price-list estimation.
- Implement **multi-provider failover** for SMS (try MessageBird, fall back to Twilio if transient error).
- Add unit and integration tests for all business logic.

### Non-Goals
- UI components (agent chat UI lives in `omnichannel-registratie`; form components reuse existing CnFormDialog).
- Calendar/agenda integration for agent availability.
- AI chatbots or automation rules.
- Group messaging support.
- Direct telephony integration (CTI, click-to-call).

## Decisions

### 1. Four New Schemas in pipelinq Register

**Decision**: Define `channel_provider`, `message_template`, `consent_record`, `message_send_budget` as separate OpenRegister schemas in `lib/Settings/pipelinq_register.json`.

**Rationale**: Each schema has distinct CRUD semantics and audit concerns. `channel_provider` is a rare-change admin config; `message_template` needs sync jobs and approval workflows; `consent_record` is an GDPR audit log; `message_send_budget` is a per-tenant control. Separate schemas keep concerns isolated and allow different permission/retention policies.

**Alternative considered**: Flatten into `message` schema. Rejected because it would create redundancy (one `channel_provider` referenced by many `message_template` and `message` rows) and complicate CRUD.

### 2. Webhook Signature Verification via openconnector

**Decision**: Reuse `openconnector` for inbound webhook ingress, including signature verification.

**Rationale**: `openconnector` already provides webhook receiver pattern, signature validation, and retry/audit logging. No need to rebuild.

**Implementation**: Register Meta, Twilio, MessageBird webhook handlers in `openconnector` config as provider source types. The webhook adapter writes to a `pipelinq.webhook_queue` internal schema; a `WebhookProcessorJob` consumes the queue and calls `WhatsAppAdapter::inbound()` / `SmsAdapter::inbound()`.

### 3. Separate Adapters for WhatsApp and SMS

**Decision**: Create `WhatsAppAdapter` and `SmsAdapter` as distinct classes rather than a unified `ChannelAdapter`.

**Rationale**: WhatsApp (Meta Cloud API) has session-window logic, template approval sync, media handling, and 24h resets. SMS has provider abstraction, failover logic, and different cost models. Each has enough unique behavior to warrant separation.

**Alternative considered**: Unified `ChannelAdapter` with WhatsApp and SMS subclasses. Rejected as it would encourage false parallelism and hide the real differences.

### 4. Template Sync as Background Job

**Decision**: `TemplateApprovalSyncJob` periodically (e.g., 1x/hour) queries Meta/BSP template status and updates local `message_template` rows.

**Rationale**: Meta does not push template status changes; we must poll. Polling hourly balances freshness vs API quota. On rejection, the job fires an admin notification and disables the template in the UI.

**Alternative considered**: Sync on every send. Rejected because it adds latency and API calls; hourly is sufficient.

### 5. Opt-Out Keywords as Inbound Message Processing

**Decision**: During inbound webhook processing, if the message body matches a known opt-out keyword (STOP, STOPALL, UITSCHRIJVEN — case-insensitive), automatically:
1. Write a `consent_record` with `state: opted-out, source: keyword-stop`.
2. Send an acknowledgement template (utility category, allowed under Meta rules even to opted-out contacts).
3. Attach the consent record and inbound message to the conversation for agent visibility.

**Rationale**: Telecommunicatiewet Art. 11.7 and DDMA code require keyword support. Automating the workflow removes burden from agents and ensures compliance.

**Alternative considered**: Flag the message for agent action. Rejected because keyword handling must be deterministic and fast; agents can always review the auto-logged consent record.

### 6. Budget Enforcement Before Send

**Decision**: In both `WhatsAppAdapter` and `SmsAdapter`, before calling the provider API:
1. Fetch the tenant's active `message_send_budget` for the selected provider.
2. Check `currentPeriodMessages < maxMessages` and `currentPeriodCostEur < maxCostEur`.
3. If hard-stop budget is exceeded, return 403 `budgetExceeded` and do not call provider.
4. If soft-alert budget is passed, log the send but fire an alert notification.
5. After provider webhook confirms delivery, increment `currentPeriodMessages` and `currentPeriodCostEur`.

**Rationale**: Prevents runaway spend; hard-stop protects against misconfiguration; soft-alert allows opt-in behavior.

**Alternative considered**: Check budget only at send time; reset on cron. Rejected because period boundaries can shift (DST, leap seconds). Instead, use `periodResetAt` as the ground truth.

### 7. Cost Capture from Provider Webhooks

**Decision**: 
- If provider webhook includes cost in source currency (Twilio), convert to EUR using daily ECB rate and store in `message.costEur`.
- If provider does not expose cost (Meta), estimate from static price table (per-category, per-country).
- Mark estimated costs with `costEstimated: true` in `message.metadata` for reconciliation later.
- If ECB rate fetch fails, persist cost in source currency with a follow-up reconciliation job.

**Rationale**: Providers have different cost exposure models; storing estimates (flagged as such) allows budget tracking while acknowledging uncertainty.

**Alternative considered**: Only capture exact costs. Rejected because Meta does not expose per-message cost; estimation allows budget control even for Meta.

### 8. Media Download within 5-Minute Meta Expiry Window

**Decision**: When an inbound WhatsApp webhook arrives with media, immediately (not async):
1. Download the file from Meta's URL using the `media_id` and tenant's API token.
2. Virus-scan via the existing pipelinq antivirus hook.
3. Store in Nextcloud Files under `/pipelinq/conversations/{conversationId}/media/`.
4. Link from the `message` record with file path and antivirus status.

**Rationale**: Meta-imposed 5-minute expiry makes async processing risky. Synchronous download with virus scanning ensures compliance and availability.

**Alternative considered**: Async download job. Rejected due to expiry risk; if webhook processing fails, file is lost.

### 9. Conversation Threading per Contact and Provider

**Decision**: Messages on the same `contact`, `provider`, and `channel` thread into a single conversation record. The conversation's `channel` field reflects the primary channel (e.g., `whatsapp`). If a contact reaches us on both WhatsApp and SMS, two separate conversation records exist but both link to the same contact.

**Rationale**: Preserves one-contact-one-conversation invariant; agents see WhatsApp and SMS as separate tabs on the contact but understand they're the same person. This matches mental models (WhatsApp thread, SMS thread) without forcing a false unified view.

**Alternative considered**: Merged conversation spanning all channels. Rejected because it muddies channel-specific rules (e.g., session window applies only to WhatsApp, not SMS).

## Seed Data

### channel_provider (5 examples per provider, Dutch values)

```json
[
  {
    "id": "uuid-wp-meta-prod",
    "kind": "whatsapp-cloud-api",
    "vendor": "meta",
    "displayName": "WhatsApp Cloud API Productie",
    "phoneNumber": "+31612345678",
    "active": true,
    "sandbox": false,
    "priority": 1,
    "credentials": { "phoneNumberId": "...", "wabaId": "...", "systemUserToken": "..." }
  },
  {
    "id": "uuid-wp-twilio-fallback",
    "kind": "whatsapp-bsp",
    "vendor": "twilio",
    "displayName": "Twilio WhatsApp Fallback",
    "phoneNumber": "+31612345678",
    "active": true,
    "sandbox": false,
    "priority": 2,
    "credentials": { "accountSid": "...", "authToken": "..." }
  },
  {
    "id": "uuid-sms-messagebird",
    "kind": "sms",
    "vendor": "messagebird",
    "displayName": "MessageBird SMS Productie",
    "phoneNumber": "+31612345678",
    "active": true,
    "sandbox": false,
    "priority": 1,
    "credentials": { "accessKey": "..." }
  },
  {
    "id": "uuid-sms-twilio",
    "kind": "sms",
    "vendor": "twilio",
    "displayName": "Twilio SMS Fallback",
    "phoneNumber": "+31612345678",
    "active": true,
    "sandbox": false,
    "priority": 2,
    "credentials": { "accountSid": "...", "authToken": "..." }
  },
  {
    "id": "uuid-sms-cmcom",
    "kind": "sms",
    "vendor": "cm-com",
    "displayName": "CM.com SMS",
    "phoneNumber": "cm-com-account-id",
    "active": false,
    "sandbox": false,
    "priority": 3,
    "credentials": { "apiKey": "..." }
  }
]
```

### message_template (3 examples for WhatsApp HSM)

```json
[
  {
    "id": "uuid-tpl-afspraak",
    "providerId": "uuid-wp-meta-prod",
    "externalId": "afspraak_bevestiging_nl",
    "language": "nl",
    "category": "utility",
    "status": "approved",
    "body": "Beste {{1}}, uw afspraak op {{2}} om {{3}} is bevestigd. Antwoord J voor bevestiging.",
    "header": null,
    "buttons": [ { "type": "quick-reply", "text": "J" }, { "type": "quick-reply", "text": "N" } ],
    "lastSyncedAt": "2026-05-22T14:30:00Z"
  },
  {
    "id": "uuid-tpl-optin",
    "providerId": "uuid-wp-meta-prod",
    "externalId": "opt_in_request_nl",
    "language": "nl",
    "category": "marketing",
    "status": "pending",
    "body": "Mag ik je op de hoogte houden van aanbiedingen en nieuws? Antwoord JA of NEE.",
    "header": null,
    "buttons": [ { "type": "quick-reply", "text": "JA" }, { "type": "quick-reply", "text": "NEE" } ],
    "lastSyncedAt": "2026-05-22T14:00:00Z"
  },
  {
    "id": "uuid-tpl-callback",
    "providerId": "uuid-wp-meta-prod",
    "externalId": "callback_scheduled_nl",
    "language": "nl",
    "category": "utility",
    "status": "approved",
    "body": "Uw terugbel is ingepland voor {{1}} om {{2}}. Wij bellen u op {{3}}.",
    "header": null,
    "buttons": null,
    "lastSyncedAt": "2026-05-22T14:30:00Z"
  }
]
```

### consent_record (2 examples)

```json
[
  {
    "id": "uuid-consent-jdoe-wa-in",
    "contactId": "contact-uuid",
    "channel": "whatsapp",
    "state": "opted-in",
    "source": "chat-reply",
    "recordedAt": "2026-05-20T10:00:00Z",
    "evidence": "Klant antwoordde JA op opt-in template opt_in_request_nl, 2026-05-20",
    "legalBasis": "consent"
  },
  {
    "id": "uuid-consent-jdoe-sms-out",
    "contactId": "contact-uuid",
    "channel": "sms",
    "state": "opted-out",
    "source": "keyword-stop",
    "recordedAt": "2026-05-21T09:30:00Z",
    "evidence": "Inbound SMS berichttekst bevat STOP (case-insensitive)",
    "legalBasis": "consent"
  }
]
```

### message_send_budget (2 examples)

```json
[
  {
    "id": "uuid-budget-whatsapp",
    "tenantId": "tenant-12345",
    "providerId": "uuid-wp-meta-prod",
    "period": "monthly",
    "maxMessages": 10000,
    "maxCostEur": 2500,
    "alertThresholdPct": 0.8,
    "hardStop": false,
    "currentPeriodMessages": 3200,
    "currentPeriodCostEur": 420.50,
    "periodResetAt": "2026-06-01T00:00:00Z"
  },
  {
    "id": "uuid-budget-sms",
    "tenantId": "tenant-12345",
    "providerId": "uuid-sms-messagebird",
    "period": "monthly",
    "maxMessages": 5000,
    "maxCostEur": 500,
    "alertThresholdPct": 0.9,
    "hardStop": true,
    "currentPeriodMessages": 4500,
    "currentPeriodCostEur": 450.00,
    "periodResetAt": "2026-06-01T00:00:00Z"
  }
]
```

## Migration Plan

1. Update `lib/Settings/pipelinq_register.json` to add the four new schemas (`channel_provider`, `message_template`, `consent_record`, `message_send_budget`) and extend the existing `message` schema with new fields.
2. Create `lib/Service/WhatsAppAdapter.php` for Meta Cloud API and BSP message handling.
3. Create `lib/Service/SmsAdapter.php` for multi-provider SMS abstraction.
4. Create `lib/Service/TemplateApprovalSyncService.php` for polling Meta/BSP template status.
5. Create `lib/Service/ConsentService.php` for consent record auditing and opt-out enforcement.
6. Create `lib/Service/BudgetService.php` for message-send budget enforcement.
7. Create `lib/BackgroundJob/TemplateApprovalSyncJob.php` (hourly) for template status sync.
8. Create `lib/BackgroundJob/BudgetPeriodResetJob.php` (daily) for budget period boundaries.
9. Register webhook receivers in `openconnector` config for Meta, Twilio, MessageBird.
10. Update `lib/BackgroundJob/WebhookProcessorJob.php` to handle inbound WhatsApp and SMS webhooks.
11. Add integration tests for adapters, services, and compliance logic.
12. Run repair step to reimport register config.

# Tasks: whatsapp-sms-channel-adapter

## 0. Deduplication Check

- [x] 0.1 Verify that `omnichannel-registratie` already provides `conversation` and `message` schemas and that we are only extending them, not redefining them
  - **Finding**: `message` schema in `omnichannel-registratie` register. This change adds fields: `channel` (enum extended), `providerId`, `externalMessageId`, `templateId`, `costEur`, `deliveryStatus`, `windowExpiresAt`. No new CRUD needed.

- [x] 0.2 Verify that `client-management` provides the `contact` schema and placeholder contact creation is a simple API call
  - **Finding**: Contact creation via `ObjectService.saveObject('client-management', 'contact', {...})`. No additional provider-specific contact type needed.

- [x] 0.3 Verify that `openconnector` is the correct place for webhook receiver registration (not rebuilding signature verification)
  - **Finding**: `openconnector` provides webhook receiver pattern with signature validation. Register Meta, Twilio, MessageBird webhook handlers as provider sources.

- [x] 0.4 Verify that `NotificationService` can fire admin notifications for template rejection, budget alerts, and failed SMS
  - **Finding**: `NotificationService` already supports arbitrary notification types. No new notification class needed; just call `notificationService.notify(...)` with new event types.

---

## 1. Schema Registration & Extension (REQ-001, REQ-002, REQ-003, REQ-004, REQ-005, REQ-006, REQ-007, REQ-008, REQ-009, REQ-010)

- [x] 1.1 Update `lib/Settings/pipelinq_register.json` to add the `channel_provider` schema
  - **spec_ref**: Design § Data Layer
  - **files**: `lib/Settings/pipelinq_register.json`
  - **properties**: `id`, `kind` (enum: whatsapp-cloud-api, whatsapp-bsp, sms), `vendor` (enum: meta, twilio, messagebird, cm-com, 360dialog), `displayName`, `credentials` (encrypted), `phoneNumber` (E.164), `webhookSecret`, `active`, `sandbox`, `priority`
  - **acceptance_criteria**:
    - GIVEN the register is reloaded
    - WHEN a tenant admin navigates to provider settings
    - THEN they can add/edit/delete `channel_provider` objects via the standard OpenRegister form

- [x] 1.2 Update `lib/Settings/pipelinq_register.json` to add the `message_template` schema
  - **spec_ref**: Design § Data Layer
  - **files**: `lib/Settings/pipelinq_register.json`
  - **properties**: `id`, `providerId`, `externalId`, `language`, `category` (enum: marketing, utility, authentication), `status` (enum: pending, approved, rejected, disabled), `body`, `header`, `buttons`, `lastSyncedAt`
  - **acceptance_criteria**:
    - GIVEN a WhatsApp Cloud API provider is configured
    - WHEN the template sync job runs
    - THEN new templates from Meta appear in the `message_template` store

- [x] 1.3 Update `lib/Settings/pipelinq_register.json` to add the `consent_record` schema
  - **spec_ref**: Design § Data Layer
  - **files**: `lib/Settings/pipelinq_register.json`
  - **properties**: `id`, `contactId`, `channel` (enum: whatsapp, sms), `state` (enum: opted-in, opted-out, unknown), `source` (enum: webform, chat-reply, import, admin-override, keyword-stop), `recordedAt`, `evidence`, `legalBasis` (enum: consent, contract, legitimate-interest)
  - **acceptance_criteria**:
    - GIVEN an inbound message with "STOP" keyword
    - WHEN the adapter processes it
    - THEN a `consent_record` is automatically created with audit trail visible in contact detail

- [x] 1.4 Update `lib/Settings/pipelinq_register.json` to add the `message_send_budget` schema
  - **spec_ref**: Design § Data Layer
  - **files**: `lib/Settings/pipelinq_register.json`
  - **properties**: `id`, `tenantId`, `providerId`, `period` (enum: monthly, weekly, daily), `maxMessages`, `maxCostEur`, `alertThresholdPct`, `hardStop`, `currentPeriodMessages`, `currentPeriodCostEur`, `periodResetAt`
  - **acceptance_criteria**:
    - GIVEN a tenant with WhatsApp and SMS providers
    - WHEN the tenant admin sets monthly budgets for each provider
    - THEN sends that exceed the budget are refused or alerted

- [x] 1.5 Extend the `message` schema in `lib/Settings/pipelinq_register.json` with new fields
  - **spec_ref**: Design § Data Layer
  - **files**: `lib/Settings/pipelinq_register.json`
  - **new_fields**: `channel` (enum extended to include whatsapp, sms), `providerId`, `externalMessageId`, `templateId`, `costEur`, `deliveryStatus` (enum: queued, sent, delivered, read, failed, expired), `windowExpiresAt`
  - **acceptance_criteria**:
    - GIVEN an outbound WhatsApp message
    - WHEN the agent sends it via the adapter
    - THEN the `message` record includes all new fields and can be queried by channel or provider

- [x] 1.6 Register the four new schemas in `appinfo/register.json` or equivalent app-level schema manifest
  - **spec_ref**: Design § Migration Plan
  - **files**: `appinfo/register.json` or config file
  - **acceptance_criteria**:
    - GIVEN the app is installed/updated
    - WHEN the register repair step runs
    - THEN all four schemas are available for CRUD

---

## 2. WhatsAppAdapter Implementation (REQ-001, REQ-002, REQ-003, REQ-008, REQ-009)

- [x] 2.1 Create `lib/Service/WhatsAppAdapter.php` with `send(contact, body, templateId?, parameters?)` method
  - **spec_ref**: REQ-001, REQ-002
  - **files**: `lib/Service/WhatsAppAdapter.php`
  - **dependencies**: `ObjectService`, `WhatsAppProviderClient` (abstraction for Meta + BSPs), `ConsentService`, `BudgetService`
  - **acceptance_criteria**:
    - GIVEN a contact with no active session and no template specified
    - WHEN `send()` is called with free-form body
    - THEN the method returns 409 with `sessionWindowExpired`
    - AND when called with a valid `templateId` and matching `parameters`
    - THEN the send succeeds and a `message` record is created

- [x] 2.2 Implement session-window tracking in WhatsAppAdapter (24-hour window from last inbound)
  - **spec_ref**: REQ-002
  - **files**: `lib/Service/WhatsAppAdapter.php`
  - **logic**: Query the contact's most recent inbound `message` on the WhatsApp channel; if within 24h, allow free-form send; otherwise require template
  - **acceptance_criteria**:
    - GIVEN an inbound arrived at 10:00 today
    - WHEN a free-form send is attempted at 12:00 same day
    - THEN it succeeds and `windowExpiresAt` is set to 10:00 next day
    - AND when attempted at 10:01 next day
    - THEN it fails with `sessionWindowExpired`

- [x] 2.3 Implement template parameter validation in WhatsAppAdapter
  - **spec_ref**: REQ-001
  - **files**: `lib/Service/WhatsAppAdapter.php`
  - **logic**: Parse template body for `{{N}}` placeholders; verify count matches supplied parameters; return 422 `templateParameterMismatch` if mismatch
  - **acceptance_criteria**:
    - GIVEN a template with body "Beste {{1}}, uw afspraak op {{2}} om {{3}}"
    - WHEN send is attempted with 2 parameters instead of 3
    - THEN it returns 422 with expected=3, given=2

- [x] 2.4 Implement provider selection logic in WhatsAppAdapter (Meta Cloud API primary, BSP fallback)
  - **spec_ref**: Design § Decisions § Separate Adapters
  - **files**: `lib/Service/WhatsAppAdapter.php`
  - **logic**: Query active `channel_provider` rows with `kind: whatsapp-cloud-api` or `kind: whatsapp-bsp`; try primary (Meta Cloud API) first; on transient error, retry with next priority BSP
  - **acceptance_criteria**:
    - GIVEN a tenant with Meta (priority 1) and Twilio BSP (priority 2) configured
    - WHEN Meta returns a transient error
    - THEN the adapter retries on Twilio and surfaces success without exposing failover

- [x] 2.5 Create `lib/Service/WhatsAppProviderClient.php` abstraction for Meta Cloud API calls
  - **spec_ref**: REQ-001, REQ-002, REQ-008
  - **files**: `lib/Service/WhatsAppProviderClient.php`
  - **methods**: `sendTemplate(phoneNumber, templateName, parameters)`, `sendFreeForm(phoneNumber, body, mediaIds?)`, `downloadMedia(mediaId)`, `uploadMedia(filePath)`
  - **acceptance_criteria**:
    - GIVEN a `WhatsAppProviderClient` configured with tenant credentials
    - WHEN `sendTemplate()` is called
    - THEN the call is made to Meta's API and an `externalMessageId` is returned

- [x] 2.6 Implement inbound webhook handling in WhatsAppAdapter (REQ-003)
  - **spec_ref**: REQ-003
  - **files**: `lib/Service/WhatsAppAdapter.php`, method `handleInboundWebhook(webhookData, providerId)`
  - **logic**: Validate signature via `webhookSecret`; extract message body, sender phone number, media IDs; find or create contact; find or create conversation; persist `message`; fire `MessageReceivedEvent`
  - **acceptance_criteria**:
    - GIVEN a Meta webhook with valid signature
    - WHEN the adapter processes it
    - THEN the message is persisted, attached to the correct conversation, and routed to KCC
    - AND when the signature is invalid
    - THEN the request is rejected with 401 and logged

- [x] 2.7 Implement media attachment handling (REQ-008)
  - **spec_ref**: REQ-008
  - **files**: `lib/Service/WhatsAppAdapter.php`, `lib/Service/MediaAttachmentService.php`
  - **logic**: On inbound webhook with media: download from Meta within 5 min window, store in Nextcloud Files under conversation folder, run antivirus scan, persist metadata in `message.metadata.mediaQuarantined` if scan flags, link file from `message`
  - **acceptance_criteria**:
    - GIVEN an inbound WhatsApp image
    - WHEN the webhook is processed
    - THEN the image is downloaded, scanned, stored in `/pipelinq/conversations/{conversationId}/media/`, and linked from the message
    - AND if antivirus flags it
    - THEN `mediaQuarantined: true` is set and agent UI suppresses preview

- [x] 2.8 Implement template approval sync (REQ-009)
  - **spec_ref**: REQ-009
  - **files**: `lib/Service/TemplateApprovalSyncService.php`, `lib/BackgroundJob/TemplateApprovalSyncJob.php`
  - **logic**: Query Meta API for template status; compare against local `message_template` rows; update `status` and `lastSyncedAt`; fire admin notification on rejection/disabled
  - **acceptance_criteria**:
    - GIVEN a template submitted as `pending`
    - WHEN the sync job runs and Meta reports `approved`
    - THEN the local `status` updates to `approved` and template appears in send-picker
    - AND when Meta reports `disabled`
    - THEN the template disappears from pickers and admin is notified

---

## 3. SmsAdapter Implementation (REQ-004, REQ-003, REQ-008)

- [x] 3.1 Create `lib/Service/SmsAdapter.php` with `send(to, body, providerHint?)` method
  - **spec_ref**: REQ-004
  - **files**: `lib/Service/SmsAdapter.php`
  - **dependencies**: `ObjectService`, `SmsProviderFactory` (creates clients for Twilio, MessageBird, CM.com), `ConsentService`, `BudgetService`
  - **acceptance_criteria**:
    - GIVEN a tenant with MessageBird and Twilio configured
    - WHEN `send()` is called without a hint
    - THEN MessageBird (priority 1) is tried first
    - AND if MessageBird returns 5xx, Twilio is tried within the same call
    - AND success is surfaced without exposing failover

- [x] 3.2 Create `lib/Service/SmsProviderFactory.php` to instantiate provider clients (Twilio, MessageBird, CM.com)
  - **spec_ref**: REQ-004
  - **files**: `lib/Service/SmsProviderFactory.php`, `lib/Service/Provider/TwilioSmsClient.php`, `lib/Service/Provider/MessageBirdSmsClient.php`, `lib/Service/Provider/CmComSmsClient.php`
  - **acceptance_criteria**:
    - GIVEN a provider type and credentials
    - WHEN the factory creates a client
    - THEN the client can call `send(to, body)` and return a provider message ID

- [x] 3.3 Implement provider priority failover logic in SmsAdapter
  - **spec_ref**: REQ-004
  - **files**: `lib/Service/SmsAdapter.php`
  - **logic**: Sort active `channel_provider` rows by priority; try each in order on transient (5xx) error; persist message with `deliveryStatus: failed` and admin notification if all fail
  - **acceptance_criteria**:
    - GIVEN both providers return 5xx
    - WHEN the adapter exhausts retries
    - THEN the message is persisted as `failed`, conversation gets a system note, and admin is notified

- [x] 3.4 Implement caller-pinned provider selection (providerHint parameter)
  - **spec_ref**: REQ-004
  - **files**: `lib/Service/SmsAdapter.php`
  - **logic**: If `providerHint` is provided and that provider exists and is active, use it directly without failover
  - **acceptance_criteria**:
    - GIVEN `providerHint: 'cm-com'` is passed
    - WHEN the send runs
    - THEN CM.com is used directly (no failover)

- [x] 3.5 Implement inbound webhook handling in SmsAdapter (REQ-003)
  - **spec_ref**: REQ-003
  - **files**: `lib/Service/SmsAdapter.php`, method `handleInboundWebhook(webhookData, providerId)`
  - **logic**: Validate signature; extract body, sender number; find or create contact; find or create conversation; persist message; fire event
  - **acceptance_criteria**:
    - GIVEN an inbound SMS webhook
    - WHEN the adapter processes it
    - THEN the message is persisted and routed to KCC

---

## 4. Consent Service (REQ-005)

- [x] 4.1 Create `lib/Service/ConsentService.php` with enforcement and recording logic
  - **spec_ref**: REQ-005
  - **files**: `lib/Service/ConsentService.php`
  - **methods**: `canSend(contact, channel)`, `recordOptOut(contact, channel, source, evidence)`, `recordOptIn(contact, channel, source, evidence)`
  - **acceptance_criteria**:
    - GIVEN a contact with `opted-out` consent for SMS
    - WHEN `canSend()` is called
    - THEN it returns false
    - AND any send attempt returns 403 `consentMissing`

- [x] 4.2 Implement automatic STOP keyword detection in webhook handlers
  - **spec_ref**: REQ-005
  - **files**: `lib/Service/WhatsAppAdapter.php`, `lib/Service/SmsAdapter.php`
  - **logic**: In inbound webhook handling, check message body for exact match "STOP", "STOPALL", "UITSCHRIJVEN" (case-insensitive); if match, call `ConsentService::recordOptOut(...)` and send acknowledgement template
  - **acceptance_criteria**:
    - GIVEN an inbound message body "stop" (lowercase)
    - WHEN the adapter processes it
    - THEN a consent_record is written with `state: opted-out, source: keyword-stop`
    - AND an acknowledgement template is sent

- [x] 4.3 Implement opt-in reactivation (GIVEN opted-out contact replies "JA" to opt-in template)
  - **spec_ref**: REQ-005
  - **files**: `lib/Service/ConsentService.php`
  - **logic**: When a consent record exists with `opted-out` and contact replies with opt-in keyword, create new consent_record with `opted-in` (do not overwrite; keep history)
  - **acceptance_criteria**:
    - GIVEN an opted-out contact
    - WHEN they reply "JA" to the system's opt-in template
    - THEN a new `opted-in` consent_record is created
    - AND the prior opt-out remains in history for audit

- [x] 4.4 Ensure `consent_record` is retained for GDPR erasure compliance
  - **spec_ref**: REQ-005
  - **files**: `lib/Service/ClientManagementIntegration.php`
  - **logic**: When a contact is deleted (GDPR erasure request), ensure that associated `consent_record` rows are also deleted (or anonymized)
  - **acceptance_criteria**:
    - GIVEN a contact deletion request
    - WHEN the deletion completes
    - THEN all associated consent_records are deleted

---

## 5. Budget Service (REQ-006, REQ-007)

- [x] 5.1 Create `lib/Service/BudgetService.php` with enforcement and tracking logic
  - **spec_ref**: REQ-006, REQ-007
  - **files**: `lib/Service/BudgetService.php`
  - **methods**: `canSend(tenantId, providerId, estimatedCostEur)`, `recordSend(tenantId, providerId, costEur)`, `recordAlert(tenantId, providerId)`
  - **acceptance_criteria**:
    - GIVEN a monthly budget of 5.000 messages with `hardStop: true`
    - WHEN `canSend()` is called after 4.999 sends
    - THEN it returns true for the 5.000th send
    - AND false for the 5.001st

- [x] 5.2 Implement budget enforcement in WhatsAppAdapter and SmsAdapter send methods
  - **spec_ref**: REQ-006
  - **files**: `lib/Service/WhatsAppAdapter.php`, `lib/Service/SmsAdapter.php`
  - **logic**: Before calling provider API, call `BudgetService::canSend(...)` with estimated cost; if false, return 403 `budgetExceeded`
  - **acceptance_criteria**:
    - GIVEN a hard-stop budget is exceeded
    - WHEN send is attempted
    - THEN it returns 403 and no provider call is made

- [x] 5.3 Implement alert-only budget (soft-limit) with one-per-period notification
  - **spec_ref**: REQ-006
  - **files**: `lib/Service/BudgetService.php`
  - **logic**: If `hardStop: false` and `currentPeriodMessages > alertThresholdPct * maxMessages`, fire admin notification exactly once per period (use IAppConfig flag to track)
  - **acceptance_criteria**:
    - GIVEN a soft-limit budget with `alertThresholdPct: 0.8` and 800 of 1.000 sent
    - WHEN the 800th message is sent
    - THEN an alert fires
    - AND subsequent sends in the same period do NOT re-alert

- [x] 5.4 Implement budget period reset logic
  - **spec_ref**: REQ-006
  - **files**: `lib/BackgroundJob/BudgetPeriodResetJob.php`
  - **logic**: Daily job that checks all `message_send_budget` rows; if `periodResetAt` has passed, reset `currentPeriodMessages` and `currentPeriodCostEur` to 0 and advance `periodResetAt`
  - **acceptance_criteria**:
    - GIVEN a monthly budget with `periodResetAt: 2026-06-01`
    - WHEN the reset job runs on 2026-06-01
    - THEN `currentPeriodMessages` and `currentPeriodCostEur` reset to 0
    - AND `periodResetAt` is set to 2026-07-01

- [x] 5.5 Implement cost capture from provider webhooks (REQ-007)
  - **spec_ref**: REQ-007
  - **files**: `lib/Service/CostCaptureService.php`, `lib/Service/ExchangeRateService.php`
  - **logic**: When delivery webhook arrives, extract cost if provided; convert to EUR using daily ECB rate if needed; if rate unavailable, persist in source currency with reconciliation flag; estimate cost from price table if not provided
  - **acceptance_criteria**:
    - GIVEN a Twilio webhook with `Price: 0.0075 USD`
    - WHEN processed with ECB rate EUR/USD 1.08
    - THEN `message.costEur` is set to approximately 0.0069 EUR
    - AND Meta (no cost in webhook) triggers estimation from price table

- [x] 5.6 Implement cost estimation fallback for providers without cost data (Meta)
  - **spec_ref**: REQ-007
  - **files**: `lib/Service/CostEstimationService.php`
  - **logic**: Create static price table mapping (category, country) to estimated cost; use category and destination country from message template to look up cost
  - **acceptance_criteria**:
    - GIVEN a Meta message sent to a NL contact in the `utility` category
    - WHEN the webhook arrives without cost
    - THEN cost is estimated from the price table (e.g., €0.012 per message)
    - AND `costEstimated: true` is stored in metadata

---

## 6. Webhook Processing & Routing (REQ-003)

- [x] 6.1 Register Meta, Twilio, MessageBird webhook handlers in `openconnector` config
  - **spec_ref**: Design § Decisions § Webhook Signature Verification
  - **files**: `openconnector` config (external app)
  - **providers**: `meta-whatsapp`, `twilio-whatsapp`, `twilio-sms`, `messagebird-sms`
  - **acceptance_criteria**:
    - GIVEN the app is updated with new provider types
    - WHEN `openconnector` is configured with webhook endpoints
    - THEN webhooks from these providers are routed to pipelinq handlers

- [x] 6.2 Create webhook queue handler in pipelinq (internal schema `webhook_queue`)
  - **spec_ref**: Design § Decisions § Webhook Signature Verification
  - **files**: `lib/BackgroundJob/WebhookProcessorJob.php`
  - **logic**: Consume queued webhooks from `openconnector`; route to `WhatsAppAdapter::handleInboundWebhook()` or `SmsAdapter::handleInboundWebhook()` based on provider type
  - **acceptance_criteria**:
    - GIVEN a webhook is written to the internal queue
    - WHEN the processor job runs
    - THEN the webhook is routed to the correct adapter

- [x] 6.3 Implement placeholder contact creation on first inbound (REQ-003)
  - **spec_ref**: REQ-003
  - **files**: `lib/Service/WhatsAppAdapter.php`, `lib/Service/SmsAdapter.php`
  - **logic**: In `handleInboundWebhook()`, if contact matching fails, create a placeholder contact with phone number and channel metadata via `ObjectService`
  - **acceptance_criteria**:
    - GIVEN an inbound from an unknown phone number
    - WHEN the adapter processes it
    - THEN a placeholder contact is created in `client-management`
    - AND the conversation is assigned to the unassigned queue

---

## 7. Background Jobs

- [x] 7.1 Create `lib/BackgroundJob/TemplateApprovalSyncJob.php` (hourly sync of template status)
  - **spec_ref**: REQ-009, Design § Decisions § Template Sync as Background Job
  - **files**: `lib/BackgroundJob/TemplateApprovalSyncJob.php`, register in `appinfo/info.xml`
  - **interval**: 3600 seconds (1 hour)
  - **dependencies**: `TemplateApprovalSyncService`
  - **acceptance_criteria**:
    - GIVEN templates in pending state
    - WHEN the job runs
    - THEN Meta status is queried and local records are updated

- [x] 7.2 Create `lib/BackgroundJob/BudgetPeriodResetJob.php` (daily reset of budget counters)
  - **spec_ref**: REQ-006, Design § Decisions § Budget Enforcement Before Send
  - **files**: `lib/BackgroundJob/BudgetPeriodResetJob.php`, register in `appinfo/info.xml`
  - **interval**: 86400 seconds (1 day), run early morning (e.g., 02:00 UTC)
  - **acceptance_criteria**:
    - GIVEN monthly budgets
    - WHEN the reset job runs at month boundary
    - THEN counters are reset and period dates advance

- [x] 7.3 Create `lib/BackgroundJob/CostReconciliationJob.php` (daily reconciliation of non-EUR costs)
  - **spec_ref**: REQ-007
  - **files**: `lib/BackgroundJob/CostReconciliationJob.php`, register in `appinfo/info.xml`
  - **interval**: 86400 seconds
  - **logic**: Find `message` rows with `costCurrencyPending: true`; fetch current ECB rates; convert and update
  - **acceptance_criteria**:
    - GIVEN messages with non-EUR costs pending conversion
    - WHEN the job runs
    - THEN costs are converted and `costCurrencyPending` is cleared

---

## 8. Tests

- [x] 8.1 Add unit tests for `WhatsAppAdapter`
  - **spec_ref**: REQ-001, REQ-002, REQ-003
  - **files**: `tests/Unit/Service/WhatsAppAdapterTest.php`
  - **test_cases**: 
    - Template send with matching parameters (REQ-001)
    - Template send with mismatched parameters (REQ-001)
    - Free-form send within session window (REQ-002)
    - Free-form send outside session window (REQ-002)
    - No session, require template (REQ-002)
    - Inbound webhook with valid signature (REQ-003)
    - Inbound webhook with invalid signature (REQ-003)
    - Inbound from unknown contact creates placeholder (REQ-003)

- [x] 8.2 Add unit tests for `SmsAdapter`
  - **spec_ref**: REQ-004, REQ-003
  - **files**: `tests/Unit/Service/SmsAdapterTest.php`
  - **test_cases**:
    - Send with priority failover (REQ-004)
    - All providers fail (REQ-004)
    - Provider hint pins specific provider (REQ-004)
    - Inbound SMS webhook (REQ-003)

- [x] 8.3 Add unit tests for `ConsentService`
  - **spec_ref**: REQ-005
  - **files**: `tests/Unit/Service/ConsentServiceTest.php`
  - **test_cases**:
    - canSend returns false for opted-out contact
    - STOP keyword triggers opt-out
    - Opted-out contact replies JA triggers opt-in (non-destructive history)

- [x] 8.4 Add unit tests for `BudgetService`
  - **spec_ref**: REQ-006, REQ-007
  - **files**: `tests/Unit/Service/BudgetServiceTest.php`
  - **test_cases**:
    - canSend respects hard-stop limit
    - canSend allows soft-limit with alert
    - Period reset advances dates and resets counters
    - Cost capture and conversion (EUR, non-EUR, estimated)

- [x] 8.5 Add integration tests for full send flow (WhatsApp + Consent + Budget)
  - **spec_ref**: REQ-001, REQ-005, REQ-006
  - **files**: `tests/Integration/WhatsAppSendFlowTest.php`
  - **test_cases**:
    - Send blocked by consent (REQ-005)
    - Send blocked by budget (REQ-006)
    - Send succeeds with both checks passing
    - Template status sync updates UI pickers (REQ-009)

- [x] 8.6 Add integration tests for inbound webhook flow (all adapters)
  - **spec_ref**: REQ-003
  - **files**: `tests/Integration/InboundWebhookFlowTest.php`
  - **test_cases**:
    - Meta webhook creates message and routes to KCC
    - SMS webhook creates message and routes to KCC
    - STOP keyword triggers opt-out
    - Unknown contact creates placeholder and lands in unassigned queue

- [x] 8.7 Add integration tests for media attachment handling
  - **spec_ref**: REQ-008
  - **files**: `tests/Integration/MediaAttachmentTest.php`
  - **test_cases**:
    - Inbound image downloaded, scanned, stored
    - Antivirus scan quarantine (mediaQuarantined: true)
    - Outbound media uploaded to provider
    - Size validation before upload

---

## 9. Documentation & Configuration

- [x] 9.1 Create admin documentation for provider setup (Meta, Twilio, MessageBird, CM.com)
  - **spec_ref**: Proposal § In Scope
  - **files**: `docs/provider-setup.md` or similar
  - **content**: Step-by-step for configuring each provider, credentials, webhook URLs

- [x] 9.2 Create agent documentation for messaging UI and session-window behavior
  - **spec_ref**: REQ-002, Proposal § Target Users
  - **files**: `docs/messaging-guide.md` or similar
  - **content**: How to send WhatsApp templates vs free-form, STOP keyword handling, budget visibility

- [x] 9.3 Update Pipelinq README to mention WhatsApp and SMS support
  - **spec_ref**: Proposal § Summary
  - **files**: `README.md`
  - **content**: Mention new channel adapters and integrations

---

## 10. Final Checks

- [x] 10.1 Run all tests and verify coverage is >= 80% for new code
  - **acceptance_criteria**:
    - All unit and integration tests pass
    - Coverage report shows >= 80% for new classes

- [x] 10.2 Verify GDPR compliance (consent audit trail, erasure hooks)
  - **spec_ref**: REQ-005, Proposal § Dependencies
  - **acceptance_criteria**:
    - Consent records are retained for audit
    - Deletion of a contact triggers deletion of associated consent records
    - Evidence field contains all required audit data

- [x] 10.3 Verify regulatory compliance (Telecommunicatiewet, DDMA, opt-out keywords)
  - **spec_ref**: Specs § Standards & Compliance
  - **acceptance_criteria**:
    - STOP, STOPALL, UITSCHRIJVEN keywords are recognized (case-insensitive)
    - Opt-out is automatic and auditable
    - Attempts to send to opted-out contact fail with clear error

- [x] 10.4 Load-test budget enforcement and media download (5-minute Meta expiry window)
  - **spec_ref**: REQ-006, REQ-008
  - **acceptance_criteria**:
    - 10.000 concurrent sends do not exceed budget
    - Media downloads complete within 4.5 minutes (5-minute window with margin)

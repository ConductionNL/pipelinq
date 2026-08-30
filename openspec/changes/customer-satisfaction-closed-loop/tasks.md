# Tasks: customer-satisfaction-closed-loop

## 1. Data Layer

- [ ] 1.1 Register `surveyInvitation` schema in OpenRegister
  - **spec_ref**: `specs/customer-satisfaction/spec.md#requirement-survey-invitation-schema-registration`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Schema defines: token, surveyRef, contactRef, linkedEntityType, linkedEntityId, channel, status, sentAt, respondedAt, responseRef, expiresAt, suppressionReason, dispatchRuleId
    - Status enum: scheduled, sent, responded, expired, suppressed, failed
    - Schema added to the register array; ID mapping added to SchemaMapService; config key added to SettingsService

- [ ] 1.2 Extend `surveyResponse` with `invitationRef` and `contact` with `surveyOptOut`
  - **spec_ref**: `specs/customer-satisfaction/spec.md#requirement-survey-invitation-schema-registration`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - `surveyResponse.invitationRef` optional string FK
    - `contact.surveyOptOut` boolean default false
    - Existing objects remain valid (additive change only)

- [ ] 1.3 Add x-openregister-notifications schema rule for detractor responses (ADR-031)
  - **spec_ref**: `specs/customer-satisfaction/spec.md#requirement-detractor-closed-loop-follow-up`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Notification rule on `surveyResponse` creation with detractor condition, canonical dialect (no legacy dialect, no imperative dispatch)
    - Notification targets the follow-up task assignee

## 2. Dispatch Rules & Settings

- [ ] 2.1 Dispatch-rule storage in SettingsService + admin API
  - **spec_ref**: `specs/customer-satisfaction/spec.md#requirement-configurable-survey-dispatch-rules`
  - **files**: `lib/Service/SettingsService.php`, `lib/Controller/SettingsController.php`
  - **acceptance_criteria**:
    - Rules persisted as JSON array: id, enabled, trigger {entityType, statusEquals, channelEquals?}, surveyRef, channel, delayMinutes, cooldownDays, expiryDays
    - CRUD endpoints admin-gated; validation rejects unknown entityType/channel
    - Default detractor rating threshold + default assignee settings included

- [ ] 2.2 Dispatch-rule management UI in survey settings
  - **spec_ref**: `specs/customer-satisfaction/spec.md#requirement-configurable-survey-dispatch-rules`
  - **files**: `src/views/surveys/*`, `src/modals/`
  - **acceptance_criteria**:
    - Rules list with enable/disable toggle; create/edit modal in `src/modals/` (modal-isolation gate)
    - NcSelect fields carry `inputLabel`; English i18n source keys; nl translations

## 3. Dispatch Engine

- [ ] 3.1 SurveyDispatchService: rule matching + guard chain + invitation creation
  - **spec_ref**: `specs/customer-satisfaction/spec.md#requirement-automated-invitation-dispatch-on-interaction-completion`
  - **files**: `lib/Service/SurveyDispatchService.php`, `lib/Listener/`
  - **acceptance_criteria**:
    - Listens to interaction terminal-status events on the existing OR event stream
    - Guard order: contact resolvable → opt-out → cooldown (per-contact, any survey) → channel address present
    - Failed guards persist `suppressed` invitations with reasons cooldown/opt-out/no-channel-address/channel-unavailable
    - Passing matches create `scheduled` invitations with unique UUID token and expiresAt

- [ ] 3.2 Background dispatch job (delayed send + channel hand-off)
  - **spec_ref**: `specs/customer-satisfaction/spec.md#requirement-automated-invitation-dispatch-on-interaction-completion`
  - **files**: `lib/Cron/SurveyInvitationDispatchJob.php`, `lib/AppInfo/Application.php`
  - **acceptance_criteria**:
    - Job registered via the valid bootstrap job-registration pattern (NOT IRegistrationContext::registerJob — see 2026-06 jobs sweep)
    - Sends `scheduled` invitations whose delay elapsed; email via existing email-action infrastructure; whatsapp/sms via channel adapter when configured, else fallback/suppress per rule flag
    - Hand-off failure → status `failed`; success → `sent` + sentAt; triggering interaction save is never blocked

- [ ] 3.3 Cooldown + opt-out unit coverage
  - **spec_ref**: `specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out`
  - **files**: `tests/unit/Service/SurveyDispatchServiceTest.php`
  - **acceptance_criteria**:
    - Tests: cooldown suppression across different surveys, opt-out suppression, guard ordering, suppression persisted with reason

## 4. Public Response Path

- [ ] 4.1 Invitation-token routes on PublicSurveyController
  - **spec_ref**: `specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection`
  - **files**: `lib/Controller/PublicSurveyController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - `showInvitation`/`submitInvitation` with `#[PublicPage]` + brute-force protection matching V1
    - Validates status `sent` and unexpired; responded/expired tokens rejected (single-use); expired renders "survey closed"
    - Submission creates `surveyResponse` with invitationRef + entity/contact linkage and flips invitation to `responded`
    - V1 per-survey token routes unchanged

- [ ] 4.2 Opt-out control on the public form
  - **spec_ref**: `specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out`
  - **files**: `src/views/surveys/PublicSurveyForm.vue`
  - **acceptance_criteria**:
    - Checkbox "Don't send me satisfaction surveys again" (English i18n key)
    - Submission with the flag sets `contact.surveyOptOut = true` server-side

## 5. Follow-Up & Analytics

- [ ] 5.1 DetractorFollowUpService
  - **spec_ref**: `specs/customer-satisfaction/spec.md#requirement-detractor-closed-loop-follow-up`
  - **files**: `lib/Service/DetractorFollowUpService.php`
  - **acceptance_criteria**:
    - Classification: NPS ≤ 6, or 1–5 rating ≤ configured threshold (default 2)
    - Creates My Work follow-up task referencing the response; assignee = client owner, fallback default assignee
    - No imperative notification dispatch (ADR-031 rule covers notification)
    - Promoter/passive responses produce no task

- [ ] 5.2 Response-rate block in SurveyAnalytics
  - **spec_ref**: `specs/customer-satisfaction/spec.md#requirement-response-rate-analytics`
  - **files**: `src/views/surveys/SurveyAnalytics.vue`, `src/store/modules/surveyStore.js`
  - **acceptance_criteria**:
    - Sent / responded / response-rate per survey, channel, period; suppressed + failed shown separately, excluded from denominator

- [ ] 5.3 SatisfactionAggregationService + customer-360 panel
  - **spec_ref**: `specs/customer-360/spec.md#requirement-per-client-satisfaction-panel`
  - **files**: `lib/Service/SatisfactionAggregationService.php`, customer-360 client view component
  - **acceptance_criteria**:
    - Per-client NPS, average rating, count, 90-day trend, latest 3 verbatims
    - Empty state when the client has no responses

## 6. Seed, Docs & Verification

- [ ] 6.1 Seed example dispatch rule
  - **spec_ref**: `specs/customer-satisfaction/spec.md#requirement-configurable-survey-dispatch-rules`
  - **files**: `lib/Settings/pipelinq_register.json` (seed), settings defaults
  - **acceptance_criteria**:
    - One disabled-by-default example rule: contactmoment closed → KTO survey, email, cooldown 30 days

- [ ] 6.2 Update feature docs
  - **spec_ref**: `specs/customer-satisfaction/spec.md#requirement-feature-documentation-conformance`
  - **files**: `docs/Features/customer-satisfaction.md`, `docs/Features/terugbel-taakbeheer.md`
  - **acceptance_criteria**:
    - customer-satisfaction.md: V1 marked implemented, closed-loop scope described with actual status
    - terugbel-taakbeheer.md re-pointed at `callback-management`

- [ ] 6.3 Tests + gates
  - **spec_ref**: all
  - **files**: `tests/unit/`, `tests/e2e/`, `tests/integration/`
  - **acceptance_criteria**:
    - PHPUnit for dispatch/follow-up/aggregation services; Vitest for new store getters
    - Playwright UI coverage for settings UI, analytics block, customer-360 panel, public-form opt-out; API assertions in Newman (not Playwright)
    - `composer check:strict` green; 14 hydra gates pass; i18n English keys + nl catalog complete

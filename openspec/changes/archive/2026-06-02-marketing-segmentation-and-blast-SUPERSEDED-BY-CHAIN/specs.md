# Specs: Marketing Segmentation and Blast

**Feature tier**: MVP
**Spec refs**: `openspec/architecture/adr-000-data-model.md`
**Standards**: GDPR Art. 6 (lawful basis), GDPR Art. 17 (right to be forgotten), CAN-SPAM (physical address), TCPA (SMS opt-in), DMARC/SPF/DKIM (email authentication)

---

## REQ-MSB-001: Segment Builder Composes Rule Trees

The segment builder MUST allow marketers to construct rule trees using AND/OR logic with leaf predicates (field, operator, value) and serialize them as JSON. Each predicate MUST be validated against the entity schema before save.

**Feature tier**: MVP
**Spec ref**: Marketing Segmentation and Blast (context-brief)
**Files**: `pipelinq/src/components/SegmentBuilder.vue`, `lib/Service/SegmentService.php`

### Scenario REQ-MSB-001-01: Rule tree validated on save

- GIVEN a marketing manager opens the segment builder for entityType "contact"
- WHEN they construct the rule `industry = "gemeente" AND (employees > 50 OR annual_revenue > 5000000) AND last_contact_moment < 90 days`
- AND they click "Save segment"
- THEN the system MUST serialize the rule tree as JSON, validate each leaf predicate:
  - field "industry" exists in contact schema ✓
  - operator "equals" is valid for string type ✓
  - value "gemeente" is a string ✓
- AND the system MUST call `SegmentService.validateRules()` to verify all paths
- AND on validation success, the system MUST save a Segment object with the rule tree
- AND on validation failure, MUST display field-level errors and block save

### Scenario REQ-MSB-001-02: Estimated size shown before commit

- GIVEN a rule tree has been validated
- WHEN the marketing manager views the segment detail
- THEN the system MUST display "Estimated members: 2,341" (or count from `SegmentService.estimateSize()`)
- AND the estimate MUST refresh within 1 second if the manager edits the rules
- AND the estimate MUST be cached for 1 hour to avoid repeated full-table scans

### Scenario REQ-MSB-001-03: Operators validated per field type

- GIVEN a contact schema with fields: `industry` (string), `employees` (integer), `last_contact_moment` (date)
- WHEN the manager attempts to create a predicate `industry > 50` (invalid: string field with numeric operator)
- THEN the rule composer MUST reject the predicate with error "Operator > is not valid for string fields"
- AND the save button MUST be disabled until the error is resolved

---

## REQ-MSB-002: Segments Are Live, Not Frozen Lists

A Segment MUST be evaluated dynamically at blast-send time, not materialized as a static contact list at save time. New Contacts matching the rules MUST be auto-included in future Blasts.

**Feature tier**: MVP
**Spec ref**: context-brief / REQ-002
**Files**: `lib/Service/SegmentService.php`, `lib/Service/BlastService.php`

### Scenario REQ-MSB-002-01: New contact auto-included in next blast

- GIVEN a Segment with rule `industry = "gemeente"` saved at 2026-01-01
- WHEN a new Contact "De Bilt Municipality" with `industry = "gemeente"` is created on 2026-02-15
- AND a Blast targeting the same Segment is sent on 2026-02-16
- THEN the Blast MUST include "De Bilt Municipality" in its BlastDelivery queue
- AND the segment query MUST NOT have been materialized as a static list on 2026-01-01

### Scenario REQ-MSB-002-02: Contact deletion removes from future blasts

- GIVEN a Contact "Harlingen City" is in an active Segment with rule `industry = "gemeente"`
- WHEN the Contact is deleted on 2026-03-01
- THEN the Contact MUST NOT appear in the next Blast sent to that Segment on 2026-03-15
- AND the estimated size `SegmentService.estimateSize()` MUST reflect the deletion

---

## REQ-MSB-003: Blast Cannot Send Without Lawful Basis

A Blast MUST NOT be sent to any Contact that lacks a ConsentRecord for the target channel with lawful-basis set. The system MUST block the send and offer remediation options.

**Feature tier**: MVP
**Spec ref**: context-brief / REQ-003, GDPR Art. 6
**Files**: `lib/Service/ComplianceService.php`, `pipelinq/src/views/blasts/BlastForm.vue`

### Scenario REQ-MSB-003-01: Send blocked with missing consent list

- GIVEN a Segment matching 1,000 Contacts
- AND 12 Contacts have no ConsentRecord for the email channel
- WHEN a marketing manager clicks "Send" on a Blast to this Segment
- THEN the system MUST call `ComplianceService.checkSegmentCompliance()` and find 12 missing
- AND the send MUST be blocked with error modal showing:
  - "12 contacts in this segment do not have email consent. Options:"
  - List of 12 contact IDs
  - Buttons: "Skip these contacts and send" | "Request consent via double-opt-in" | "Cancel"
- AND the Blast MUST remain in draft status

### Scenario REQ-MSB-003-02: Send proceeds after addressing missing consent

- GIVEN the send-blocked modal from REQ-MSB-003-01
- WHEN the manager clicks "Skip these contacts and send"
- THEN the system MUST create BlastDelivery rows only for the 988 Contacts with valid consent
- AND the Blast MUST transition to "sending" status
- AND the 12 skipped Contacts MUST be logged in the Blast audit trail

### Scenario REQ-MSB-003-03: Imported contacts default to lawful-basis "imported"

- GIVEN Contacts are bulk-imported from an external CRM with consentSource = "imported"
- WHEN a Blast is sent to a Segment containing these Contacts
- THEN lawful-basis "imported" MUST NOT satisfy consent gating (strict interpretation: imported data is not explicit consent)
- AND the Blast send MUST be blocked with the missing-consent error
- AND the audit log MUST note "lawful-basis 'imported' does not permit marketing sends"

---

## REQ-MSB-004: Unsubscribe Footer Enforced on Email Templates

Every email CampaignTemplate MUST contain the unsubscribe token `{{unsubscribe_link}}` and a physical-address block. Save MUST be rejected if either is missing.

**Feature tier**: MVP
**Spec ref**: context-brief / REQ-004, CAN-SPAM, e-Privacy Directive 2002/58/EC
**Files**: `lib/Service/ComplianceService.php`, `pipelinq/src/views/templates/TemplateForm.vue`

### Scenario REQ-MSB-004-01: Save rejected if unsubscribe token missing

- GIVEN a CampaignTemplate with channel = "email"
- WHEN a marketer attempts to save a template with bodyHtml that does NOT contain `{{unsubscribe_link}}`
- THEN save MUST be rejected with field error on bodyHtml:
  - "Email templates must include the {{unsubscribe_link}} token"
- AND the template MUST NOT be persisted

### Scenario REQ-MSB-004-02: Save rejected if physical address missing

- GIVEN a CampaignTemplate with channel = "email"
- WHEN the marketer saves a template bodyHtml that contains `{{unsubscribe_link}}` but no physical-address block (e.g., street address, city, postal code)
- THEN save MUST be rejected with error:
  - "Physical mailing address required in footer for CAN-SPAM compliance"
- AND MUST highlight the footer region where address should be added

### Scenario REQ-MSB-004-03: Default template includes both tokens

- GIVEN a marketer clicks "New Email Template"
- WHEN the template form loads
- THEN the default bodyHtml MUST pre-populate with:
  ```
  {{greeting}}
  
  ...content...
  
  ---
  {{senderName}} | {{senderEmail}}
  {{physicalAddress}}
  
  {{unsubscribe_link}}
  ```
- AND if the marketer deletes either token, a visual warning MUST appear

### Scenario REQ-MSB-004-04: SMS templates do not require unsubscribe footer

- GIVEN a CampaignTemplate with channel = "sms"
- WHEN the marketer saves the template
- THEN the unsubscribe-token and physical-address validations MUST NOT apply
- AND save MUST succeed if bodyText contains valid Mustache variables

---

## REQ-MSB-005: Unsubscribe Propagates Within Minutes

When a recipient clicks the unsubscribe link or an unsubscribe webhook is received from the email provider, the ConsentRecord MUST be withdrawn within 60 seconds. All queued BlastDelivery rows for that contact MUST be transitioned to `unsubscribed-before-send` and skipped at dispatch time.

**Feature tier**: MVP
**Spec ref**: context-brief / REQ-005, GDPR Art. 7
**Files**: `lib/Service/ComplianceService.php`, `lib/BackgroundJob/WebhookProcessorJob.php`

### Scenario REQ-MSB-005-01: Webhook unsubscribe updates consent within 60s

- GIVEN a BlastDelivery with status "delivered" for Contact "Anna Vermeer"
- WHEN SendGrid webhook POST to `/webhook/sendgrid` with event `{ event: "unsubscribe", email: "anna@example.nl", timestamp: 1700000000 }`
- THEN within 60 seconds:
  - ConsentRecord for anna@example.nl channel "email" MUST have `withdrawnAt` set to the event timestamp
  - `withdrawnReason` MUST be set to "user-unsubscribed"
  - All BlastDelivery rows for this Contact with status "queued" in any in-flight Blasts MUST transition to "unsubscribed-before-send"
- AND no BlastDelivery for this Contact may be dispatched to the email provider

### Scenario REQ-MSB-005-02: Soft unsubscribe via link click

- GIVEN a BlastDelivery with a click-tracked unsubscribe link: `/api/blast/:blastId/unsubscribe?contact=:contactId&token=xyz`
- WHEN a recipient clicks the link
- THEN the API MUST:
  - Validate the contact + blast + token match
  - Call `ComplianceService.recordConsentWithdrawal()` with reason "user-unsubscribed"
  - Return a confirmation page: "You have been unsubscribed from this mailing list"
  - Set HTTP status 200

### Scenario REQ-MSB-005-03: Future blasts exclude unsubscribed contact

- GIVEN a Contact with ConsentRecord `withdrawnAt` set (unsubscribed)
- WHEN the segment evaluation for the next Blast runs
- THEN `SegmentService.evaluateRules()` MUST check ConsentRecord.withdrawnAt and return false for email channel
- AND the Contact MUST NOT be queued in the new Blast

---

## REQ-MSB-006: A/B Test Splits Segment and Reports Significance

When a Blast is configured as A/B with `abSplitPercent`, the segment MUST be split deterministically (same contact always gets same variant), and once each arm has ≥500 delivered and 24 hours have elapsed, a chi-square test MUST report click-rate significance at p<0.05.

**Feature tier**: MVP
**Spec ref**: context-brief / REQ-006
**Files**: `lib/Service/BlastService.php`, `pipelinq/src/views/blasts/PerformanceDashboard.vue`

### Scenario REQ-MSB-006-01: Segment split deterministically per contact

- GIVEN a Blast configured as A/B with `abSplitPercent: 50` and a Segment of 4,000 Contacts
- WHEN the Blast is sent
- THEN:
  - A parent Blast MUST be created with `abSplitPercent: 50`, `status: "draft"`
  - A child B-variant Blast MUST be created with `abVariantOf: parent.id`
  - 2,000 BlastDeliveries MUST be queued for variant A
  - 2,000 BlastDeliveries MUST be queued for variant B
  - Deterministic assignment: `variant = hash(contactId) % 100 < abSplitPercent ? "B" : "A"`
  - Same contact querying same segment again MUST receive same variant (hash is deterministic)

### Scenario REQ-MSB-006-02: Significance test once N≥500 and 24h elapsed

- GIVEN Blast variants A and B both have ≥500 BlastDeliveries with status "delivered"
- AND ≥24 hours have elapsed since first send
- WHEN the PerformanceDashboard is opened
- THEN the system MUST compute:
  - Variant A: click_count = 45, delivered = 500, click_rate = 9.0%
  - Variant B: click_count = 62, delivered = 500, click_rate = 12.4%
  - Chi-square statistic = 3.24, p-value = 0.0719
  - Display: "Variant B click-rate is 12.4% vs A at 9.0%. Difference is not significant (p=0.072)"
- AND once p < 0.05, display: "Variant B click-rate is significantly higher (p=0.042) ✓"

### Scenario REQ-MSB-006-03: Test unavailable if N<500

- GIVEN Variant A has 320 delivered, Variant B has 285 delivered (total < 500 each)
- WHEN the PerformanceDashboard is opened
- THEN the system MUST display:
  - "Test results not yet available (need ≥500 per variant, currently A: 320 / B: 285)"
  - No significance calculation performed

---

## REQ-MSB-007: Bounce Handling Protects Sender Reputation

Hard bounces MUST immediately withdraw consent; soft bounces MUST count toward a threshold (default 5 consecutive) before withdrawal. Bounced Contacts MUST be excluded from all future email Blasts.

**Feature tier**: MVP
**Spec ref**: context-brief / REQ-007, GDPR Art. 7
**Files**: `lib/Service/ComplianceService.php`, `lib/BackgroundJob/WebhookProcessorJob.php`

### Scenario REQ-MSB-007-01: Hard bounce withdraws consent immediately

- GIVEN a BlastDelivery with status "sent"
- WHEN SendGrid webhook POST event `{ event: "bounce", bounceType: "hard", email: "invalid@example.nl", ... }`
- THEN:
  - BlastDelivery MUST transition to `bounced`, `bounceType = "hard"`
  - ConsentRecord for the Contact MUST be updated: `withdrawnAt = now`, `withdrawnReason = "bounce-hard"`
  - Contact MUST be excluded from all future email Segments

### Scenario REQ-MSB-007-02: Soft bounce increments counter, withdrawal after threshold

- GIVEN a Contact with no prior soft-bounce record
- WHEN soft-bounce webhooks are received for the same Contact 5 times
- THEN after the 1st soft bounce: ConsentRecord remains active, counter = 1
- AND after the 5th soft bounce: ConsentRecord `withdrawnAt = now`, `withdrawnReason = "bounce-soft-x5"`
- AND the Contact MUST be excluded from future email Blasts

### Scenario REQ-MSB-007-03: Hard bounce takes precedence over soft

- GIVEN a Contact with soft-bounce counter = 3
- WHEN a hard-bounce webhook is received
- THEN consent MUST be withdrawn immediately with reason "bounce-hard" (not "bounce-soft-x5")
- AND the soft-bounce counter MUST be cleared

---

## REQ-MSB-008: Send Via OpenConnector with Per-Tenant Provider

A Blast MUST specify a `connectorSourceId` (the tenant's configured SendGrid / SES / Twilio account). Send dispatch MUST use openconnector's source-specific send-mail action, never embed provider credentials in Pipelinq code.

**Feature tier**: MVP
**Spec ref**: context-brief / REQ-008, ADR-005 (security)
**Files**: `lib/Service/BlastService.php`, `pipelinq/src/views/blasts/BlastForm.vue`

### Scenario REQ-MSB-008-01: Connector source selected during blast creation

- GIVEN a tenant has configured openconnector source "SendGrid Account - Main" with `connectorSourceId = "source-abc123"`
- WHEN a marketing manager creates a Blast with channel "email"
- THEN the BlastForm MUST display dropdown "Email provider" with options:
  - "SendGrid Account - Main" (selected)
  - "SendGrid Account - Backup"
  - "AWS SES Account"
- AND the selected `connectorSourceId` MUST be stored on the Blast object

### Scenario REQ-MSB-008-02: Dispatch via openconnector send-mail action

- GIVEN a BlastDelivery queued for Contact "Jan de Vries" with email "jan@example.nl"
- WHEN BlastSendJob calls `BlastService.dispatchBlastDeliveries()`
- THEN for each BlastDelivery:
  - Fetch the openconnector source by `connectorSourceId`
  - Call openconnector source action `send-mail` with parameters:
    ```json
    {
      "to": "jan@example.nl",
      "subject": "Q4 Newsletter",
      "html": "<html>...rendered template...</html>",
      "from": "marketing@acme.nl",
      "replyTo": "support@acme.nl"
    }
    ```
  - Wait for response: `{ success: true, messageId: "sg-abc123xyz" }`
  - Store `providerId = "sg-abc123xyz"` on BlastDelivery for webhook tracking

### Scenario REQ-MSB-008-03: Pipelinq code never touches provider credentials

- GIVEN a SendGrid API key configured in openconnector
- WHEN a Blast is sent
- THEN `lib/Service/BlastService.php` and all backend code MUST NOT:
  - Import or reference SendGrid SDK
  - Store, read, or log the API key
  - Construct SendGrid API requests directly
- AND all sends MUST delegate to openconnector via `OCA\OpenConnector\Service\SourceService::executeAction()`

---

## REQ-MSB-009: Revenue Attribution Joins Clicks to Closed Deals

BlastDelivery tracked links MUST carry `utm_campaign=blast-<blastId>&utm_source=pipelinq-blast`. When a recipient clicks and later closes a Deal, an AttributionLink MUST join Blast → Contact → Deal with first-click timestamp and attributed value.

**Feature tier**: MVP
**Spec ref**: context-brief / REQ-009
**Files**: `lib/Service/AttributionService.php`, `pipelinq/src/views/blasts/PerformanceDashboard.vue`

### Scenario REQ-MSB-009-01: Tracked links include UTM parameters

- GIVEN a CampaignTemplate with bodyHtml containing `<a href="https://acme.nl/solutions">View Solutions</a>`
- WHEN the Blast is sent
- THEN the rendered link for each BlastDelivery MUST be rewritten to:
  - `https://acme.nl/solutions?utm_campaign=blast-<blastId>&utm_source=pipelinq-blast&utm_medium=email`
  - Link MUST include recipient identifier for tracking (e.g., `utm_content=contact-<contactId>`)

### Scenario REQ-MSB-009-02: Click event recorded via webhook or tracking pixel

- GIVEN a BlastDelivery with tracked link `https://acme.nl/solutions?utm_campaign=blast-abc123&...`
- WHEN a recipient clicks the link
- THEN:
  - Provider (SendGrid) MUST log the click event
  - Webhook POST to `/webhook/sendgrid` with event `{ event: "click", url: "https://...", timestamp: 1700000000, ... }`
  - System MUST extract utm_campaign and utm_content from URL
  - BlastDelivery `firstClickAt` MUST be recorded
  - `clickedUrls[]` array MUST include the URL

### Scenario REQ-MSB-009-03: Attribution link created when deal closes

- GIVEN:
  - BlastDelivery for Contact "Maria Jansen" with firstClickAt = 2026-02-15 12:00:00
  - Blast "Q4 Gemeente Outreach" with blastId = "blast-abc123"
  - Deal "Municipality Website Redesign" closes as won on 2026-03-20 with amount EUR 45,000
- WHEN the Deal is saved with status "won"
- AND the Deal is linked to Contact "Maria Jansen"
- THEN the system MUST detect the Blast-to-Deal link and create an AttributionLink:
  ```json
  {
    "blastId": "blast-abc123",
    "contactId": "contact-maria-uuid",
    "dealId": "deal-website-uuid",
    "firstClickAt": "2026-02-15T12:00:00Z",
    "closedWonAt": "2026-03-20T14:30:00Z",
    "attributedValue": 45000
  }
  ```

### Scenario REQ-MSB-009-04: Dashboard sums attributed revenue per blast

- GIVEN 3 AttributionLink rows all with blastId = "blast-q4-gemeente"
  - Link 1: attributedValue = EUR 45,000
  - Link 2: attributedValue = EUR 12,000
  - Link 3: attributedValue = EUR 18,500
- WHEN the PerformanceDashboard Attribution tab is opened
- THEN the system MUST display:
  - "Q4 Gemeente Outreach: Attributed value EUR 75,500 from 3 deals"

---

## REQ-MSB-010: Throttle Respects Provider Rate Limits

The sending engine MUST respect per-source rate limits (e.g., SendGrid 100 emails/second) configured in openconnector. Sends to 50,000 contacts at 100/second MUST complete in ~8 minutes 20 seconds. A live progress bar MUST track real-time dispatch status.

**Feature tier**: MVP
**Spec ref**: context-brief / REQ-010
**Files**: `lib/Service/BlastService.php`, `pipelinq/src/views/blasts/BlastMonitor.vue`

### Scenario REQ-MSB-010-01: Rate limit applied per source

- GIVEN a Blast with 50,000 queued BlastDeliveries
- AND openconnector source "SendGrid Main" configured with `sendRateLimit = 100` emails/second
- WHEN BlastSendJob triggers dispatch
- THEN:
  - BlastService MUST read the rate limit from openconnector source config
  - Dispatch MUST batch queued rows (default batch size = 50)
  - For each batch, call openconnector `send-mail` action
  - Wait between batches to maintain 100 emails/second throughput

### Scenario REQ-MSB-010-02: Progress bar shows real-time dispatch count

- GIVEN a Blast mid-send with 50,000 BlastDeliveries
- AND 12,500 rows already dispatched, 37,500 queued
- WHEN a user opens BlastMonitor.vue for this Blast
- THEN the page MUST display:
  - Progress bar: `[████████░░░░░░░░░░░░░░░░░░] 12,500 / 50,000 (25%)`
  - Estimated time remaining: "~6 minutes 15 seconds"
  - Current dispatch rate: "97 emails/sec"

### Scenario REQ-MSB-010-03: Throttle prevents provider rate-limit errors

- GIVEN a Blast configured to send to 50,000 contacts via SendGrid at 100/second
- WHEN BlastSendJob runs for the full duration
- THEN SendGrid MUST NOT return HTTP 429 (rate limit exceeded) errors
- AND all 50,000 BlastDeliveries MUST progress from "queued" → "sent" or "failed" without throttle-related failures

---

## REQ-MSB-011: Seed Data Includes Realistic Examples

The `pipelinq_register.json` MUST include 5+ Segment, 3+ CampaignTemplate, 2+ Blast, 20+ BlastDelivery, 10+ ConsentRecord, and 2+ AttributionLink objects with realistic Dutch values for testing and demo.

**Feature tier**: MVP
**Files**: `lib/Settings/pipelinq_register.json`

### Scenario REQ-MSB-011-01: Segment seed objects

- GIVEN pipelinq_register.json
- THEN `components.objects[]` MUST include ≥5 Segment objects with:
  - Realistic Dutch names: "Gemeente Contact Blast", "Enterprise High-Value", "Inactive Leads", "Retention Newsletter", "Technical Leads"
  - Valid rule trees with AND/OR combinations
  - Varied entityTypes: "contact", "customer"
  - Unique slugs: "segment-gemeente-blast", "segment-enterprise-hv", etc.

### Scenario REQ-MSB-011-02: CampaignTemplate seed objects

- GIVEN pipelinq_register.json
- THEN `components.objects[]` MUST include ≥3 CampaignTemplate objects:
  - Email: "Q4 Product Launch" with subject, bodyHtml (includes `{{unsubscribe_link}}`), senderName, senderEmail
  - Email: "Renewal Reminder" with similar structure
  - SMS: "Appointment Confirmation" with bodyText, no subject or footer

### Scenario REQ-MSB-011-03: Blast seed objects include A/B pair

- GIVEN pipelinq_register.json
- THEN `components.objects[]` MUST include ≥2 Blast objects:
  - Parent: "Q4 Gemeente Outreach - Variant A" with `abSplitPercent: 50`, status "sent", totals with realistic counts
  - Child: "Q4 Gemeente Outreach - Variant B" with `abVariantOf: parent.id`, status "sent"
  - Both linked to the same Segment and Template

### Scenario REQ-MSB-011-04: BlastDelivery seed includes realistic event sequence

- GIVEN pipelinq_register.json with ≥20 BlastDelivery objects
- THEN statuses MUST be mixed: "queued", "sent", "delivered", "bounced", "opened", "clicked", "unsubscribed"
- AND timestamps MUST show realistic progression: sentAt < openedAt < firstClickAt
- AND provider IDs MUST resemble SendGrid format: "sg-abc123xyz..."
- AND clicked URLs MUST include utm parameters: `?utm_campaign=blast-...&utm_source=pipelinq-blast`

### Scenario REQ-MSB-011-05: ConsentRecord seed includes varied states

- GIVEN pipelinq_register.json with ≥10 ConsentRecord objects
- THEN records MUST include:
  - Active consent: `withdrawnAt = null`, varied lawfulBasis ("consent", "contract")
  - Withdrawn unsubscribe: `withdrawnReason = "user-unsubscribed"`
  - Withdrawn bounce-hard: `withdrawnReason = "bounce-hard"`
  - Withdrawn soft-bounce: `withdrawnReason = "bounce-soft-x5"`
  - Varied consentSource: "signup-form", "double-opt-in-email", "contract", "imported"

### Scenario REQ-MSB-011-06: AttributionLink seed shows revenue attribution

- GIVEN pipelinq_register.json with ≥2 AttributionLink objects
- THEN each MUST include:
  - `blastId` linking to a seed Blast
  - `contactId` and `dealId` linking to existing seed objects
  - `firstClickAt` and `closedWonAt` with realistic progression (click first, deal close later)
  - `attributedValue` in EUR (e.g., 45000, 12000, 18500)

---

## REQ-MSB-012: API Endpoints CRUD and Query

All Blast, Segment, CampaignTemplate, ConsentRecord API endpoints MUST support standard CRUD operations with proper authorization and error handling. User identity MUST be derived from `IUserSession`, never trusted from frontend.

**Feature tier**: MVP
**Spec ref**: ADR-005 (security), ADR-015 (common patterns)
**Files**: `lib/Controller/BlastController.php`, `lib/Controller/SegmentController.php`, `lib/Controller/TemplateController.php`

### Scenario REQ-MSB-012-01: GET /api/blasts returns paginated list with filters

- GIVEN a current user with Pipelinq access
- WHEN they GET `/api/blasts?status=sent&page=1&limit=20`
- THEN response MUST be HTTP 200 with JSON body:
  ```json
  {
    "data": [
      { "id": "blast-123", "name": "Q4 Outreach", "status": "sent", "totals": {...} },
      ...
    ],
    "pagination": { "page": 1, "limit": 20, "total": 147 }
  }
  ```
- AND filter by status MUST work: status="draft" returns drafts only

### Scenario REQ-MSB-012-02: POST /api/blasts creates new blast in draft

- GIVEN a valid Segment, Template, and connector source
- WHEN POST `/api/blasts` with body:
  ```json
  {
    "name": "My Test Blast",
    "segmentId": "segment-123",
    "templateId": "template-456",
    "channel": "email",
    "connectorSourceId": "source-789"
  }
  ```
- THEN response MUST be HTTP 201 with new Blast object in draft status

### Scenario REQ-MSB-012-03: Error responses use generic messages

- GIVEN an invalid segmentId in the POST body
- WHEN POST `/api/blasts` with the invalid segmentId
- THEN response MUST be HTTP 400 with error message:
  - "Invalid segment"
  - MUST NOT expose internal details like "Segment UUID 'abc' not found in register"

### Scenario REQ-MSB-012-04: User identity from IUserSession only

- GIVEN a POST request to create a Blast
- AND the request body includes `"createdBy": "admin-user-id"`
- WHEN the controller processes the request
- THEN the system MUST ignore the body's createdBy value
- AND MUST use `IUserSession.getUser().getUID()` to set createdBy (the actual logged-in user)

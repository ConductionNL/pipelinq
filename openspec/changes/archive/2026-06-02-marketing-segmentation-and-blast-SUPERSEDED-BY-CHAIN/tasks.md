# Tasks: Marketing Segmentation and Blast

## Section 0: Deduplication Check

### Task 0.1: Verify no overlap with existing services [MVP]
- **Spec ref**: ADR-012 (deduplication)
- **Files**: Search `lib/Service/`, `openspec/specs/`, existing Pipelinq services
- **Findings**:
  - No prior SegmentService, BlastService, ComplianceService, or AttributionService exists
  - `ObjectService` from OpenRegister reused for all CRUD on Segment, Blast, etc. (no custom CRUD)
  - Webhook processor pattern exists in email-calendar-sync (WebhookProcessor job) — reuse that pattern
  - No overlap with openconnector sources (external, plugin-based)
- [ ] Document deduplication findings in PR description before merging

---

## Section 1: Seed Data [V1]

### Task 1.1: Add Segment seed objects to pipelinq_register.json [V1]
- **Spec ref**: REQ-MSB-011, REQ-MSB-011-01
- **Files**: `lib/Settings/pipelinq_register.json`
- **Acceptance**: 5 Segment objects with realistic Dutch values, varied rule trees
- [ ] Add 5 Segment objects under `components.objects[]` with `@self` envelope (`register: pipelinq`, `schema: segment`, unique slug)
- [ ] Segment 1: "Gemeente Contact Blast" — rule tree: `industry = "gemeente"` (entityType: contact, estimatedSize: 234)
- [ ] Segment 2: "Enterprise High-Value" — rule: `employees > 100 AND annual_revenue > 10000000` (entityType: customer, estimatedSize: 87)
- [ ] Segment 3: "Inactive Leads" — rule: `status = "lead" AND last_activity > "6 months"` (entityType: contact)
- [ ] Segment 4: "Retention Newsletter" — rule: `status = "customer" AND contract_end < "60 days"` (entityType: customer)
- [ ] Segment 5: "Technical Leads" — rule: `tags contains "technical" OR role contains "CTO"` (entityType: contact)
- [ ] Each object has unique slug: "segment-gemeente-blast", "segment-enterprise-hv", etc.
- [ ] Verify rule trees are valid JSON and conform to `{ type: "AND"|"OR", children: [...] }` structure

### Task 1.2: Add CampaignTemplate seed objects to pipelinq_register.json [V1]
- **Spec ref**: REQ-MSB-011-02
- **Files**: `lib/Settings/pipelinq_register.json`
- **Acceptance**: 3 CampaignTemplate objects (2 email, 1 SMS) with enforced unsubscribe tokens
- [ ] Add Template 1 (email): "Q4 Product Launch"
  - channel: "email", subject: "Introducing Our Q4 Solutions", senderName: "Marketing Team", senderEmail: "marketing@example.nl"
  - bodyHtml includes `<p>{{greeting}}</p>...<footer>{{unsubscribe_link}} | {{physicalAddress}}</footer>`
  - Verify `{{unsubscribe_link}}` and physical address placeholders present
- [ ] Add Template 2 (email): "Renewal Reminder"
  - channel: "email", subject: "Your Contract Renewal — {{contractEndDate}}"
  - Similar structure with unsubscribe tokens
- [ ] Add Template 3 (SMS): "Appointment Confirmation"
  - channel: "sms", bodyText: "Your appointment is confirmed for {{appointmentDate}}. Reply STOP to unsubscribe."
  - No subject or footer required (SMS-specific)
- [ ] All templates have unique slugs and realistic Dutch organization names

### Task 1.3: Add Blast seed objects (including A/B pair) to pipelinq_register.json [V1]
- **Spec ref**: REQ-MSB-011-03
- **Files**: `lib/Settings/pipelinq_register.json`
- **Acceptance**: 2 Blast objects forming an A/B test pair with realistic event totals
- [ ] Add Blast 1 (parent): "Q4 Gemeente Outreach - Variant A"
  - segmentId: reference to "Gemeente Contact Blast"
  - templateId: reference to "Q4 Product Launch"
  - channel: "email", status: "sent", sentAt: "2026-02-01T10:00:00Z"
  - abSplitPercent: 50
  - totals: `{ queued: 8000, sent: 8000, delivered: 7950, bounced: 45, opened: 342, clicked: 89, unsubscribed: 8, complained: 0 }`
- [ ] Add Blast 2 (variant B): "Q4 Gemeente Outreach - Variant B"
  - Same segment and template, abVariantOf: reference to parent Blast
  - status: "sent", totals: `{ queued: 8000, sent: 8000, delivered: 7945, bounced: 48, opened: 289, clicked: 67, unsubscribed: 12, complained: 2 }`
- [ ] Both slugs unique and identifiable

### Task 1.4: Add BlastDelivery seed objects (20 rows) to pipelinq_register.json [V1]
- **Spec ref**: REQ-MSB-011-04
- **Files**: `lib/Settings/pipelinq_register.json`
- **Acceptance**: 20 BlastDelivery objects with mixed statuses, provider IDs, timestamps, and clicked URLs
- [ ] Add 20 BlastDelivery objects under `components.objects[]`
- [ ] Status distribution:
  - 4 with status "delivered" (include openedAt, firstClickAt)
  - 3 with status "bounced" (2 hard, 1 soft, include bouncedAt, bounceType)
  - 2 with status "unsubscribed" (include unsubscribedAt)
  - 11 with status "queued"
- [ ] Each delivered entry has:
  - email: realistic Dutch email addresses
  - providerId: SendGrid-style ID (e.g., "sg-abc123xyz...")
  - openedAt: ISO 8601 timestamp
  - firstClickAt: later than openedAt
  - clickedUrls: e.g., `["https://example.nl/solutions?utm_campaign=blast-..."]`
- [ ] All linked to parent Blast via blastId

### Task 1.5: Add ConsentRecord seed objects (10 rows) to pipelinq_register.json [V1]
- **Spec ref**: REQ-MSB-011-05
- **Files**: `lib/Settings/pipelinq_register.json`
- **Acceptance**: 10 ConsentRecord objects with varied lawful basis and withdrawal states
- [ ] Add 10 ConsentRecord objects
- [ ] Distribution:
  - 4 active (no withdrawnAt): mix of lawfulBasis "consent" and "contract"
  - 2 withdrawn unsubscribe: withdrawnReason "user-unsubscribed"
  - 2 withdrawn bounce: 1 "bounce-hard", 1 "bounce-soft-x5"
  - 2 additional varied states (e.g., "complaint", "manual")
- [ ] Each includes:
  - contactId: reference to existing Contact
  - channel: "email" or "sms"
  - lawfulBasis: GDPR Art. 6 compliant
  - consentSource: realistic source ("signup-form", "double-opt-in-email", "contract", "imported", "manual")
  - consentedAt: ISO 8601 (null for non-consent lawful basis)
  - withdrawnAt and withdrawnReason: only if status withdrawn

### Task 1.6: Add AttributionLink seed objects (2 rows) to pipelinq_register.json [V1]
- **Spec ref**: REQ-MSB-011-06
- **Files**: `lib/Settings/pipelinq_register.json`
- **Acceptance**: 2 AttributionLink objects showing realistic revenue attribution
- [ ] Add AttributionLink 1:
  - blastId: reference to "Q4 Gemeente Outreach"
  - contactId: "Jan Pieterse" (existing Contact)
  - dealId: "Municipality Website Redesign" (existing Deal)
  - firstClickAt: "2026-02-01T10:30:00Z"
  - closedWonAt: "2026-02-15T14:00:00Z"
  - attributedValue: 45000 (EUR)
- [ ] Add AttributionLink 2:
  - Similar structure, different Contact and Deal
  - attributedValue: 12000 (EUR)
- [ ] Verify timestamps show click before deal close

---

## Section 2: Backend Services [V1]

### Task 2.1: Create SegmentService [V1]
- **Spec ref**: REQ-MSB-001, REQ-MSB-002, REQ-MSB-010
- **Files**: `lib/Service/SegmentService.php`
- **Acceptance**: Service validates and evaluates rule trees, estimates segment size
- [ ] Implement `validateRules(array $rules, string $entityType): ?string`
  - Recursively traverse rule tree
  - For leaf predicates: verify field exists in entity schema, operator valid for type, value coercible
  - Return null if valid, error message if invalid
  - Field resolution: use `SchemaMapService` to get Contact/Customer field definitions
  - Operators: "equals", "gt", "gte", "lt", "lte", "contains", "in", "between" per field type
- [ ] Implement `evaluateRules(array $rules, array $entity): bool`
  - Recursively evaluate rule tree against a single entity object
  - AND: all children true
  - OR: any child true
  - Leaf predicate: compare entity field against rule value
  - Type coercion where needed (string "90 days" → integer days offset)
- [ ] Implement `estimateSize(string $segmentId): int`
  - Load Segment from ObjectService
  - Query all Contact/Customer objects of matching entityType
  - Evaluate rules against each object
  - Count matches
  - Cache with TTL from app config (default 3600 seconds)
  - Return integer count
- [ ] Implement `getMembersForBlast(string $segmentId): array`
  - Called by BlastService at send time
  - Query all matching objects
  - Return array of `[contactId, email, firstName, lastName]` (minimal fields needed for delivery)
- [ ] Inject: `ObjectService`, `SchemaMapService`, `IAppConfig`, `LoggerInterface`, `ICacheFactory`
- [ ] Add `@spec` PHPDoc with task reference

### Task 2.2: Create ComplianceService [V1]
- **Spec ref**: REQ-MSB-003, REQ-MSB-004, REQ-MSB-005, REQ-MSB-007
- **Files**: `lib/Service/ComplianceService.php`
- **Acceptance**: Service gates sends by consent, enforces template compliance, withdraws consent on unsubscribe/bounce
- [ ] Implement `checkSegmentCompliance(string $segmentId, string $channel): array`
  - Get segment members via SegmentService
  - For each member, check ConsentRecord for lawful-basis on channel
  - Return array: `{ compliant: bool, missingConsent: [contactIds], missingCount: int }`
  - Query ConsentRecord with filters: contactId in members, channel, withdrawnAt null
- [ ] Implement `hasConsentForChannel(string $contactId, string $channel): bool`
  - Query ConsentRecord: contactId, channel, withdrawnAt IS NULL
  - Return true if lawfulBasis is set and non-withdrawn
- [ ] Implement `recordConsentWithdrawal(string $contactId, string $channel, string $reason, ?string $sourceBlastId = null): void`
  - Load or create ConsentRecord
  - Set withdrawnAt = now(), withdrawnReason = reason
  - Call `BlastService::transitionQueuedDeliveries()` to mark all queued BlastDeliveries for this contact as "unsubscribed-before-send"
  - Log withdrawal with reason and source
- [ ] Implement `validateTemplate(array $templateData, string $channel): ?string`
  - If channel = "email":
    - Check bodyHtml contains `{{unsubscribe_link}}`
    - Check footer has physical address placeholders (e.g., street, city, postal code patterns)
    - Return error message if either missing
  - If channel = "sms": return null (no validation required)
- [ ] Inject: `ObjectService`, `LoggerInterface`
- [ ] Add `@spec` PHPDoc

### Task 2.3: Create BlastService [V1]
- **Spec ref**: REQ-MSB-001, REQ-MSB-002, REQ-MSB-006, REQ-MSB-008, REQ-MSB-010
- **Files**: `lib/Service/BlastService.php`
- **Acceptance**: Service orchestrates send: compliance gating, delivery queue creation, A/B splitting, throttle enforcement
- [ ] Implement `sendBlast(string $blastId, bool $isDraft = false): array`
  - Load Blast from ObjectService
  - Validate Blast not already sent (status = "draft")
  - Call `ComplianceService::checkSegmentCompliance()` for the channel
  - If missing consent, return `{ queuedCount: 0, skipCount: count, errors: [list of contactIds] }` and keep status "draft"
  - If user confirmed skip, proceed with compliant contacts only
  - Get segment members via SegmentService::getMembersForBlast()
  - If abSplitPercent set: create variant B Blast and split members deterministically using hash(contactId)
  - Create BlastDelivery rows for each member with status "queued"
  - Transition Blast to status "sending"
  - Return `{ queuedCount: N, skipCount: 0, errors: [] }`
- [ ] Implement `dispatchBlastDeliveries(string $blastId, int $maxPerSecond = 100): int`
  - Query queued BlastDelivery rows for the Blast
  - Read throttle limit from openconnector source config
  - For each batch of deliveries (batch size from config, default 50):
    - Render template with contact variables
    - Call openconnector source action `send-mail` with rendered content
    - On success: update BlastDelivery status "sent", store providerId
    - On failure: update BlastDelivery status "failed", log error
    - Update Blast.totals
  - Enforce rate limit (wait between batches)
  - Return count of successfully sent deliveries
- [ ] Implement `createAbVariant(string $parentBlastId, array $variantData): string`
  - Load parent Blast
  - Create new Blast with abVariantOf = parentBlastId
  - Return new Blast UUID
- [ ] Implement `updateBlastTotals(string $blastId): void`
  - Query BlastDelivery rows grouped by status
  - Recount totals: queued, sent, delivered, bounced, opened, clicked, unsubscribed, complained
  - Update Blast.totals object
- [ ] Implement `transitionQueuedDeliveries(string $contactId, string $blastId, string $newStatus): void`
  - Called by ComplianceService on consent withdrawal
  - Query BlastDelivery: contactId, status = "queued"
  - Transition to newStatus (e.g., "unsubscribed-before-send")
- [ ] Inject: `SegmentService`, `ComplianceService`, `ObjectService`, `IAppConfig`, `LoggerInterface`
- [ ] Add `@spec` PHPDoc

### Task 2.4: Create AttributionService [V1]
- **Spec ref**: REQ-MSB-009
- **Files**: `lib/Service/AttributionService.php`
- **Acceptance**: Service links clicks to closed Deals via UTM parameters
- [ ] Implement `recordClick(string $blastDeliveryId, array $clickEvent): void`
  - Load BlastDelivery from ObjectService
  - Extract firstClickAt from clickEvent timestamp
  - Extract clicked URLs from clickEvent
  - Update BlastDelivery: firstClickAt, clickedUrls[]
- [ ] Implement `linkBlastToDeal(string $blastDeliveryId, string $dealId): void`
  - Load BlastDelivery, extract blastId and contactId
  - Load Deal, extract closedWonAt and amount
  - Create AttributionLink: blastId, contactId, dealId, firstClickAt (from delivery), closedWonAt, attributedValue (from deal)
- [ ] Implement `getBlastAttributedValue(string $blastId): float`
  - Query AttributionLink objects with blastId
  - Sum attributedValue
  - Return total EUR
- [ ] Inject: `ObjectService`, `LoggerInterface`
- [ ] Add `@spec` PHPDoc

### Task 2.5: Create BlastSendJob background job [V1]
- **Spec ref**: REQ-MSB-008, REQ-MSB-010
- **Files**: `lib/BackgroundJob/BlastSendJob.php`, `appinfo/info.xml`
- **Acceptance**: Job runs every 5 minutes, dispatches queued deliveries, processes webhooks
- [ ] Create `BlastSendJob` class extending `OCP\BackgroundJob\TimedJob`
- [ ] Set interval to 300 seconds (5 minutes)
- [ ] Implement `run(IJobList $jobList)` method:
  - Query all Blasts with status = "sending"
  - For each Blast, call `BlastService::dispatchBlastDeliveries()`
  - Log dispatch count and any errors per Blast
  - Catch per-Blast errors — job MUST NOT abort if one Blast fails
  - After dispatches, check for pending webhook events (from webhook queue)
  - Process bounces, opens, clicks, unsubscribes
  - Call `ComplianceService::recordConsentWithdrawal()` for unsubscribes and hard bounces
  - Update Blast.totals via `BlastService::updateBlastTotals()`
- [ ] Register in `appinfo/info.xml` under `<background-jobs>`
- [ ] Inject: `BlastService`, `ComplianceService`, `IUserManager`, `IAppConfig`, `LoggerInterface`
- [ ] Add `@spec` PHPDoc

### Task 2.6: Create BlastController REST API [V1]
- **Spec ref**: REQ-MSB-012
- **Files**: `lib/Controller/BlastController.php`, `appinfo/routes.php`
- **Acceptance**: CRUD endpoints for Blasts with proper authorization and error handling
- [ ] Create `BlastController` extending `Controller`
- [ ] Implement endpoints:
  - `GET /api/blasts?status=draft&page=1&limit=20` → list paginated with filters
  - `POST /api/blasts` → create new Blast in draft, validate required fields
  - `GET /api/blasts/:blastId` → get single Blast with current totals
  - `PATCH /api/blasts/:blastId` → update Blast (name only; status via separate endpoints)
  - `POST /api/blasts/:blastId/send` → transition to sending, initiate dispatch
  - `POST /api/blasts/:blastId/cancel` → cancel sending Blast
  - `GET /api/blasts/:blastId/deliveries?page=1&limit=50` → list BlastDelivery rows
- [ ] All endpoints:
  - Derive user from `IUserSession` — NEVER trust frontend user ID
  - Return JSONResponse with proper HTTP status (200, 201, 400, 404, 500)
  - Error messages MUST be generic, not expose internal details (ADR-005)
  - Controller methods <10 lines, delegate logic to services (ADR-003)
- [ ] Add routes to `appinfo/routes.php` before any wildcard routes
- [ ] Add `@spec` PHPDoc

### Task 2.7: Create SegmentController REST API [V1]
- **Spec ref**: REQ-MSB-001, REQ-MSB-002
- **Files**: `lib/Controller/SegmentController.php`, `appinfo/routes.php`
- **Acceptance**: CRUD endpoints for Segments with rule validation
- [ ] Create `SegmentController`
- [ ] Implement endpoints:
  - `GET /api/segments` → list all segments
  - `POST /api/segments` → create new Segment, validate rule tree via ComplianceService
  - `GET /api/segments/:segmentId` → get with current estimatedSize
  - `GET /api/segments/:segmentId/members?page=1&limit=50` → preview members
  - `POST /api/segments/:segmentId/size` → manually refresh estimatedSize
- [ ] On POST, validate rules: call `SegmentService::validateRules()` before save
- [ ] Return JSONResponse with proper status and error messages
- [ ] Add routes and `@spec` PHPDoc

### Task 2.8: Create TemplateController REST API [V1]
- **Spec ref**: REQ-MSB-004
- **Files**: `lib/Controller/TemplateController.php`, `appinfo/routes.php`
- **Acceptance**: CRUD endpoints for CampaignTemplates with content validation
- [ ] Create `TemplateController`
- [ ] Implement endpoints:
  - `GET /api/templates` → list all templates
  - `POST /api/templates` → create, validate via `ComplianceService::validateTemplate()`
  - `GET /api/templates/:templateId` → get single template
  - `PATCH /api/templates/:templateId` → update template
- [ ] On POST/PATCH, call `validateTemplate()` before save
- [ ] Return proper error messages if validation fails (e.g., missing unsubscribe token)
- [ ] Add routes and `@spec` PHPDoc

### Task 2.9: Create webhook processing for bounce/open/click/unsubscribe events [V1]
- **Spec ref**: REQ-MSB-005, REQ-MSB-007, REQ-MSB-009
- **Files**: `lib/Controller/WebhookController.php`, `lib/Service/WebhookProcessorService.php`
- **Acceptance**: Endpoints for SendGrid, SES, Twilio webhooks; process events into BlastDelivery state and ConsentRecord changes
- [ ] Create `WebhookController` with endpoints:
  - `POST /webhook/sendgrid` → receive SendGrid events
  - `POST /webhook/ses` → receive AWS SES events
  - `POST /webhook/twilio` → receive Twilio SMS events
- [ ] Create `WebhookProcessorService` with methods:
  - `processSendGridEvent(array $event)` → parse event type and dispatch
  - `processBounce(array $event)` → update BlastDelivery.bounceType, call `ComplianceService::recordConsentWithdrawal()` for hard bounces
  - `processOpen(array $event)` → set BlastDelivery.openedAt
  - `processClick(array $event)` → set BlastDelivery.firstClickAt and clickedUrls[], extract utm_campaign
  - `processUnsubscribe(array $event)` → call `ComplianceService::recordConsentWithdrawal()` with reason "user-unsubscribed"
- [ ] Each webhook endpoint MUST:
  - Verify webhook signature (provider-specific; use openconnector integration for cred management)
  - Queue events to process asynchronously (write to app cache or webhook queue table)
  - Return HTTP 200 immediately (provider requires fast response)
- [ ] Webhook processor job runs every 5 minutes via `BlastSendJob`, processes queued events
- [ ] Add routes and `@spec` PHPDoc

---

## Section 3: Frontend Components [V1]

### Task 3.1: Create SegmentBuilder.vue component [V1]
- **Spec ref**: REQ-MSB-001
- **Files**: `pipelinq/src/components/SegmentBuilder.vue`
- **Acceptance**: Visual rule composer with AND/OR logic, real-time validation and size estimation
- [ ] Create Vue component with props:
  - `modelValue` (rule tree object)
  - `entityType` ("contact" | "customer")
- [ ] Render recursive rule tree:
  - Node type selector: AND | OR radio buttons
  - "Add condition" button → predicate form (field dropdown, operator dropdown, value input)
  - "Add group" button → nested AND/OR node
  - "Remove" button per node
- [ ] Implement predicate form:
  - Field dropdown: populate from entity schema fields via `SchemaService.getFields(entityType)`
  - Operator dropdown: filter by field type (string: equals/contains, number: gt/gte/lt/lte, date: lt/gte)
  - Value input: type changes based on operator and field type
- [ ] Real-time validation:
  - On blur of any field, call backend `POST /api/segments/validate` with current rule tree
  - Display field-level error messages if invalid
- [ ] Size estimation:
  - Debounced 1-second call to backend on rule change
  - Display "Estimated members: 2,341" or spinner if loading
  - Cache estimate client-side (1 minute TTL)
- [ ] Emit `update:modelValue` on any rule change
- [ ] Add `@spec` PHPDoc

### Task 3.2: Create BlastForm.vue component [V1]
- **Spec ref**: REQ-MSB-001, REQ-MSB-008
- **Files**: `pipelinq/src/views/blasts/BlastForm.vue`
- **Acceptance**: Wizard for creating Blasts: name → segment → template → channel → schedule → A/B options
- [ ] Multi-step form with steps:
  1. Blast name (text input)
  2. Segment picker (dropdown, shows segment name + estimated size)
  3. Template picker (dropdown, filtered by selected channel)
  4. Channel selector (radio: email | sms; auto-populated from template)
  5. Schedule (radio: send-now | schedule; datetime-local if scheduled)
  6. A/B testing (toggle → split percent slider 0-100)
- [ ] Validation:
  - All fields required
  - Call `POST /api/segments/:segmentId/check-compliance` before sending
  - If missing consent, show modal with options (skip contacts, request consent, cancel)
  - Call `POST /api/templates/:templateId/validate` for email templates
- [ ] On submit:
  - POST `/api/blasts` with name, segmentId, templateId, channel, scheduledFor, abSplitPercent
  - On success: navigate to `BlastMonitor` view for the new Blast
  - On error: display inline error messages
- [ ] Add `@spec` PHPDoc

### Task 3.3: Create BlastMonitor.vue component [V1]
- **Spec ref**: REQ-MSB-010
- **Files**: `pipelinq/src/views/blasts/BlastMonitor.vue`
- **Acceptance**: Real-time send progress tracking with live counts and event timeline
- [ ] Display:
  - Progress bar: `[████████░░░░░░░░░░░░░░░░░░] 12,500 / 50,000 (25%)`
  - Estimated time remaining based on current dispatch rate
  - Totals grid (4 columns × 2 rows):
    - Row 1: Queued | Sent | Delivered | Bounced
    - Row 2: Opened | Clicked | Unsubscribed | Complained
  - Event timeline: last 50 events in reverse chronological order with icons
    - Opened events: "👁 Jan 10:30 → jan@example.nl"
    - Clicked events: "🔗 Jan 10:31 → clicked link"
    - Bounced events: "⚠️ Jan 10:32 → bounced (hard)"
    - Unsubscribed events: "🚫 Jan 10:33 → unsubscribed"
- [ ] Polling:
  - GET `/api/blasts/:blastId` every 2 seconds
  - Update totals and progress bar
  - Fetch new events and prepend to timeline
  - Stop polling if status = "sent" or "failed"
- [ ] "Cancel send" button (if status = "sending")
  - POST `/api/blasts/:blastId/cancel`
  - Disable button, show "Cancelling..." state
- [ ] Add `@spec` PHPDoc

### Task 3.4: Create PerformanceDashboard.vue component [V1]
- **Spec ref**: REQ-MSB-006, REQ-MSB-009
- **Files**: `pipelinq/src/views/blasts/PerformanceDashboard.vue`
- **Acceptance**: Post-send analytics with A/B testing and revenue attribution
- [ ] Tab 1: Overview
  - List of recent Blasts in table with columns:
    - Blast name | Segment | Status | Sent | Delivered | Open rate % | Click rate % | Unsubscribed
  - Sorting by status, date, metrics
- [ ] Tab 2: A/B Testing (if `abVariantOf` exists)
  - Side-by-side variant comparison:
    - | Metric | Variant A | Variant B | Significance |
    - | Delivered | 500 | 500 | — |
    - | Opened | 45 | 62 | Not significant |
    - | Click rate % | 9.0% | 12.4% | p=0.072 |
  - Once N≥500 each and 24h elapsed, compute chi-square test
  - Display "Not significant (p>0.05)" or "Variant B is significantly higher (p<0.05) ✓"
- [ ] Tab 3: Attribution
  - Table of Blasts with:
    - Blast name | Attributed deals count | Attributed value (EUR) | ROI if spend known (V2)
  - GET `/api/blasts/:blastId/attribution` to sum attributed values
  - Display "Q4 Gemeente Outreach: Attributed value EUR 75,500 from 3 deals"
- [ ] Add `@spec` PHPDoc

---

## Section 4: Unit Tests [V1]

### Task 4.1: Unit tests for SegmentService [V1]
- **Spec ref**: REQ-MSB-001, REQ-MSB-002
- **Files**: `tests/Unit/Service/SegmentServiceTest.php`
- **Acceptance**: ≥5 test methods covering validation, evaluation, and estimation
- [ ] Test `validateRules()` accepts valid rules
- [ ] Test `validateRules()` rejects invalid field
- [ ] Test `validateRules()` rejects invalid operator for type
- [ ] Test `evaluateRules()` returns true for matching entity
- [ ] Test `evaluateRules()` returns false for non-matching entity
- [ ] Test `evaluateRules()` handles AND logic
- [ ] Test `evaluateRules()` handles OR logic
- [ ] Test `estimateSize()` returns count of matching entities
- [ ] Mock `ObjectService` — do NOT use real DB
- [ ] Use realistic rule trees from seed data

### Task 4.2: Unit tests for ComplianceService [V1]
- **Spec ref**: REQ-MSB-003, REQ-MSB-004, REQ-MSB-005, REQ-MSB-007
- **Files**: `tests/Unit/Service/ComplianceServiceTest.php`
- **Acceptance**: ≥5 test methods covering consent checking, template validation, withdrawal
- [ ] Test `checkSegmentCompliance()` returns all compliant
- [ ] Test `checkSegmentCompliance()` returns missing contacts
- [ ] Test `validateTemplate()` rejects email without unsubscribe token
- [ ] Test `validateTemplate()` rejects email without physical address
- [ ] Test `validateTemplate()` accepts valid email
- [ ] Test `validateTemplate()` accepts SMS (no validation)
- [ ] Test `hasConsentForChannel()` returns true for active consent
- [ ] Test `hasConsentForChannel()` returns false for withdrawn
- [ ] Test `recordConsentWithdrawal()` updates ConsentRecord and transitions deliveries
- [ ] Mock ObjectService

### Task 4.3: Unit tests for BlastService [V1]
- **Spec ref**: REQ-MSB-001, REQ-MSB-006, REQ-MSB-008, REQ-MSB-010
- **Files**: `tests/Unit/Service/BlastServiceTest.php`
- **Acceptance**: ≥5 test methods covering send, dispatch, A/B splitting, throttling
- [ ] Test `sendBlast()` creates BlastDelivery queue
- [ ] Test `sendBlast()` fails on missing consent
- [ ] Test `createAbVariant()` creates variant B with correct split
- [ ] Test `dispatchBlastDeliveries()` calls openconnector for each delivery
- [ ] Test `dispatchBlastDeliveries()` respects rate limit
- [ ] Test `updateBlastTotals()` recounts deliveries by status
- [ ] Mock SegmentService, ComplianceService, ObjectService

### Task 4.4: Integration test: Segment → Blast → Send [V1]
- **Spec ref**: REQ-MSB-001, REQ-MSB-002, REQ-MSB-003
- **Files**: `tests/Integration/BlastWorkflowTest.php`
- **Acceptance**: E2E test from segment creation through blast send
- [ ] Setup: Create test segment with rule, add test contacts with consent
- [ ] Create Blast with the segment
- [ ] Send blast, verify BlastDeliveries created for compliant contacts
- [ ] Verify non-compliant contacts skipped
- [ ] Use real ObjectService (may use test register if available)

---

## Section 5: Documentation [V1]

### Task 5.1: Add CHANGELOG entry [V1]
- **Spec ref**: All
- **Files**: `CHANGELOG.md`
- **Acceptance**: Entry documents new feature at top with version and feature summary
- [ ] Add entry under "Unreleased" or next version number
- [ ] Summary: "Add marketing segmentation and blast campaigns with rule-based segments, multi-channel sends (email/SMS), compliance enforcement (GDPR/CAN-SPAM), A/B testing, and revenue attribution."
- [ ] Highlight key accomplishments: segment builder, real-time delivery tracking, consent gating, attribution

### Task 5.2: Add feature documentation to docs/ [V1]
- **Spec ref**: All
- **Files**: `docs/user/marketing-blasts.md` (or similar)
- **Acceptance**: User guide for marketing managers covering segment building, template creation, and send workflows
- [ ] Section 1: Creating segments with rule builder
- [ ] Section 2: Creating email and SMS templates with compliance requirements
- [ ] Section 3: Scheduling and sending blasts
- [ ] Section 4: A/B testing workflows
- [ ] Section 5: Monitoring delivery and attribution
- [ ] Include screenshots of UI components
- [ ] Dutch and English versions if applicable

---

## Section 6: Database & Migration [V1]

### Task 6.1: Register seed data in pipelinq_register.json [V1]
- **Spec ref**: REQ-MSB-011
- **Files**: `lib/Settings/pipelinq_register.json`
- **Acceptance**: All 6 entity types (Segment, CampaignTemplate, Blast, BlastDelivery, ConsentRecord, AttributionLink) registered in schema definitions and populated with seed objects
- [ ] Register schema definitions under `components.schemas[]`:
  - segment
  - campaign-template
  - blast
  - blast-delivery
  - consent-record
  - attribution-link
- [ ] Each schema includes fields from context-brief and ADR-000
- [ ] All 20+ seed objects added under `components.objects[]`
- [ ] Verify register syntax valid (OpenRegister format)

---

## Section 7: Quality & Verification [V1]

### Task 7.1: Run unit and integration tests [V1]
- **Spec ref**: All
- **Files**: `tests/`
- **Acceptance**: All tests pass with ≥80% code coverage for new services
- [ ] Run: `composer test` or equivalent
- [ ] All test methods pass
- [ ] Check coverage: SegmentService, ComplianceService, BlastService, AttributionService ≥80%
- [ ] Fix any failing tests before PR

### Task 7.2: Manual test blast end-to-end workflow [V1]
- **Spec ref**: All
- **Files**: Pipelinq UI
- **Acceptance**: E2E test: create segment → create template → send blast → verify delivery
- [ ] Start dev server
- [ ] Create a test segment with simple rule (e.g., industry = "test")
- [ ] Create test contacts matching the rule with valid consent
- [ ] Create email template with unsubscribe token and address
- [ ] Create blast, send to test segment
- [ ] Verify blast progresses from "draft" → "sending" → "sent"
- [ ] Verify BlastDeliveries created for each contact
- [ ] Monitor delivery progress in BlastMonitor
- [ ] Check seed data loads correctly in database

### Task 7.3: Manual test compliance blocking [V1]
- **Spec ref**: REQ-MSB-003, REQ-MSB-004, REQ-MSB-007
- **Files**: Pipelinq UI
- **Acceptance**: Send blocked if compliance violated
- [ ] Create test contact WITHOUT email consent
- [ ] Create blast targeting segment including that contact
- [ ] Attempt send → verify modal appears with contact ID
- [ ] Verify "Skip contacts" option works
- [ ] Create template without unsubscribe token → verify save rejected

### Task 7.4: Manual test A/B testing [V1]
- **Spec ref**: REQ-MSB-006
- **Files**: Pipelinq UI
- **Acceptance**: A/B variants created, significance test displays correctly
- [ ] Create blast with A/B split 50%
- [ ] Send to segment with ≥1000 contacts
- [ ] Monitor BlastMonitor: verify variant A and B both created and sending
- [ ] Once >500 delivered and 24h elapsed, check PerformanceDashboard
- [ ] Verify significance test displays with p-value and interpretation

### Task 7.5: Manual test unsubscribe propagation [V1]
- **Spec ref**: REQ-MSB-005
- **Files**: Pipelinq UI, webhook processor
- **Acceptance**: Unsubscribe recorded within 60 seconds
- [ ] Send test blast to contact
- [ ] Simulate unsubscribe webhook: POST `/webhook/sendgrid` with unsubscribe event
- [ ] Check ConsentRecord within 1 minute: withdrawnAt should be set
- [ ] Verify future blast sends skip the unsubscribed contact

---

## Section 8: Code Review Checklist

### Task 8.1: Pre-merge code review items [Before PR]
- **Spec ref**: ADR-005 (security), ADR-015 (common patterns)
- **Acceptance**: All items reviewed before pull request submission
- [ ] All new services use `ObjectService` for CRUD (not raw SQL or custom DAO)
- [ ] All API endpoints derive user from `IUserSession`, never from request body
- [ ] All error messages are generic, not exposing internal details
- [ ] All controller methods <10 lines, logic delegated to services
- [ ] All webhook events processed asynchronously (job, not blocking HTTP response)
- [ ] Consent enforcement blocks every send (ComplianceService gating)
- [ ] Template validation enforces unsubscribe token and address (REQ-MSB-004)
- [ ] Rate limiting applied per openconnector source (no hardcoding 100/sec)
- [ ] Soft bounce counter threshold configurable (default 5)
- [ ] AttributionLink creation links deal close to blast click (temporal order verified)
- [ ] Seed data uses Dutch organization names and realistic timestamps
- [ ] PHPDoc `@spec` references present on all public methods
- [ ] Unit tests ≥80% coverage on service classes
- [ ] Integration test E2E segment → blast → send included

---

## Implementation Notes

- **Priority**: MVP features only — no enterprise scoring, no bulk operations (V2), no custom unsubscribe page (V2)
- **Staging approach**: Implement in order: data → services → controllers → frontend → tests → docs
- **Provider integration**: Use openconnector's existing source abstraction — never import SendGrid/SES/Twilio SDKs directly
- **Compliance**: Every requirement ties to GDPR Art. 6 (lawful basis) or Art. 17 (right to be forgotten) — fail safe on consent
- **Testing**: Unit tests use mocks; integration test uses real ObjectService if test register available

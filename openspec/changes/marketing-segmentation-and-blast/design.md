# Design: Marketing Segmentation and Blast

## Overview

This change adds campaign-grade marketing capabilities to Pipelinq by implementing rule-based customer segmentation, multi-channel blast sending with real-time tracking, compliance enforcement (AVG/GDPR), and revenue attribution. No new entity types are introduced beyond the data model in ADR-000: Segment, CampaignTemplate, Blast, BlastDelivery, ConsentRecord, and AttributionLink.

The architecture layers:
- **Data Layer**: Six new OpenRegister schemas stored in `pipelinq_register.json`
- **Backend Services**: SegmentService (rule evaluation), BlastService (send orchestration), ComplianceService (consent gating), AttributionService (click→deal joining)
- **Background Jobs**: BlastSendJob (dispatch queued BlastDeliveries), WebhookProcessor (bounce/open/click/unsubscribe ingest)
- **Frontend Components**: SegmentBuilder (rule visual composer), BlastForm (schedule/send), BlastMonitor (live tracking), PerformanceDashboard (A/B testing + ROI)

---

## Data Layer

All six schemas are defined in ADR-000 and populated via seed data in `pipelinq_register.json`.

### Segment

Stored query for dynamic customer membership. Never materialized as a contact list.

| Property | Type | Required | Notes |
|----------|------|----------|-------|
| name | string | Yes | Segment name, e.g. "Q4 Enterprise Outreach" |
| description | string | No | Human-readable purpose |
| rules | object | Yes | JSON tree: `{ type: "AND"\|"OR", children: [...] }` with leaf `{ field, operator, value }` |
| entityType | string | Yes | "contact" \| "customer" — schema to evaluate rules against |
| estimatedSize | integer | No | Cached count refreshed hourly; displayed in UI before send |
| createdBy | string | Yes | Nextcloud user UID of segment creator |
| createdAt | string | Yes | ISO 8601 timestamp |
| updatedAt | string | Yes | ISO 8601 timestamp |

**Seed data**: 5 Segment objects with realistic Dutch criteria
- "Gemeente Contact Blast" — industry = "gemeente" AND last-contact-moment < 90 days
- "Enterprise High-Value" — employees > 100 AND annual_revenue > 10M
- "Inactive Leads" — status = "lead" AND last-activity > 6 months
- "Retention Newsletter" — status = "customer" AND contract_end < 60 days
- "Technical Leads" — tags contains "technical" OR role contains "CTO/technical"

### CampaignTemplate

Reusable email/SMS template with enforced compliance fields.

| Property | Type | Required | Notes |
|----------|------|----------|-------|
| name | string | Yes | Template name, e.g. "Q4 Product Launch - Email" |
| channel | string | Yes | "email" \| "sms" |
| subject | string | No | Email subject line (email only) |
| bodyHtml | string | Yes | HTML body with Mustache variables: `{{firstName}}`, `{{companyName}}`, `{{unsubscribe_link}}` |
| bodyText | string | No | Plain-text version of body |
| senderName | string | Yes | Display name in email From: |
| senderEmail | string | Yes | Email address in From: (validated during save) |
| replyTo | string | No | Reply-To address (optional; defaults to senderEmail) |
| footerOverride | string | No | Custom footer (must contain `{{unsubscribe_link}}` for email); validated at save |
| variables | array | No | List of variable names used in template for validation |
| language | string | No | ISO 639-1 language code (en, nl, de, fr, etc.) — stored but not enforced in V1 |
| createdBy | string | Yes | Nextcloud user UID |
| createdAt | string | Yes | ISO 8601 |

**Seed data**: 3 CampaignTemplate objects
- Email: "Q4 Newsletter" — senderEmail "marketing@acme.nl", includes {{unsubscribe_link}} and address footer
- Email: "Renewal Reminder" — senderEmail "success@acme.nl", targets customers within 60 days of renewal
- SMS: "Appointment Confirmation" — bodyText, no footer requirement (SMS doesn't support links)

### Blast

Scheduled or sent campaign send.

| Property | Type | Required | Notes |
|----------|------|----------|-------|
| name | string | Yes | Blast name, e.g. "Q4 Newsletter - Gemeente Segment - Variant A" |
| segmentId | string | Yes | UUID reference to Segment |
| templateId | string | Yes | UUID reference to CampaignTemplate |
| channel | string | Yes | "email" \| "sms" (denormalized from template for query efficiency) |
| scheduledFor | string | No | ISO 8601 timestamp for scheduled sends; null = send now |
| sentAt | string | No | ISO 8601 timestamp when first BlastDelivery was dispatched; set on actual send |
| status | string | Yes | "draft" \| "scheduled" \| "sending" \| "sent" \| "cancelled" \| "failed" |
| abVariantOf | string | No | UUID of parent Blast if this is a B variant (A/B testing) |
| abSplitPercent | integer | No | Split percentage (0-100) for A/B; only on parent Blast |
| totals | object | Yes | `{ queued, sent, delivered, bounced, opened, clicked, unsubscribed, complained }` — updated in real time |
| connectorSourceId | string | Yes | UUID of openconnector source for this send (e.g., SendGrid account) |
| createdBy | string | Yes | Nextcloud user UID |
| createdAt | string | Yes | ISO 8601 |

**Seed data**: 2 Blast objects (including A/B pair)
- "Q4 Gemeente Outreach - Variant A" — status "sent", 8000 queued → 7950 delivered, 342 opened, 89 clicked
- "Q4 Gemeente Outreach - Variant B" — status "sent", abVariantOf parent, 8000 queued → 7945 delivered, 289 opened, 67 clicked

### BlastDelivery

Per-recipient row — one per contact in the segment at blast-send time.

| Property | Type | Required | Notes |
|----------|------|----------|-------|
| blastId | string | Yes | UUID reference to parent Blast |
| contactId | string | No | UUID of Contact; may be null if contact was deleted post-send (pseudonymization) |
| email | string | Yes | Email or SMS destination (denormalized for delivery) |
| status | string | Yes | "queued" \| "sent" \| "delivered" \| "bounced" \| "failed" \| "unsubscribed-before-send" |
| sentAt | string | No | ISO 8601 when sent to provider |
| providerId | string | No | Provider's message ID (SendGrid msg_id, SES message_id, etc.) — used for tracking webhooks |
| openedAt | string | No | ISO 8601 of first open event (from provider webhook) |
| firstClickAt | string | No | ISO 8601 of first click event; used for attribution join |
| clickedUrls | array | No | List of URLs clicked by recipient (from click-tracking webhook) |
| bouncedAt | string | No | ISO 8601 when bounce received |
| bounceType | string | No | "hard" \| "soft" (from provider webhook) |
| unsubscribedAt | string | No | ISO 8601 when unsubscribe received |

**Seed data**: 20 BlastDelivery objects
- Mix of statuses: 4 delivered with opened/clicked, 3 bounced (2 hard, 1 soft), 2 unsubscribed, 11 queued
- Realistic provider IDs (SendGrid-style: `sg-xxx...`), timestamps, clicked URLs with utm parameters

### ConsentRecord

Per-contact, per-channel lawful basis and consent lifecycle.

| Property | Type | Required | Notes |
|----------|------|----------|-------|
| contactId | string | Yes | UUID of Contact |
| channel | string | Yes | "email" \| "sms" \| "profiling" |
| lawfulBasis | string | Yes | "consent" \| "contract" \| "legitimate-interest" \| "legal-obligation" (GDPR Art. 6) |
| consentedAt | string | No | ISO 8601 when consent was given (null if lawful-basis is not "consent") |
| consentSource | string | No | "signup-form" \| "double-opt-in-email" \| "contract" \| "imported" \| "manual" |
| consentIp | string | No | IP address hash (if obtained via web form) — not stored directly per privacy best practice |
| withdrawnAt | string | No | ISO 8601 when consent was withdrawn |
| withdrawnReason | string | No | "user-unsubscribed" \| "bounce-hard" \| "bounce-soft-x5" \| "complaint" \| "manual" \| "right-to-be-forgotten" |

**Seed data**: 10 ConsentRecord objects
- Mix of statuses: 6 active consent (email + SMS each), 2 withdrawn (unsubscribed), 1 bounce-hard, 1 bounce-soft-x5
- Varied consentSource: "signup-form", "double-opt-in-email", "contract", "imported"
- Realistic timestamps (some old, some recent)

### AttributionLink

Blast → Contact → Deal revenue attribution via click tracking.

| Property | Type | Required | Notes |
|----------|------|----------|-------|
| blastId | string | Yes | UUID of Blast |
| contactId | string | Yes | UUID of Contact who clicked |
| dealId | string | Yes | UUID of closed-won Deal |
| firstClickAt | string | Yes | ISO 8601 of first click in the blast email |
| closedWonAt | string | Yes | ISO 8601 when Deal was closed-won |
| attributedValue | number | Yes | EUR amount from Deal.value |
| createdAt | string | Yes | ISO 8601 when attribution link was created |

**Seed data**: 2 AttributionLink objects (sample revenue attribution)
- Blast Q4 Gemeente → Contact Jan Pieterse → Deal "Municipality Website Redesign" EUR 45,000
- Blast Q4 Gemeente → Contact Maria Jansen → Deal "CRM License Renewal" EUR 12,000

---

## Backend Services

### SegmentService (`lib/Service/SegmentService.php`)

Rule evaluation and segment membership computation.

**Dependencies:**
- `OCA\OpenRegister\Service\ObjectService` — query Contact and Customer objects
- `OCA\Pipelinq\Service\SchemaMapService` — resolve field definitions

**Key Methods:**

| Method | Signature | Purpose |
|--------|-----------|---------|
| `validateRules` | `validateRules(array $rules, string $entityType): ?string` | Validate rule tree: field exists, operator valid for type, value coercible. Return error message or null if valid. |
| `evaluateRules` | `evaluateRules(array $rules, array $entity): bool` | Recursively evaluate rule tree against a single entity object. Return true if all AND conditions met or any OR condition met. |
| `estimateSize` | `estimateSize(string $segmentId): int` | Execute the segment's rule tree against all entities of its type; cache the count with 1-hour TTL. Return estimated member count. |
| `getMembersForBlast` | `getMembersForBlast(string $segmentId): array` | Return array of `[contactId, email, firstName, lastName]` objects matching the segment at blast-send time. Called by BlastService. |

### BlastService (`lib/Service/BlastService.php`)

Blast orchestration: send initiation, delivery queueing, throttling, and real-time status.

**Dependencies:**
- `SegmentService` — get segment members
- `ComplianceService` — consent gating
- `OCA\OpenRegister\Service\ObjectService` — CRUD Blast and BlastDelivery
- `OCP\AppConfig` — read throttle settings per connectorSourceId
- `LoggerInterface` — log send progress

**Key Methods:**

| Method | Signature | Purpose |
|--------|-----------|---------|
| `sendBlast` | `sendBlast(string $blastId, bool $isDraft = false): array` | Validate segment and compliance, create BlastDelivery queue, return `{ queuedCount, skipCount, errors }`. Transition Blast to "sending". |
| `dispatchBlastDeliveries` | `dispatchBlastDeliveries(string $blastId, int $maxPerSecond = 100): int` | Dequeue queued BlastDeliveries, dispatch via openconnector, update status to "sent" or "failed". Respect rate limit. Return count dispatched. |
| `updateBlastTotals` | `updateBlastTotals(string $blastId): void` | Recount BlastDelivery statuses, update Blast.totals with current counts. Called after send and on webhook events. |
| `createAbVariant` | `createAbVariant(string $parentBlastId, array $variantData): string` | Create a B-variant Blast from a parent, set `abVariantOf` and `abSplitPercent`. Return new Blast UUID. |

### ComplianceService (`lib/Service/ComplianceService.php`)

Consent gating, lawful-basis validation, right-to-be-forgotten enforcement.

**Dependencies:**
- `OCA\OpenRegister\Service\ObjectService` — query ConsentRecord
- `LoggerInterface`

**Key Methods:**

| Method | Signature | Purpose |
|--------|-----------|---------|
| `checkSegmentCompliance` | `checkSegmentCompliance(string $segmentId, string $channel): array` | For each member of the segment, check if ConsentRecord exists with lawful-basis set for the channel. Return `{ compliant: bool, missingConsent: [contactIds], missingCount: int }`. |
| `hasConsentForChannel` | `hasConsentForChannel(string $contactId, string $channel): bool` | Return true if a non-withdrawn ConsentRecord exists for the contact on the channel. |
| `recordConsentWithdrawal` | `recordConsentWithdrawal(string $contactId, string $channel, string $reason, ?string $sourceBlastId = null): void` | Set ConsentRecord.withdrawnAt and withdrawnReason; queue all in-flight BlastDeliveries for this contact to "unsubscribed-before-send". |
| `validateTemplate` | `validateTemplate(array $templateData, string $channel): ?string` | For email: check bodyHtml contains `{{unsubscribe_link}}` and footer has physical address. For SMS: no-op. Return error message or null. |

### AttributionService (`lib/Service/AttributionService.php`)

Link clicks to closed Deals via UTM parameters and form submissions.

**Dependencies:**
- `OCA\OpenRegister\Service\ObjectService` — CRUD AttributionLink
- `OCA\Pipelinq\Service\LeadService` — query leads by UTM source

**Key Methods:**

| Method | Signature | Purpose |
|--------|-----------|---------|
| `recordClick` | `recordClick(string $blastDeliveryId, array $clickEvent): void` | Update BlastDelivery.firstClickAt and clickedUrls; extract utm_campaign and utm_source from clicked URL. |
| `linkBlastToDeal` | `linkBlastToDeal(string $blastDeliveryId, string $dealId): AttributionLink` | Create an AttributionLink joining the blast, contact, and deal. Called when a lead from the blast's utm_campaign closes as won. |
| `getBlastAttributedValue` | `getBlastAttributedValue(string $blastId): number` | Sum AttributionLink.attributedValue for all links with this blastId. Return total EUR attributed to the blast. |

### BlastSendJob (`lib/BackgroundJob/BlastSendJob.php`)

Periodic background job (5 minutes) that dispatches queued BlastDeliveries and processes webhooks.

**Type**: `OCP\BackgroundJob\TimedJob`
**Interval**: 300 seconds (5 minutes)

- Iterate queued Blasts in "sending" status
- Call `BlastService::dispatchBlastDeliveries()` for each
- Process pending webhook events (bounces, opens, clicks, unsubscribes)
- Update Blast.totals and ConsentRecord withdrawals
- Log errors per-blast without aborting

---

## Frontend Components

### SegmentBuilder.vue

Visual rule composer for AND/OR trees with leaf predicates.

**Props**: `modelValue` (rule tree), `entityType` ("contact" | "customer")

**UI**:
- Node type selector: AND | OR (radio buttons)
- "Add condition" button → predicate composer (field dropdown, operator dropdown, value input)
- "Add group" button → nested AND/OR node
- "Remove" button per node
- Real-time "Estimated members: 2,341" count (calls `SegmentService.estimateSize()` on blur with 1-second debounce)
- Field validation error messages inline

**Data**:
```json
{
  "type": "AND",
  "children": [
    { "field": "industry", "operator": "equals", "value": "gemeente" },
    {
      "type": "OR",
      "children": [
        { "field": "employees", "operator": "gt", "value": 50 },
        { "field": "annual_revenue", "operator": "gt", "value": 5000000 }
      ]
    },
    { "field": "last_contact_moment", "operator": "lt", "value": "90 days" }
  ]
}
```

### BlastForm.vue

Schedule/send dialog with template and segment pickers.

**Inputs**:
1. Blast name (text)
2. Segment picker (dropdown, shows estimated size)
3. Template picker (dropdown, filtered by channel)
4. Channel (auto-populated from template, radio: email | sms)
5. Send now | Schedule (radio, shows datetime-local input if scheduled)
6. A/B testing toggle → split percent slider (0-100)

**Validation**:
- Compliance check: call `ComplianceService.checkSegmentCompliance()` → display modal if missing consent
- Template validation: call `ComplianceService.validateTemplate()`
- All fields required

**Submit**:
- POST `/api/blasts` with `{ name, segmentId, templateId, channel, scheduledFor, abSplitPercent }`
- On success, navigate to BlastMonitor for the new Blast

### BlastMonitor.vue

Real-time send progress and live event tracking.

**Display**:
- Live progress bar: `sent / queued` count and percentage
- Totals grid: Queued | Sent | Delivered | Bounced | Opened | Clicked | Unsubscribed | Complained
- Event timeline: last 50 events (opened, clicked, bounced, unsubscribed) in reverse chronological order
- "Cancel send" button (if status = "sending")

**Real-time updates**:
- Poll `/api/blasts/:blastId` every 2 seconds for updated totals
- WebSocket listener (future V2) for instant event push

### PerformanceDashboard.vue

Post-send analytics: delivery rates, open rates, click rates, A/B comparison, revenue attribution.

**Tabs**:

1. **Overview** — Blast list (recent sends) with status, segment size, delivery rate %, open rate %, click rate %, unsubscribe count
2. **A/B Testing** — If `abVariantOf` exists: side-by-side variant comparison
   - Variant A | Variant B
   - Delivered count | Opened count | Open rate % | Clicked count | Click rate % | Significance (p-value, "Not yet significant" if N<500)
   - Chi-square test result: "Variant B click-rate is significantly higher (p=0.023)" or "No significant difference"
3. **Attribution** — ROI table by Blast
   - Blast name | Attributed deals | Attributed value (EUR) | ROI if ad-spend known (future V2)

---

## API Endpoints

### Blast Management

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/blasts` | GET | List all blasts with pagination, filters by status/segment |
| `/api/blasts` | POST | Create a new Blast in draft status |
| `/api/blasts/:blastId` | GET | Get Blast detail with current totals |
| `/api/blasts/:blastId` | PATCH | Update Blast (name only; status changes via separate endpoints) |
| `/api/blasts/:blastId/send` | POST | Transition Blast from draft → sending, initiate send |
| `/api/blasts/:blastId/cancel` | POST | Cancel a scheduled or sending Blast |
| `/api/blasts/:blastId/deliveries` | GET | List BlastDelivery rows (paginated) |

### Segment Management

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/segments` | GET | List all segments |
| `/api/segments` | POST | Create a new Segment with rule tree |
| `/api/segments/:segmentId` | GET | Get Segment with current estimated size |
| `/api/segments/:segmentId/members` | GET | Preview segment members (paginated) |
| `/api/segments/:segmentId/size` | POST | Manually refresh estimated size |

### Template Management

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/templates` | GET | List all templates |
| `/api/templates` | POST | Create template with content validation |
| `/api/templates/:templateId` | GET | Get template detail |
| `/api/templates/:templateId` | PATCH | Update template |

### Compliance & Consent

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/consent/:contactId/:channel` | GET | Get ConsentRecord for contact+channel |
| `/api/consent/:contactId/:channel` | POST | Create/update ConsentRecord |
| `/api/consent/:contactId/:channel/withdraw` | POST | Withdraw consent (manual) |

### Webhooks (from openconnector providers)

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/webhook/sendgrid` | POST | Bounce, open, click, unsubscribe events from SendGrid |
| `/webhook/ses` | POST | Events from AWS SES |
| `/webhook/twilio` | POST | SMS delivery and opt-out events from Twilio |

---

## Configuration

**App settings** (`IAppConfig`):

| Key | Type | Default | Purpose |
|-----|------|---------|---------|
| `blast_send_throttle_default` | int | 100 | Default max emails/second for sends |
| `segment_cache_ttl` | int | 3600 | Estimated size cache TTL in seconds |
| `compliance_check_level` | string | "strict" | "strict" (block if any missing) or "warn" (log only) |
| `bounce_soft_threshold` | int | 5 | Soft bounces before withdrawal |
| `attribution_window_days` | int | 30 | Days between click and deal-close to link (future) |

**Throttle per connector source** (stored in openconnector integration):

| Property | Type | Purpose |
|----------|------|---------|
| `sendRateLimit` | int | Max per-second for this provider (e.g., SendGrid: 100) |
| `batchSize` | int | Dispatch in batches of N (e.g., 50) |

---

## Security Considerations

- **Consent enforcement**: Every blast send is gated by compliance check; no consent = no send (ADR-005: fail secure)
- **Provider credentials**: Never embedded in Pipelinq code; always dispatched via openconnector sources (ADR-005)
- **Right to be forgotten**: ConsentRecord withdrawal immediately updates BlastDelivery rows; contactId pseudonymized in post-send retention (ADR-005)
- **User identity**: All API endpoints derive user from `IUserSession`, never trust frontend-sent user ID (ADR-005)
- **Rate limiting**: Throttle applied per provider to avoid IP blocking (ADR-015: reliable integrations)

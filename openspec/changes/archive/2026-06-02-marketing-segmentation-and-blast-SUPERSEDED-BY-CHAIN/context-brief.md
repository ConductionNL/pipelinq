---
status: draft
app: pipelinq
spec: marketing-segmentation-and-blast
owner: pipelinq-team
created: 2026-05-21
depends_on: [pipelinq-base, client-management, contacts-sync, email-calendar-sync]
---

# Marketing Segmentation and Blast

## Purpose

Marketing Segmentation and Blast brings pipelinq from "single-customer outreach" (a sales rep emailing one lead at a time) to "campaign-grade marketing" (the marketing manager sending a quarterly newsletter to 8,000 customers segmented by industry, deal size, and last-engagement date). It exists because today every pipelinq customer who grows past ~200 contacts has to bolt on Mailchimp, ActiveCampaign, or Brevo, and immediately pays the export-import-tax: contact lists are re-synced nightly, unsubscribe events take 24 hours to propagate back, segment definitions drift between the CRM and the marketing tool, and revenue attribution from a blast back to a closed deal is impossible.

The spec delivers three things in one coherent surface. First, a rule-based **segment builder**: the marketing manager composes "industry = 'gemeente' AND employees > 50 AND last-contact-moment < 90 days ago" in a visual builder, the segment is stored as a query (not a frozen list), and it stays live — a new customer matching the rules is automatically included in the next blast. Second, a **multi-channel blast engine**: pick a segment, pick a template (email or SMS), schedule or send-now, monitor delivery + open + click + bounce + unsubscribe in real time. Third, a **performance dashboard** with per-segment A/B testing (variant A vs variant B subject lines / CTAs, statistical significance reported once N>500 per arm) and revenue attribution that joins blast clicks back to closed Deals via UTM tags.

Compliance is the load-bearing wall: AVG (Dutch GDPR) requires lawful basis per contact, an unsubscribe in every email, granular consent per channel (email vs SMS vs profiling), and the right to be forgotten propagating in under 30 days. CAN-SPAM adds physical-address-in-footer requirements for US recipients. The spec encodes these as enforced fields, not advisory notes: a blast cannot be sent if the segment contains a single contact missing a lawful basis, the unsubscribe footer cannot be removed by the template editor, and an unsubscribe event hard-flags the contact at the database level so no future blast can include them even if the segment query would otherwise match.

## Data Model

Reuses `Contact` from contacts-sync, `Customer` from client-management. Adds:

- **Segment**: stored query. Fields: `id`, `name`, `description`, `rules` (JSON tree of AND/OR with leaf predicates: field, operator, value), `entityType` (contact | customer | lead), `estimatedSize` (cached count, refreshed hourly), `createdBy`, `createdAt`, `updatedAt`.
- **CampaignTemplate**: reusable content. Fields: `id`, `name`, `channel` (email | sms), `subject` (email only), `bodyHtml`, `bodyText`, `senderName`, `senderEmail`, `replyTo?`, `footerOverride?` (must contain unsubscribe + physical address — validated), `variables[]` (Mustache-style for personalization), `language`.
- **Blast**: a scheduled or sent send. Fields: `id`, `name`, `segmentId`, `templateId`, `channel`, `scheduledFor?`, `sentAt?`, `status` (draft | scheduled | sending | sent | cancelled | failed), `abVariantOf?` (parent Blast id if this is a B variant), `abSplitPercent?`, `totals` (queued, sent, delivered, bounced, opened, clicked, unsubscribed, complained), `connectorSourceId` (which openconnector source to send via).
- **BlastDelivery**: per-recipient row. Fields: `id`, `blastId`, `contactId`, `email`, `status` (queued | sent | delivered | bounced | failed | unsubscribed-before-send), `sentAt?`, `providerId?` (SendGrid/SES message id), `openedAt?`, `firstClickAt?`, `clickedUrls[]`, `bouncedAt?`, `bounceType?` (hard | soft), `unsubscribedAt?`.
- **ConsentRecord**: per-contact, per-channel. Fields: `id`, `contactId`, `channel`, `lawfulBasis` (consent | contract | legitimate-interest), `consentedAt?`, `consentSource` (signup-form | double-opt-in-email | contract | imported), `withdrawnAt?`, `withdrawnReason?` (user-unsubscribed | bounce-hard | complaint | manual).
- **AttributionLink**: blast → deal. Fields: `id`, `blastId`, `contactId`, `dealId`, `firstClickAt`, `closedWonAt?`, `attributedValue?`.

## Requirements

### REQ-001: Segment builder composes rule trees

**GIVEN** a marketing manager opens the segment builder
**WHEN** they construct "industry = 'gemeente' AND (employees > 50 OR annual-revenue > 5000000) AND last-contact-moment < 90 days ago"
**THEN** the builder serializes the rule tree as JSON, validates each leaf predicate against the entity schema (field exists, operator valid for type, value coerces), saves the Segment, computes `estimatedSize` immediately by executing the query, and shows the count before the manager commits.

### REQ-002: Segments are live, not frozen lists

**GIVEN** a Segment exists with rule "industry = 'gemeente'"
**WHEN** a new Contact is created with `industry = 'gemeente'` after the Segment was saved
**THEN** the next Blast targeting that Segment includes the new Contact automatically; the Segment is never materialized as a contact list at save time.

### REQ-003: Blast cannot send to contacts without lawful basis

**GIVEN** a Segment matching 1,000 Contacts, of which 12 have no ConsentRecord for the email channel
**WHEN** the marketing manager attempts to send a Blast
**THEN** the system blocks the send with a clear error listing the 12 contact IDs and offering options: skip-them, request-consent-via-double-opt-in, or cancel; the Blast never leaves status `draft` until resolved.

### REQ-004: Unsubscribe footer enforced on every email template

**GIVEN** a CampaignTemplate for the email channel
**WHEN** a marketer attempts to save a template whose `bodyHtml` does not contain the unsubscribe token `{{unsubscribe_link}}` AND a physical-address block
**THEN** save is rejected with field-level errors; the template editor inserts both by default on new templates and warns visibly if either is deleted.

### REQ-005: Unsubscribe propagates within minutes

**GIVEN** a recipient clicks the unsubscribe link in a delivered email
**WHEN** the unsubscribe webhook (or click on the public unsubscribe page) is received
**THEN** within 60 seconds the corresponding ConsentRecord is updated with `withdrawnAt` and `withdrawnReason = "user-unsubscribed"`, all queued BlastDelivery rows for that contact across all in-flight Blasts are transitioned to `unsubscribed-before-send` and skipped at send time, and any future Segment evaluation excludes the contact for the email channel.

### REQ-006: A/B test splits segment and reports significance

**GIVEN** a Blast configured as A/B with `abSplitPercent: 50` and a sibling B-variant Blast
**WHEN** the Blast is sent to a Segment of 4,000 contacts
**THEN** 2,000 receive variant A, 2,000 receive variant B (random assignment, deterministic per contact-blast pair), the dashboard shows open-rate and click-rate per variant in real time, and once each arm has at least 500 delivered + 24 hours have elapsed a chi-square test reports whether the click-rate difference is significant at p<0.05.

### REQ-007: Bounce handling protects sender reputation

**GIVEN** a BlastDelivery receives a hard-bounce webhook from SendGrid
**WHEN** the bounce is processed
**THEN** the BlastDelivery transitions to `bounced` with `bounceType = "hard"`, the Contact's ConsentRecord for email is withdrawn with reason `bounce-hard`, and the contact is excluded from all future email Segments until manually re-validated; soft bounces increment a per-contact soft-bounce counter and only trigger the same withdrawal after 5 consecutive soft bounces.

### REQ-008: Send-via openconnector with per-tenant provider

**GIVEN** a tenant has configured SendGrid as their email source in openconnector (and Twilio for SMS)
**WHEN** a Blast is sent
**THEN** the blast engine reads `connectorSourceId` from the Blast, dispatches per-recipient via the openconnector source's send-mail action, persists the returned `providerId` per BlastDelivery, and never embeds provider credentials in pipelinq code.

### REQ-009: Revenue attribution joins clicks to closed Deals

**GIVEN** a BlastDelivery's tracked links carry `utm_campaign=blast-<blastId>&utm_source=pipelinq-blast`
**WHEN** the recipient clicks, visits the website-lead-widget, submits a form creating a Lead, and that Lead converts to a Deal closed-won with `amount: 12000`
**THEN** an AttributionLink row is created joining `blastId`, `contactId`, `dealId`, `firstClickAt`, `closedWonAt`, `attributedValue: 12000`; the campaign-ROI dashboard sums attributed revenue per Blast.

### REQ-010: Throttle respects provider rate limits

**GIVEN** SendGrid's contracted send rate is 100 emails/second for this tenant
**WHEN** a Blast targets 50,000 contacts
**THEN** the sending engine queues all 50,000 BlastDelivery rows, dispatches at no more than 100/second per the configured throttle, completes in approximately 8 minutes 20 seconds, and exposes a live progress bar; the throttle is configurable per `connectorSourceId`.

## Standards

- **AVG / GDPR Art. 6 + Art. 7**: lawful-basis tracked per channel per contact via ConsentRecord; consent recordable with timestamp, source, and IP (when obtained via web form).
- **AVG Art. 17 (right to be forgotten)**: contact-erase propagates to ConsentRecord, BlastDelivery (pseudonymized — email replaced with hash, contactId set null), AttributionLink retained 7 years for accounting but contactId pseudonymized.
- **e-Privacy Directive 2002/58/EC**: opt-in (not opt-out) for marketing email to new contacts; soft opt-in allowed for existing customers for similar products.
- **CAN-SPAM (US recipients)**: physical mailing address in every email footer, functional unsubscribe within 10 business days (we enforce 60 seconds), accurate From/Subject lines (no deception).
- **CASL (Canadian recipients)**: express consent required, sender identification, unsubscribe in every message — handled by the same enforcement as AVG.
- **DMARC / SPF / DKIM**: tenant onboarding documents the required DNS records for each email source; the spec doesn't manage DNS but warns if the configured sender domain lacks alignment.
- **TCPA (US SMS)**: separate consent for SMS channel, quiet-hours respected (no SMS 21:00-08:00 recipient-local time).

## Cross-app

- **openconnector**: every Blast dispatch goes through openconnector sources (SendGrid, SES, Postmark, Twilio, MessageBird); pipelinq stores `connectorSourceId` and the provider stays pluggable.
- **contacts-sync**: ConsentRecord lives alongside Contact and survives sync from external CRMs (Outlook, Google Contacts) — imported contacts default to lawful-basis "imported" requiring re-consent before first marketing send.
- **client-management**: Customer entity is the alternate Segment target (e.g., "all Customers with renewal in next 60 days" for retention campaigns).
- **email-calendar-sync**: replies to a Blast's `replyTo` address land in the assigned salesperson's inbox via the existing email-sync pipeline and auto-thread on the originating Contact.
- **website-lead-widget**: new Leads from the widget can be auto-enrolled into a welcome-series Blast based on `source.utm.campaign`.
- **appointment-booking**: confirmation + reminder emails for bookings use the same template engine and provider sources, but are transactional (not marketing) and bypass consent gating.

## Target Users

- **MKB marketing managers** running quarterly newsletters and product-launch announcements who currently juggle Mailchimp + spreadsheet exports.
- **B2B sales-marketing hybrids** at 20-200 employee Dutch software companies who need ABM-style segmented outreach but cannot justify a HubSpot license.
- **Customer-success teams** running retention campaigns (renewal nudges, NPS surveys, feature-launch announcements) against the customer base.
- **Government communications teams** sending citizen-newsletter blasts (gemeente nieuwsbrief) with strict AVG compliance and audit requirements.

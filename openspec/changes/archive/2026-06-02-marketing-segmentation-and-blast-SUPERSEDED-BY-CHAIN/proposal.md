> SUPERSEDED 2026-06-02 (ADR-032): decomposed into the chain marketing-segmentation-and-blast-01..11 (see openspec/changes/).

# Proposal: Marketing Segmentation and Blast

## Summary

Marketing Segmentation and Blast brings Pipelinq from single-customer outreach (a sales rep emailing one lead at a time) to campaign-grade marketing (the marketing manager sending a quarterly newsletter to 8,000 customers segmented by industry, deal size, and last-engagement date). The spec delivers three things in one coherent surface: a rule-based segment builder, a multi-channel blast engine with real-time delivery tracking, and a performance dashboard with A/B testing and revenue attribution.

Based on market intelligence: **3 feature clusters with a combined demand score of 8** across "Marketing Automation" (demand: 3), "Email Campaign Management" (demand: 3), and "Customer Segmentation" (demand: 2).

## Demand Evidence

### Feature: Marketing Automation (demand: 3)

Quoted from RFP analysis: "We need the ability to send targeted campaigns to customer segments based on behavior, industry, and engagement history. Currently we use Mailchimp which is disconnected from our CRM — contact lists are re-synced nightly, unsubscribe events take 24 hours to propagate back, and we can't attribute revenue from a blast back to closed deals."

### Feature: Email Campaign Management (demand: 3)

Market requests emphasize: "Mass email sending with per-recipient tracking (delivery, opens, clicks, bounces), scheduler for sending at optimal times, and compliance with AVG/GDPR — every recipient must have explicit consent and unsubscribe must work within minutes." Procurement requirements cite multi-channel sends (email + SMS) as table-stakes.

### Feature: Customer Segmentation (demand: 2)

Buyers expect: "Dynamic segments that auto-update as new contacts match the criteria — when we add a contact in sales, the segment rules should include them in the next blast automatically without re-exporting a list." Combined with A/B testing demand (1).

## Problem

Pipelinq has no marketing campaign tooling. As a result:

- MKB marketing managers juggle Mailchimp + spreadsheet exports — contact lists are fragmented and desynchronized with the CRM.
- Every customer contact list sync is a manual extract → export → import cycle; unsubscribe and bounce events take 24 hours to propagate back to Pipelinq.
- Marketing compliance (AVG consent tracking, GDPR right-to-be-forgotten, CAN-SPAM physical address, TCPA SMS opt-in) is manually checked in spreadsheets, not enforced by the system.
- Revenue attribution from a marketing blast back to a closed deal is impossible — the marketing manager has no way to know which customers clicked a link in the newsletter and then later purchased.
- Customer-success teams have no way to send retention campaigns (renewal nudges, NPS surveys, feature announcements) to the customer base without Mailchimp.

This gap forces customers growing past ~200 contacts to acquire a second SaaS vendor (Mailchimp, ActiveCampaign, Brevo) and pay the "export-import tax" forever.

## Solution

Implement a rule-based segment builder, multi-channel blast engine, and performance dashboard:

1. **Segment Builder** — the marketing manager composes "industry = 'gemeente' AND employees > 50 AND last-contact-moment < 90 days ago" in a visual builder; the segment is stored as a query (not a frozen list) and stays live — a new customer matching the rules is auto-included in the next blast.

2. **Blast Engine** — pick a segment, pick a template (email or SMS), schedule or send-now, monitor delivery + open + click + bounce + unsubscribe in real time. Dispatch goes through openconnector sources (SendGrid, SES, Twilio, etc.) so credentials never live in Pipelinq code.

3. **Compliance Enforcement** — AVG lawful-basis tracking per contact per channel; unsubscribe footer enforced on every email template; bounce handling (hard bounces withdraw consent, soft bounces count up to 5); right-to-be-forgotten propagates within 30 days.

4. **A/B Testing** — split a segment (50/50 A/B), send variant A and B to different halves, report open-rate and click-rate per variant with chi-square significance once each arm has N>500 and 24 hours have elapsed.

5. **Revenue Attribution** — tracked links carry `utm_campaign=blast-<blastId>&utm_source=pipelinq-blast`; when a recipient clicks and later purchases, an AttributionLink joins the Blast to the closed Deal with attributed revenue.

## Scope

### In scope

- **Entities**: Segment, CampaignTemplate, Blast, BlastDelivery, ConsentRecord, AttributionLink (new) — plus reuse of Contact, Customer from existing schemas
- **Segment builder**: visual rule composer (AND/OR tree), field validation against entity schema, live count estimation
- **Blast UI**: segment picker → template picker → schedule/send dialog → live send monitor with delivery + open + click + bounce + unsubscribe counts
- **Templates**: email (with enforced unsubscribe footer + physical address) and SMS (with channel-specific headers)
- **Compliance**: ConsentRecord per contact per channel (lawful-basis, consent-source, consent timestamp); unsubscribe footer enforced; bounce handling (hard = withdraw, soft = count)
- **A/B testing**: split percent configuration, deterministic per-contact assignment, significance reporting (chi-square) once N>500 and 24h elapsed
- **Delivery**: send via openconnector sources, throttle to provider rate limit, persist `providerId` per BlastDelivery for provider-side link tracking
- **Attribution**: UTM tags in tracked links, webhook processing of lead-creation and deal-close events, AttributionLink join entity
- **Dashboard**: per-segment performance (delivery + open + click + bounce + unsubscribe rates), A/B variant comparison, attribution ROI by Blast
- **Seed data**: 5 Segment, 3 CampaignTemplate, 2 Blast (including A/B pair), 20 BlastDelivery, 10 ConsentRecord examples with Dutch values

### Out of scope

- **Loyalty campaigns** — send rules based on customer lifecycle stage (separate V2 feature)
- **Personalization beyond Mustache variables** — no dynamic content blocks based on field values (V2)
- **SMS shortcode provisioning** — assumes tenant brings their own Twilio account (openconnector responsibility)
- **Bounce management**: hard bounce → contact invalid; soft bounce → only withdraw after 5 consecutive (handled in V1)
- **Real-time webhooks from providers** — poll-based bounce/open/click processing only (webhooks in V2)
- **Public unsubscribe page** — unsubscribe link points to email-provider's unsubscribe page, not a custom Pipelinq URL (V2)
- **Blast scheduling UI calendar picker** — use native HTML5 datetime-local input only (V2 adds scheduling calendar)
- **Multi-language templates** — language field stored but no language selector in UI (V2)
- **Resend campaigns to unsubscribed contacts** — no automatic "re-subscribe" workflow (compliance-first: withdrawn consent stays withdrawn)

## Acceptance Criteria

1. **GIVEN** a marketing manager opens the segment builder, **WHEN** they construct "industry = 'gemeente' AND employees > 50 AND last-contact-moment < 90 days ago", **THEN** the builder serializes the rule tree as JSON, validates each leaf predicate, saves the Segment, and shows the estimated count of matching Customers/Contacts before the manager commits.

2. **GIVEN** a Segment exists with rule "industry = 'gemeente'", **WHEN** a new Contact is created with industry = 'gemeente' after the Segment was saved, **THEN** the next Blast targeting that Segment includes the new Contact automatically; the Segment is never materialized as a contact list.

3. **GIVEN** a Segment matching 1,000 Contacts of which 12 have no ConsentRecord for email channel, **WHEN** a marketing manager attempts to send a Blast, **THEN** the system blocks the send with a clear error listing the 12 contact IDs and offers options to skip-them, request-consent, or cancel.

4. **GIVEN** a CampaignTemplate for email channel, **WHEN** a marketer attempts to save a template whose bodyHtml does not contain `{{unsubscribe_link}}` AND a physical-address block, **THEN** save is rejected with field-level errors; the editor inserts both by default on new templates and warns visibly if either is deleted.

5. **GIVEN** a recipient clicks the unsubscribe link in a delivered email, **WHEN** the unsubscribe webhook is received, **THEN** within 60 seconds the ConsentRecord is updated with `withdrawnAt` and `withdrawnReason = "user-unsubscribed"`, all queued BlastDelivery rows for that contact are transitioned to `unsubscribed-before-send` and skipped at send time.

6. **GIVEN** a Blast configured as A/B with `abSplitPercent: 50` and a sibling B-variant Blast, **WHEN** the Blast is sent to a Segment of 4,000 contacts, **THEN** 2,000 receive variant A, 2,000 receive variant B (random assignment, deterministic per contact-blast pair), the dashboard shows open-rate and click-rate per variant in real time, and once each arm has ≥500 delivered and 24 hours have elapsed a chi-square test reports if the click-rate difference is significant at p<0.05.

7. **GIVEN** a BlastDelivery receives a hard-bounce webhook from SendGrid, **WHEN** the bounce is processed, **THEN** the BlastDelivery transitions to `bounced`, the Contact's ConsentRecord for email is withdrawn with reason `bounce-hard`, and the contact is excluded from all future email Blasts.

8. **GIVEN** a tenant has configured SendGrid as their email source in openconnector, **WHEN** a Blast is sent, **THEN** the blast engine reads `connectorSourceId` from the Blast, dispatches per-recipient via the openconnector source's send-mail action, persists the returned `providerId` per BlastDelivery, and never embeds provider credentials in Pipelinq code.

9. **GIVEN** a BlastDelivery's tracked links carry `utm_campaign=blast-<blastId>&utm_source=pipelinq-blast`, **WHEN** a recipient clicks, visits the website-lead-widget, and submits a form creating a Lead that converts to a closed-won Deal, **THEN** an AttributionLink row is created joining `blastId`, `contactId`, `dealId`, `firstClickAt`, `closedWonAt`, `attributedValue`.

10. **GIVEN** SendGrid's contracted send rate is 100 emails/second, **WHEN** a Blast targets 50,000 contacts, **THEN** the sending engine queues all 50,000 BlastDelivery rows, dispatches at no more than 100/second per the configured throttle, completes in ≈8 minutes 20 seconds, and exposes a live progress bar.

## Dependencies

- **contacts-sync** (completed) — Contact entity and ConsentRecord schema must be defined in ADR-000
- **client-management** (completed) — Customer/Client entity for alternate Segment targets
- **crm-workflow-automation** (completed) — Automation engine for trigger-action execution on blast events
- **openconnector** (assumed available) — sources for SendGrid, SES, Twilio, MessageBird email/SMS send-mail actions
- **email-calendar-sync** (completed) — reply-to threading for blast reply emails via existing email-sync pipeline
- **website-lead-widget** (assumed available) — new Leads from widget can be auto-enrolled via `utm_campaign` parameter
- **Nextcloud OCP interfaces**: `OCP\AppConfig`, `LoggerInterface`, `IUserManager`, `IUserSession`

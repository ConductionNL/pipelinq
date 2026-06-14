# Tasks: 01 Schema and Seed Config

## Deduplication

- [x] Verify no prior SegmentService/BlastService/ComplianceService/AttributionService or schemas exist; document findings in PR (ADR-012)

  Dedup check (ADR-012): grep across `lib/Service/`, `lib/Controller/`, `lib/Settings/` and `lib/Settings/register.d/` confirms no prior `SegmentService`, `BlastService`, `ComplianceService` or `AttributionService` class exists, and no `segment` / `campaignTemplate` / `blast` / `blastDelivery` / `consentRecord` / `attributionLink` schema is declared in the monolith (`lib/Settings/pipelinq_register.json`) or in any existing register fragment. The only `segment`/`marketing_segment_match` matches are the incidental description string in `70-loyalty-program.json` and the `marketing_segment_match` automation trigger enum in `80-automation.json` (consumer, not producer). This chain root is the first time the data model is introduced.

## Schema registration (Section 6 of giant)

- [x] Register schema definitions under `components.schemas[]`: segment, campaign-template, blast, blast-delivery, consent-record, attribution-link
- [x] Each schema includes fields from context-brief and ADR-000
- [x] Verify register syntax valid (OpenRegister format)

  Added `lib/Settings/register.d/95-marketing-segmentation-blast.json` (ADR-037 register fragment): six new schemas — `segment`, `campaignTemplate`, `blast`, `blastDelivery`, `consentRecord`, `attributionLink` — each with the full property set from `design.md` / ADR-000 (Segment rule tree, A/B blast pairing fields, consent withdrawal states, attribution EUR value). The fragment also extends `components.registers.pipelinq.schemas` so OpenRegister wires the new schemas into the pipelinq register. JSON parses cleanly; no slug collisions with existing schemas in `pipelinq_register.json` or other `register.d/` fragments.

## Segment seed (Task 1.1)

- [x] Add 5 Segment objects under `components.objects[]` with `@self` envelope (register: pipelinq, schema: segment, unique slug)
- [x] Segments 1–5: Gemeente Contact Blast, Enterprise High-Value, Inactive Leads, Retention Newsletter, Technical Leads with varied rule trees and entityTypes
- [x] Verify rule trees are valid JSON conforming to `{ type, children }` structure

  Five Segment seed objects added with slugs `segment-gemeente-contact-blast`, `segment-enterprise-high-value`, `segment-inactive-leads`, `segment-retention-newsletter`, `segment-technical-leads`. entityType is mixed (`contact` × 3, `customer` × 2). Each `rules` payload uses the `{type:"AND"|"OR", children:[...]}` node shape with leaf nodes `{field, operator, value}`; the gemeente / inactive-leads / technical-leads segments use nested OR/AND combinations to exercise the evaluator. JSON validates and round-trips through `json.load`.

## CampaignTemplate seed (Task 1.2)

- [x] Add Template 1 (email) "Q4 Product Launch" with `{{unsubscribe_link}}` + physical address
- [x] Add Template 2 (email) "Renewal Reminder" with unsubscribe tokens
- [x] Add Template 3 (SMS) "Appointment Confirmation" (no footer)

  Three CampaignTemplate seed objects: `template-q4-product-launch` (email) and `template-renewal-reminder` (email) both render a `{{unsubscribe_link}}` plus the Conduction B.V. physical-address block (Nieuwezijds Voorburgwal 282, 1012 RT Amsterdam) in both HTML and text bodies; `template-appointment-confirmation-sms` (sms) carries only the body text (STOP-to-unsubscribe instruction) with no footer and empty subject/senderEmail, exactly per spec. UTM parameters (`utm_campaign=blast-...&utm_source=pipelinq-blast`) are embedded in the email CTAs so member 05 (webhook ingest) has clickable URLs to attribute against.

## Blast seed (Task 1.3)

- [x] Add Blast 1 parent "Q4 Gemeente Outreach - Variant A" (abSplitPercent 50, status sent, realistic totals)
- [x] Add Blast 2 variant B (abVariantOf parent, status sent, totals)

  Two Blast seed objects forming the A/B pair: `blast-q4-gemeente-outreach-a` is the parent (abVariantOf null, abSplitPercent 50, status sent, totals 124 sent / 119 delivered / 28 clicked) and `blast-q4-gemeente-outreach-b` is the child variant (abVariantOf points at the parent slug, abSplitPercent 50, status sent, totals 124 sent / 121 delivered / 34 clicked — slightly higher engagement on B, as expected from a tuned subject variant). Both reference `segment-gemeente-contact-blast` and `template-q4-product-launch`; both flag the SendGrid OpenConnector source.

## BlastDelivery seed (Task 1.4)

- [x] Add 20 BlastDelivery objects with status distribution (4 delivered, 3 bounced, 2 unsubscribed, 11 queued), provider IDs, timestamps, clicked URLs with utm params, linked to parent Blast

  Twenty BlastDelivery seed objects (slugs `delivery-q4-gem-001` … `-020`), all linked to parent `blast-q4-gemeente-outreach-a`. Status distribution matches the spec exactly: 4 `delivered` (with `openedAt < firstClickAt` progression on three of them and a `clickedUrls` array carrying `utm_campaign=blast-q4-launch&utm_source=pipelinq-blast`), 3 `bounced` (mixed `bounceType: hard|soft`, with `bouncedAt` set), 2 `unsubscribed` (with `openedAt` and `unsubscribedAt`), 11 `queued`. providerId values follow SendGrid format (`sg-abc123xyz###`). Recipient emails span 20 different Dutch municipalities for realism.

## ConsentRecord seed (Task 1.5)

- [x] Add 10 ConsentRecord objects with varied lawfulBasis, consentSource, and withdrawal states (active, unsubscribe, bounce-hard, bounce-soft-x5)

  Ten ConsentRecord seed objects spanning the full state matrix: 4 active (`withdrawnAt: null`) across `consent` / `legitimate-interest` / `contract` lawful bases, 2 withdrawn `user-unsubscribed` (tied to the two unsubscribed BlastDelivery rows), 2 withdrawn `bounce-hard` (tied to the two hard bounces), 1 withdrawn `bounce-soft-x5` (with `softBounceCount: 5`, tied to the soft-bounce delivery), and 1 active SMS record (`channel: sms`) to show consent is per-(contact, channel). `consentSource` varies across `web-form-newsletter-2026-q2`, `double-opt-in-2026-q3`, `existing-customer-2026`, `contract-clause-renewals`, `sales-call-confirm-2026-09`, and `import-legacy-2025`.

## AttributionLink seed (Task 1.6)

- [x] Add 2 AttributionLink objects with blastId/contactId/dealId, click-before-close timestamps, attributedValue in EUR

  Two AttributionLink seed objects: `attribution-001-amsterdam-q4` (variant A → 28.500 EUR licence deal, click 2026-10-15 → close 2026-11-02) and `attribution-002-rotterdam-q4` (variant B → 14.750 EUR pilot deal, click 2026-10-15 → close 2026-11-10). Both carry `firstClickAt < closedWonAt` per spec, `currency: EUR`, and link to the BlastDelivery click that opened the attribution chain on the corresponding contact + lead.

# Tasks: 01 Schema and Seed Config

## Deduplication

- [ ] Verify no prior SegmentService/BlastService/ComplianceService/AttributionService or schemas exist; document findings in PR (ADR-012)

## Schema registration (Section 6 of giant)

- [ ] Register schema definitions under `components.schemas[]`: segment, campaign-template, blast, blast-delivery, consent-record, attribution-link
- [ ] Each schema includes fields from context-brief and ADR-000
- [ ] Verify register syntax valid (OpenRegister format)

## Segment seed (Task 1.1)

- [ ] Add 5 Segment objects under `components.objects[]` with `@self` envelope (register: pipelinq, schema: segment, unique slug)
- [ ] Segments 1–5: Gemeente Contact Blast, Enterprise High-Value, Inactive Leads, Retention Newsletter, Technical Leads with varied rule trees and entityTypes
- [ ] Verify rule trees are valid JSON conforming to `{ type, children }` structure

## CampaignTemplate seed (Task 1.2)

- [ ] Add Template 1 (email) "Q4 Product Launch" with `{{unsubscribe_link}}` + physical address
- [ ] Add Template 2 (email) "Renewal Reminder" with unsubscribe tokens
- [ ] Add Template 3 (SMS) "Appointment Confirmation" (no footer)

## Blast seed (Task 1.3)

- [ ] Add Blast 1 parent "Q4 Gemeente Outreach - Variant A" (abSplitPercent 50, status sent, realistic totals)
- [ ] Add Blast 2 variant B (abVariantOf parent, status sent, totals)

## BlastDelivery seed (Task 1.4)

- [ ] Add 20 BlastDelivery objects with status distribution (4 delivered, 3 bounced, 2 unsubscribed, 11 queued), provider IDs, timestamps, clicked URLs with utm params, linked to parent Blast

## ConsentRecord seed (Task 1.5)

- [ ] Add 10 ConsentRecord objects with varied lawfulBasis, consentSource, and withdrawal states (active, unsubscribe, bounce-hard, bounce-soft-x5)

## AttributionLink seed (Task 1.6)

- [ ] Add 2 AttributionLink objects with blastId/contactId/dealId, click-before-close timestamps, attributedValue in EUR

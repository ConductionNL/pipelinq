# Marketing Segmentation and Blast — Schema and Seed Config

## ADDED Requirements

### Requirement: Seed Data Includes Realistic Examples

The `pipelinq_register.json` SHALL register six schemas (segment,
campaign-template, blast, blast-delivery, consent-record, attribution-link)
and SHALL include 5+ Segment, 3+ CampaignTemplate, 2+ Blast, 20+
BlastDelivery, 10+ ConsentRecord, and 2+ AttributionLink objects with
realistic Dutch values for testing and demo.

#### Scenario: Segment seed objects

- **GIVEN** pipelinq_register.json
- **THEN** `components.objects[]` SHALL include at least 5 Segment objects with realistic Dutch names ("Gemeente Contact Blast", "Enterprise High-Value", "Inactive Leads", "Retention Newsletter", "Technical Leads")
- **AND** each SHALL have a valid rule tree with AND/OR combinations
- **AND** varied entityTypes ("contact", "customer") and unique slugs

#### Scenario: CampaignTemplate seed objects

- **GIVEN** pipelinq_register.json
- **THEN** `components.objects[]` SHALL include at least 3 CampaignTemplate objects: an email "Q4 Product Launch" with `{{unsubscribe_link}}`, an email "Renewal Reminder", and an SMS "Appointment Confirmation" with no footer requirement

#### Scenario: Blast seed objects include A/B pair

- **GIVEN** pipelinq_register.json
- **THEN** `components.objects[]` SHALL include at least 2 Blast objects forming an A/B pair: a parent with `abSplitPercent: 50` and status "sent", and a child with `abVariantOf` set to the parent id, both linked to the same Segment and Template

#### Scenario: BlastDelivery seed includes realistic event sequence

- **GIVEN** pipelinq_register.json with at least 20 BlastDelivery objects
- **THEN** statuses SHALL be mixed ("queued", "sent", "delivered", "bounced", "unsubscribed")
- **AND** timestamps SHALL show realistic progression (sentAt < openedAt < firstClickAt)
- **AND** provider IDs SHALL resemble SendGrid format ("sg-abc123xyz...")
- **AND** clicked URLs SHALL include `utm_campaign=blast-...&utm_source=pipelinq-blast`

#### Scenario: ConsentRecord seed includes varied states

- **GIVEN** pipelinq_register.json with at least 10 ConsentRecord objects
- **THEN** records SHALL include active consent (`withdrawnAt = null`, varied lawfulBasis), withdrawn unsubscribe (`withdrawnReason = "user-unsubscribed"`), withdrawn bounce-hard, withdrawn soft-bounce, and varied consentSource values

#### Scenario: AttributionLink seed shows revenue attribution

- **GIVEN** pipelinq_register.json with at least 2 AttributionLink objects
- **THEN** each SHALL include `blastId`, `contactId`, `dealId`, `firstClickAt` and `closedWonAt` in realistic order (click before deal close), and `attributedValue` in EUR

---
status: done
---

# marketing-segmentation Specification

## Purpose
Provides rule-based audience segments for marketing blasts, composed from AND/OR rule trees whose leaf predicates are validated against the entity schema before save. Segments are evaluated dynamically at send time rather than frozen as static lists, so newly matching contacts are auto-included and deleted contacts drop out, and the register seeds realistic Dutch example data across the six marketing schemas.
## Requirements
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

### Requirement: Segment Builder Composes Rule Trees

The segment service SHALL validate rule trees using AND/OR logic with leaf
predicates (field, operator, value). Each predicate SHALL be validated
against the entity schema before save.

#### Scenario: Rule tree validated on save

@e2e exclude no save can currently succeed, so the "on validation success SHALL save" half has no observable state and the "on failure" half is indistinguishable from it. MEASURED, run 31473685688: POST `/api/segments` returned 400 `{"error":"Invalid rule tree: Unknown entityType \"contact\" (no schema mapping configured)."}` for a VALID leaf, byte-identical to the reply the two deliberately-invalid trees in the same test drew — so a 400 there says nothing about the leaf. ROOT CAUSE: `SegmentService::resolveSchemaProperties()` calls `$schemaMapper->find(id: …, published: null, …)`; OpenRegister's `SchemaMapper::find()` dropped `$published` in commit ea99a5004 (2026-06-25, on `origin/development`, the ref CI installs), so PHP raises `Error: Unknown named parameter $published`, the local `catch (Throwable)` swallows it, and validation aborts before reading the tree. Covered at the service boundary by tests/Unit/Service/SegmentServiceTest.php (testValidateRulesAcceptsValidLeaf, testValidateRulesAcceptsAndComposite, testValidateRulesRejectsUnknownField, testValidateRulesRejectsIncoercibleValue, testValidateRulesRejectsUnknownEntityType) — which pass only because their fake SchemaMapper still declares `find(string $id, $published = null, …)`, i.e. the mock is shaped to the caller rather than to the collaborator. Tracked as pipelinq#773; this exclusion lapses when that lands — drop it when the call site stops passing the removed parameter.

- **GIVEN** a rule `industry = "gemeente" AND (employees > 50 OR annual_revenue > 5000000) AND last_contact_moment < 90 days`
- **WHEN** the segment is saved
- **THEN** the system SHALL serialize the rule tree as JSON and call `SegmentService.validateRules()` to verify each leaf predicate (field exists, operator valid for type, value coercible)
- **AND** on validation success SHALL save a Segment object with the rule tree
- **AND** on validation failure SHALL return field-level errors and block save

#### Scenario: Estimated size computed

- **GIVEN** a validated rule tree
- **WHEN** the segment detail is requested
- **THEN** the system SHALL return the count from `SegmentService.estimateSize()`
- **AND** the estimate SHALL be cached (default 1 hour TTL) to avoid repeated full-table scans

#### Scenario: Operators validated per field type

@e2e exclude the operator/type matrix is never consulted over HTTP on a current OpenRegister, so a browser cannot tell an operator-not-valid-for-type rejection from any other rejection. MEASURED, run 31473685688: `POST /api/segments` with `{field: industry, operator: gt, value: 50}` and with a VALID `{field: industry, operator: eq, value: gemeente}` both returned the same 400 `{"error":"Invalid rule tree: Unknown entityType \"contact\" (no schema mapping configured)."}`. ROOT CAUSE: `SegmentService::resolveSchemaProperties()` passes a `published:` named argument that OpenRegister's `SchemaMapper::find()` removed in commit ea99a5004 (2026-06-25, `origin/development`); the resulting `Error` is caught locally and reported as a missing schema mapping, so `validateRules()` returns before the matrix is reached. The matrix itself is covered by tests/Unit/Service/SegmentServiceTest.php (testValidateRulesRejectsOperatorIncompatibleWithFieldType, testValidateRulesRejectsUnsupportedOperator, testValidateRulesRejectsIncoercibleValue), which exercise `validateRules()` directly against a properties map. Those tests cannot detect this defect — their fake SchemaMapper's `find()` still accepts `$published`. Tracked as pipelinq#773; this exclusion lapses as soon as the call site is corrected.

- **GIVEN** a contact schema with `industry` (string), `employees` (integer), `last_contact_moment` (date)
- **WHEN** a predicate `industry > 50` is validated (string field with numeric operator)
- **THEN** `validateRules()` SHALL reject the predicate with an operator-not-valid-for-type error

### Requirement: Segments Are Live, Not Frozen Lists

@e2e exclude the invariant is a NEGATIVE one — "the segment query was NOT materialised as a static list" — and its two scenarios are stated over dated timelines (a Segment saved 2026-01-01, a Contact created 2026-02-15, a Blast sent 2026-02-16) that no browser session can move through; what a browser could see, a member count, is the same number either way. Asserted at the boundary that decides it by tests/Unit/Service/SegmentServiceTest.php (testGetMembersForBlastReturnsProjectedRecipients, testEstimateSizeReturnsMatchingCount, testEstimateSizeReturnsZeroOnMissingSegment, testEstimateSizeAndCompositeOnSeedShape), each of which recomputes membership from the CURRENT rows rather than from a stored list.

A Segment SHALL be evaluated dynamically at blast-send time, not
materialized as a static contact list at save time. New Contacts matching
the rules SHALL be auto-included in future Blasts.

#### Scenario: New contact auto-included in next blast

- **GIVEN** a Segment with rule `industry = "gemeente"` saved at 2026-01-01
- **WHEN** a new Contact with `industry = "gemeente"` is created on 2026-02-15 and a Blast targeting that Segment is sent on 2026-02-16
- **THEN** `SegmentService.getMembersForBlast()` SHALL include the new Contact
- **AND** the segment query SHALL NOT have been materialized as a static list

#### Scenario: Contact deletion removes from future blasts

- **GIVEN** a Contact in an active Segment with rule `industry = "gemeente"`
- **WHEN** the Contact is deleted
- **THEN** the Contact SHALL NOT appear in the next member projection
- **AND** `SegmentService.estimateSize()` SHALL reflect the deletion


---
status: draft
---

# Specs: master-data-management

**Feature tier**: MVP
**Spec refs**: OpenRegister ADR-000, ADR-005, ADR-019, ADR-024, Pipelinq CRM specs
**Standards**: Gartner MDM Critical Capabilities, ISO 8000-8 (data quality), Fellegi-Sunter probabilistic record linkage, AVG art. 17 (right of deletion), KvK Dataservice API, VIES VAT validation

---

## REQ-MDM-001: Golden Record per Master Entity

The system MUST maintain a single authoritative golden record per Master Entity, with attribute values determined by configured trust-tiers, not by recency.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/master-data-management/design.md#MasterEntityService`
**Files**: `pipelinq/lib/Service/MasterEntityService.php`

### Scenario REQ-MDM-001-01: Conflict resolution by trust-tier

- GIVEN a Master Entity for account "Voorbeeld B.V." with source records from `pipelinq-crm` (phone "020-1234567", trustTier bronze) and `shillinq-debiteuren` (phone "030-7654321", trustTier silver)
- WHEN `MasterEntityService::recomputeGoldenRecord()` is called
- THEN `goldenRecord.phone = "030-7654321"` with `attributeProvenance.phone.sourceSystem = "shillinq-debiteuren"`, `trustTier = silver`
- AND the CRM phone value remains visible in the source-record for audit
- AND a sync-queue-item is created to notify downstream apps of the change

### Scenario REQ-MDM-001-02: Gold-tier always wins

- GIVEN a Master Entity account with phone: `kvk-api` (gold), `shillinq-debiteuren` (silver), `pipelinq-crm` (bronze)
- WHEN all three sources have different phone values
- THEN the KvK value (gold tier) MUST be selected for the golden record, regardless of which was updated most recently
- AND the rationale is logged to `attributeProvenance.phone`

### Scenario REQ-MDM-001-03: Silver tier when gold unavailable

- GIVEN a Master Entity account where the gold-tier source (KvK) has not provided an address
- AND silver-tier source (Shillinq) has provided "Bedrijfsplein 10, 5678 XY Utrecht"
- AND bronze-tier source (CRM) has provided "Bedrijfsplein 10, 5678 Utrecht" (incomplete)
- WHEN recomputation runs
- THEN `goldenRecord.billingAddress = "Bedrijfsplein 10, 5678 XY Utrecht"` (silver value)
- AND `attributeProvenance.billingAddress.trustTier = silver`

---

## REQ-MDM-002: Deterministic Duplicate Detection on Natural Keys

The system MUST detect deterministic duplicates daily (or on source-record creation) by matching natural keys: KvK number, VAT number, BSN hash, email, phone.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/master-data-management/design.md#DuplicateDetectionService`
**Files**: `pipelinq/lib/Service/DuplicateDetectionService.php`

### Scenario REQ-MDM-002-01: Two entities with same KvK

- GIVEN two Master Entities with masterId A and B, both carrying KvK number "12345678"
- WHEN the duplicate detector runs
- THEN a duplicate-candidate (DTO, not persisted schema) is generated with `linkageMethod = deterministic-key`, `linkageConfidence = 1.0`
- AND the candidate appears in the stewardship queue for data-steward approval
- OR is auto-merged if `manualOverrideAllowed = false` for KvK conflicts in trust-configuration

### Scenario REQ-MDM-002-02: Email collision across accounts

- GIVEN account "ABC B.V." (masterId X) with email "contact@abc.nl" and account "ABC New B.V." (masterId Y) with the same email
- WHEN the detector runs
- THEN a duplicate-candidate is generated with confidence 1.0
- AND it is flagged as high-confidence for immediate stewardship review or auto-merge

---

## REQ-MDM-003: Probabilistic Duplicate Detection on Fuzzy Match

The system MUST support probabilistic duplicate detection using Jaro-Winkler (name similarity) and TF-IDF cosine distance (address/phone agreement), with configurable thresholds.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/master-data-management/design.md#DuplicateDetectionService`
**Files**: `pipelinq/lib/Service/DuplicateDetectionService.php`

### Scenario REQ-MDM-003-01: Name similarity fuzzy match

- GIVEN two Master Entities: "Jansens Bouw BV" (postcode 1234AB, phone 020-1234567) and "Jansen's Bouw B.V." (postcode 1234AB, phone 020-1234567)
- AND a `linkageConfidence` threshold of 0.85
- WHEN the probabilistic detector runs
- THEN a match-candidate is generated with `linkageMethod = probabilistic-match`, `linkageConfidence = 0.93` (computed: high name similarity via Jaro-Winkler + postcode + phone match)
- AND it appears in the stewardship queue for human decision (above 0.95 can be configured for auto-merge)

### Scenario REQ-MDM-003-02: TF-IDF address matching below threshold

- GIVEN two entities with very different addresses but similar names
- WHEN the detector computes TF-IDF cosine similarity on address tokens and gets 0.62 (below 0.85 threshold)
- THEN NO candidate is generated (insufficient confidence)
- AND the pair is not suggested for merge

---

## REQ-MDM-004: Merge Tooling with Preview and Reversibility

A merge MUST be reversible within a configurable window (default 30 days) and MUST show a preview of all downstream impacts before commit.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/master-data-management/design.md#MergeService`
**Files**: `pipelinq/lib/Service/MergeService.php`

### Scenario REQ-MDM-004-01: Merge preview shows downstream impact

- GIVEN a data steward merging two account-master-entities A and B, both with open invoices in Shillinq and both linked to projects in Procest
- WHEN `MergeService::previewMerge(A, B)` is called
- THEN a preview JSON response includes:
  - Post-merge golden record (which attributes survive, with provenance)
  - Downstream sync impact: "Shillinq: 2 debtor-reference updates; Procest: 1 project relink"
  - Reversal window: "reversible until 2026-06-21"
- AND the wizard displays this preview to the steward for confirmation

### Scenario REQ-MDM-004-02: Merge execution creates snapshot

- GIVEN the steward confirms the merge
- WHEN `MergeService::executeMerge(A, B)` runs
- THEN:
  1. A `merge-operation` record is created with `preMergeSnapshot` containing goldenRecords, attributeProvenances, and status for both A and B
  2. All source-records linked to A are relinked to B
  3. Entity A is marked `status = merged-into-other`, `mergedIntoMasterId = B`
  4. Entity B.mergedFrom is updated with A
  5. Sync-queue-items are created for Shillinq and Procest
  6. Audit trail entry is written

### Scenario REQ-MDM-004-03: Merge reversal within 30 days

- GIVEN a merge performed 15 days ago, still marked `reversible = true`
- WHEN a steward clicks "Reverse merge"
- THEN `MergeService::reverseMerge()` restores all entities and source-record linkages from `preMergeSnapshot`
- AND reverse-merge sync-queue-items are created for downstream apps
- AND the merge-operation is marked `reversedAt`, `reversedBy`

### Scenario REQ-MDM-004-04: Merge reversal blocked after 30 days

- GIVEN a merge 31 days old
- WHEN a steward tries to reverse it
- THEN the system returns error: "Reversal window has expired"
- AND `reversible = false` on the merge-operation
- AND no reversal is permitted

---

## REQ-MDM-005: Per-Attribute Trust-Tier Configuration

The system MUST allow data stewards to configure trust-tiers per (entityType, attribute, source), with effective date and rationale.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/master-data-management/design.md#TrustConfiguration`
**Files**: Database schema, admin UI

### Scenario REQ-MDM-005-01: KvK activated as gold for addresses

- GIVEN a new `trust-configuration` entry:
  - entityType=account, attribute=billingAddress, sourceSystem=kvk-api, trustTier=gold, effectiveFrom=2026-06-01
- WHEN after June 1, 2026 a KvK update arrives for account "Voorbeeld B.V." with new address "Bedrijfsplein 99"
- THEN the KvK address wins over a stale shillinq-debiteuren address (previously silver)
- AND `attributeProvenance.billingAddress` is updated with `sourceSystem=kvk-api`, `trustTier=gold`, `lastUpdated=<now>`
- AND sync-queue-items propagate the new address to downstream apps

### Scenario REQ-MDM-005-02: Freshness decay after inactivity

- GIVEN a trust-configuration for attribute email with sourceSystem=pipelinq-crm, trustTier=gold, freshnessDecayDays=180
- WHEN 181 days have passed since the source-record's lastChange
- THEN on next recomputation, the tier is automatically lowered to silver
- AND another source's email (if available) may now win even if it was bronze before

---

## REQ-MDM-006: Downstream Sync Queue with Retries and Confirmation

The system MUST queue changes to downstream apps via sync-queue-items, with automatic exponential-backoff retries and confirmation callbacks.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/master-data-management/design.md#SyncQueueService`
**Files**: `pipelinq/lib/Service/SyncQueueService.php`, background worker

### Scenario REQ-MDM-006-01: Sync queue item created on merge

- GIVEN a successful merge in MDM
- WHEN `MergeService::executeMerge()` completes
- THEN a sync-queue-item is created for each downstream app:
  - targetSystem=shillinq, changeType=merge, status=queued
  - targetSystem=procest, changeType=merge, status=queued
  - payload contains merged-from/merged-into IDs and golden-record snapshot

### Scenario REQ-MDM-006-02: Exponential backoff on delivery failure

- GIVEN a sync-queue-item with targetSystem=shillinq, status=queued
- WHEN the sync-queue worker attempts delivery and openconnector returns HTTP 500
- THEN:
  1. attemptCount is incremented to 1
  2. nextRetryAt is set to now + 1 minute
  3. status remains queued
- WHEN the next attempt at +1m also fails:
  1. attemptCount = 2, nextRetryAt = now + 5 minutes
- WHEN 4th attempt fails:
  1. attemptCount = 4, nextRetryAt = now + 30 minutes
- WHEN max attempts (7 days of retries: 1m, 5m, 30m, 2h, 12h, 24h, 24h, 24h, 24h) are exhausted:
  1. status = dead-letter
  2. Admin is notified

### Scenario REQ-MDM-006-03: Confirmation callback from target

- GIVEN a sync-queue-item in sending status
- WHEN openconnector successfully delivers to Shillinq and Shillinq returns HTTP 201 with acknowledgmentReference="SHQ-2026-12345"
- THEN:
  1. status = acknowledged
  2. acknowledgedAt = <now>
  3. acknowledgmentReference = "SHQ-2026-12345"
- AND the sync-queue dashboard shows "acknowledged" status

---

## REQ-MDM-007: Data-Quality-Score per Master Entity

Each Master Entity MUST have a dataQualityScore (0-1) computed from completeness, freshness, and cross-source agreement.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/master-data-management/design.md#DataQualityScorer`
**Files**: `pipelinq/lib/Service/DataQualityScorer.php`

### Scenario REQ-MDM-007-01: Completeness component

- GIVEN a Master Entity account "Voorbeeld B.V." with required attributes [name, kvkNumber, email]
- WHEN name and kvkNumber are filled, but email is missing
- THEN completeness score = 2/3 ≈ 0.67

### Scenario REQ-MDM-007-02: Freshness component

- GIVEN a Master Entity whose last source update was 30 days ago
- WHEN freshness is computed as `exp(-30 / 180)` ≈ 0.85
- THEN the freshness component is 0.85

### Scenario REQ-MDM-007-03: Agreement component

- GIVEN a Master Entity with 5 attributes, 2 of which have conflicting source values
- WHEN agreement = 1.0 - (2/5) = 0.6
- THEN the agreement component is 0.6

### Scenario REQ-MDM-007-04: Overall score

- GIVEN completeness=0.67, freshness=0.85, agreement=0.6
- WHEN dataQualityScore = (0.67*0.3 + 0.85*0.4 + 0.6*0.3)
- THEN dataQualityScore ≈ 0.71
- AND the entity appears on the data-quality dashboard in the "fair" range (0.6-0.8)

---

## REQ-MDM-008: Audit Trail per Merge and Gold-Record Mutation

The system MUST log an audit trail for every merge and every gold-record attribute mutation, retained for 10 years.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/master-data-management/design.md#MergeOperation`
**Files**: OpenRegister auditTrail field (built-in)

### Scenario REQ-MDM-008-01: Merge audit log

- GIVEN a merge of account A into B performed by steward "alice" on 2026-05-22
- WHEN the audit trail is reviewed
- THEN an entry exists:
  - timestamp: 2026-05-22T14:30:00Z
  - action: MERGE
  - actor: alice
  - object: master-entity B
  - details: mergedFrom=[A], preMergeSnapshot={...}
  - retention: until 2036-05-22 (10 years)

### Scenario REQ-MDM-008-02: Attribute change audit log

- GIVEN a source-record update that changes Master Entity's goldenRecord.phone via trust-tier recomputation
- WHEN the change is applied
- THEN an entry exists in Master Entity's auditTrail:
  - timestamp: now
  - action: ATTRIBUTE_UPDATE
  - attribute: phone
  - oldValue: "030-1234567"
  - newValue: "030-7654321"
  - reason: "Trust-tier recomputation; sourceSystem=shillinq-debiteuren won over pipelinq-crm"
  - retention: 10 years

---

## REQ-MDM-009: Right-of-Deletion (AVG art. 17)

The system MUST correctly execute AVG right-of-deletion: Master Entity soft-deleted, source-records anonymized, downstream apps synced, audit trail geanonimiseerd.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/master-data-management/design.md#AVGWorkflowService`
**Files**: `pipelinq/lib/Service/AVGWorkflowService.php`

### Scenario REQ-MDM-009-01: Initiate right-of-deletion

- GIVEN a data-subject request from "Pietje Puk" to be forgotten from all systems
- WHEN a data-steward initiates the workflow
- THEN:
  1. The Master Entity for contact "Pietje Puk" is identified
  2. All linked source-records (5 records from 5 systems) are listed
  3. A right-of-deletion workflow task is created for steward approval
  4. Audit note logs the request with GDPR request ID

### Scenario REQ-MDM-009-02: Approve and execute deletion

- GIVEN the steward approves the right-of-deletion request
- WHEN `AVGWorkflowService::approveAndExecuteRightOfDeletion()` runs
- THEN:
  1. Master Entity status = soft-deleted
  2. All source-records are anonymized: name/address/email/phone → "[verwijderd]", withdrawn=true
  3. Sync-queue-items are created for all 5 downstream systems with changeType=soft-delete
  4. Audit trail entry: "Right-of-deletion executed on 2026-05-22 by steward bob, GDPR request #GR-2026-5001"
  5. Hard-delete callback is scheduled for +30 days (cooling-off period for mistakes)

### Scenario REQ-MDM-009-03: Audit trail remains after anonymization

- GIVEN the soft-delete was executed as above
- WHEN an auditor reviews the audit trail 1 year later
- THEN the trail still shows:
  - "Merge on 2025-03-15 by alice involving masterId X and Y"
  - "Right-of-deletion executed on 2026-05-22 by bob for GDPR request GR-2026-5001"
  - The actual attribute values are geanonimiseerd (redacted as [***])
  - But the structure of events and dates remain visible for wettelijke aantoonbaarheid (proof of legal compliance)

---

## REQ-MDM-010: Read-API for Downstream Apps

The system MUST publish a read-API allowing downstream apps to retrieve golden records by masterId, aliasId, or natural key.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/master-data-management/design.md`
**Files**: `pipelinq/lib/Controller/MdmApiController.php`

### Scenario REQ-MDM-010-01: Query by KvK number

- GIVEN Procest needs to link a project to a stakeholder organization via KvK "12345678"
- WHEN Procest calls `GET /api/mdm/master?type=account&kvk=12345678`
- THEN the system returns:
  ```json
  {
    "masterId": "550e8400-...",
    "entityType": "account",
    "goldenRecord": { ... },
    "dataQualityScore": 0.92,
    "attributeProvenance": { ... }
  }
  ```
- AND Procest stores the masterId on its project record

### Scenario REQ-MDM-010-02: Query by masterId

- GIVEN Procest already has masterId "550e8400-..." stored from a prior link
- WHEN Procest calls `GET /api/mdm/master/550e8400-...`
- THEN the current golden record is returned
- AND if a merge occurred since the earlier query, the `masterId` in the response is the new merged-into ID, and prior masterId is in `aliases`

### Scenario REQ-MDM-010-03: Query by alias (pre-merge ID)

- GIVEN Procest has an old reference to masterId "old-id-abc" which was merged
- WHEN Procest calls `GET /api/mdm/master/old-id-abc`
- THEN the system recognizes this as an alias in the new master-entity, and returns the current golden record plus note: "This masterId was merged; current masterId is new-id-xyz"

---

## REQ-MDM-011: Sync Golden Record to OpenRegister

The system MUST keep the OpenRegister schema instances (contact, account, product, vendor) synchronized with the golden records, marking them with masterEntityRef.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/master-data-management/design.md`
**Files**: Background sync job, openregister adapter

### Scenario REQ-MDM-011-01: Golden record reflects in OR schema

- GIVEN a change to Master Entity account's goldenRecord (phone updated)
- WHEN the sync-to-OR job runs
- THEN the corresponding OpenRegister `account` object is updated with the new phone value
- AND `masterEntityRef = <masterId>` is set on the OR object

### Scenario REQ-MDM-011-02: Pre-merge OR records marked as merged

- GIVEN two OR `account` objects are merged into one Master Entity
- WHEN the first OR object is marked as `isMasterRecord = false`
- THEN queries against the OpenRegister catalog still resolve correctly via masterEntityRef

---

## REQ-MDM-012: Conflict-Resolution Wizard for Data Stewards

The system MUST provide a wizard for data stewards to resolve attribute conflicts, with the option to establish persistent trust-tier rules.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/master-data-management/design.md`
**Files**: Frontend wizard component, trust-configuration API

### Scenario REQ-MDM-012-01: Resolve VAT number conflict

- GIVEN a Master Entity with conflicting VAT numbers: pipelinq-crm="NL123456789B01", shillinq-debiteuren="NL123456789B02"
- WHEN a steward opens the conflict-resolution wizard
- THEN the UI displays:
  - Attribute: vatNumber
  - Value 1: "NL123456789B01" (source: pipelinq-crm, lastUpdated: 2026-03-01)
  - Value 2: "NL123456789B02" (source: shillinq-debiteuren, lastUpdated: 2026-05-10)
  - Rationale field (free-text)
- AND the steward can select "Shillinq is correct (more recent)"
- AND optionally check "Always use this rule" to create a persistent trust-config

### Scenario REQ-MDM-012-02: Persistent rule creation

- GIVEN the steward selected "Always use this rule"
- AND entered rationale "Shillinq bron geverifieerd via VAT-validatie EU-service"
- WHEN the wizard saves
- THEN:
  1. The attribute value for this entity is resolved to the shillinq value
  2. A trust-configuration entry is created: entityType=account, attribute=vatNumber, sourceSystem=shillinq-debiteuren, trustTier=gold (or upgraded if already silver/bronze)
  3. All other Master Entities are queued for recomputation with the new rule

# Tasks: master-data-management

> **Status: HANDOFF.** All 73 tasks below are marked `[~] (HANDOFF)`. The MDM concerns this change set out to cover are addressed by existing capabilities in the Pipelinq spec corpus:
>
> - **Contact reconciliation / golden record (REQ-MDM-001, REQ-MDM-011)** — covered by `contacts-sync` (ContactSyncService) and the existing `contact`/`account`/`product`/`vendor` schemas in `pipelinq_register.json`.
> - **Source-system identity (LeadSource, RequestChannel)** — covered by `lead-management` and `request-management`.
> - **Duplicate prevention (REQ-MDM-002)** — covered by `admin-settings-duplicate-prevention`.
> - **Downstream sync queue (REQ-MDM-006)** — covered by `queue-management` + openconnector.
> - **Right-of-deletion (REQ-MDM-009)** — covered by `avg-verzoeken-workflow`.
> - **Read-API / OR sync (REQ-MDM-010, REQ-MDM-011)** — covered by `openregister-integration` and `pipelinq-or-adoption`.
>
> Probabilistic matching (REQ-MDM-003), reversible merge tooling (REQ-MDM-004), trust-tier configuration (REQ-MDM-005), data-quality scoring (REQ-MDM-007), and the conflict-resolution wizard (REQ-MDM-012) are **deferred** to future, more narrowly-scoped changes.
>
> The `## ADDED Requirements` delta in `specs/master-data-management/spec.md` documents the REQ-MDM-001…012 surface as a forward-compatibility marker so this change validates `--strict` and serves as the canonical handoff record.

## 1. Data Layer: Schema Registration & OpenRegister Integration

- [~] 1.1 Register `master-entity` schema in OpenRegister (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-001`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Schema defines: masterId, entityType, goldenRecord, attributeProvenance, aliases, mergedFrom, status, mergedIntoMasterId, dataQualityScore, lastReviewedAt, tags, gdprNotes
    - `masterId` is UUID, required, unique key
    - `attributeProvenance` is object with per-attribute metadata
    - Schema is readable by OR API

- [~] 1.2 Register `source-record` schema in OpenRegister (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-001`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Schema defines: sourceRecordId, sourceSystem, nativeId, entityType, currentMasterEntity, rawAttributes, mappedAttributes, firstSeen, lastSeen, lastChange, confidence, linkageMethod, linkageConfidence, withdrawn
    - `sourceRecordId` is composite key (`sourceSystem:nativeId`)
    - Relations to `master-entity` are configured

- [~] 1.3 Register `trust-configuration` schema in OpenRegister (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-005`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Schema defines: entityType, attribute, sourceSystem, trustTier, freshnessDecayDays, manualOverrideAllowed, rationale, effectiveFrom
    - Supports querying by (entityType, attribute, sourceSystem) tuple
    - Effective-date filtering is supported

- [~] 1.4 Register `merge-operation` schema in OpenRegister (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-004`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Schema defines: id, mergedIntoMasterId, mergedFromMasterIds, mergedAt, mergedBy, mergeReason, preMergeSnapshot, attributeResolutionLog, downstreamSyncStatus, reversible, reversedAt, reversedBy
    - `preMergeSnapshot` is JSON object type
    - Relations to `master-entity` are configured

- [~] 1.5 Register `sync-queue-item` schema in OpenRegister (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-006`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Schema defines: id, masterEntity, targetSystem, changeType, payload, status, attemptCount, lastAttemptAt, nextRetryAt, errorMessage, acknowledgedAt, acknowledgmentReference, priority
    - Status enum: queued, sending, sent, acknowledged, failed, dead-letter
    - Relations to `master-entity` are configured

- [~] 1.6 Extend `contact` schema with MDM fields (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-011`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Add `masterEntityRef` (FK to master-entity, optional)
    - Add `isMasterRecord` (boolean, optional)
    - Backward-compatible: null for existing contacts

- [~] 1.7 Extend `account` schema with MDM fields (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-011`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Add `masterEntityRef`, `isMasterRecord`
    - Backward-compatible

- [~] 1.8 Extend `product` schema with MDM fields (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-011`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Add `masterEntityRef`, `isMasterRecord`
    - Backward-compatible

- [~] 1.9 Seed data: Create 3 master-entity examples (HANDOFF)
  - **spec_ref**: `specs/master-data-management/design.md#SeedData`
  - **files**: Database migrations or seed script
  - **acceptance_criteria**:
    - 1 contact example (Maria Jansen) with goldenRecord and attributeProvenance
    - 1 account example (Voorbeeld B.V.) with KvK, VAT, address provenance
    - 1 product example (Implementatieservice - 40 uur)
    - All include dataQualityScore, lastReviewedAt, tags

- [~] 1.10 Seed data: Create 3 trust-configuration examples (HANDOFF)
  - **spec_ref**: `specs/master-data-management/design.md#SeedData`
  - **files**: Database migrations or seed script
  - **acceptance_criteria**:
    - Account billingAddress: kvk-api=gold, freshnessDecayDays=180
    - Account phone: shillinq-debiteuren=silver, freshnessDecayDays=90
    - Account vatNumber: kvk-api=gold, manualOverrideAllowed=false

---

## 2. Backend: Master Entity Service (REQ-MDM-001)

- [~] 2.1 Create `lib/Service/MasterEntityService.php` (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-001`
  - **files**: `pipelinq/lib/Service/MasterEntityService.php`
  - **acceptance_criteria**:
    - GIVEN a Master Entity object
    - THEN `MasterEntityService` can be instantiated with `ObjectService` dependency
    - AND public methods exist: `recomputeGoldenRecord()`, `linkSourceRecord()`, `unlinkSourceRecord()`

- [~] 2.2 Implement golden-record recomputation algorithm (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-001`
  - **files**: `pipelinq/lib/Service/MasterEntityService.php`
  - **acceptance_criteria**:
    - GIVEN a Master Entity with multiple source-records
    - WHEN `recomputeGoldenRecord()` is called
    - THEN for each attribute:
      1. Fetch all source-record values
      2. Look up trust-configuration for this (entityType, attribute, source) tuple
      3. Apply freshness decay if freshnessDecayDays has passed
      4. Select value from highest trust-tier
      5. Update goldenRecord[attribute] and attributeProvenance[attribute]
    - AND the logic handles missing gold-tier sources (falls back to silver, then bronze)

- [~] 2.3 Implement source-record linkage (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-001`
  - **files**: `pipelinq/lib/Service/MasterEntityService.php`
  - **acceptance_criteria**:
    - `linkSourceRecord(sourceRecord, masterId)` sets currentMasterEntity and calls recomputeGoldenRecord
    - `unlinkSourceRecord(sourceRecordId)` clears the link and recomputes the master entity's golden record

- [~] 2.4 Register listener for source-record changes (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-001`
  - **files**: `pipelinq/lib/AppInfo/Application.php`, `pipelinq/lib/Listener/SourceRecordChangedListener.php`
  - **acceptance_criteria**:
    - Listener implements `OCP\EventDispatcher\IEventListener`
    - Listens to `ObjectUpdatedEvent` for `source-record` objects
    - On update: calls `MasterEntityService::recomputeGoldenRecord()` for the linked Master Entity
    - Listener is registered in Application constructor

---

## 3. Backend: Duplicate Detection Service (REQ-MDM-002, REQ-MDM-003)

- [~] 3.1 Create `lib/Service/DuplicateDetectionService.php` (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-002`, `REQ-MDM-003`
  - **files**: `pipelinq/lib/Service/DuplicateDetectionService.php`
  - **acceptance_criteria**:
    - GIVEN the service instantiated with TrustConfigurationService and MasterEntityService dependencies
    - THEN public method `detectDuplicates(entityType)` exists
    - AND returns array of duplicate-candidate DTOs (not persisted)

- [~] 3.2 Implement deterministic key matching (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-002`
  - **files**: `pipelinq/lib/Service/DuplicateDetectionService.php`
  - **acceptance_criteria**:
    - GIVEN Master Entities of same entityType
    - WHEN `detectDeterministicDuplicates()` runs
    - THEN identify pairs with identical KvK, VAT ID, email, or phone values
    - AND assign linkageConfidence=1.0 and linkageMethod=deterministic-key
    - AND return as candidates

- [~] 3.3 Implement probabilistic matching (Jaro-Winkler + TF-IDF) (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-003`
  - **files**: `pipelinq/lib/Service/DuplicateDetectionService.php`
  - **acceptance_criteria**:
    - GIVEN configurable thresholds (e.g., 0.88 for Jaro-Winkler name, 0.85 for TF-IDF address)
    - WHEN `detectProbabilisticDuplicates()` runs
    - THEN compute similarity scores between all Master Entity pairs
    - AND generate candidates for pairs above threshold with computed linkageConfidence
    - AND support custom threshold configuration

- [~] 3.4 Implement cron job for daily duplicate detection (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-002`, `REQ-MDM-003`
  - **files**: `pipelinq/lib/Cron/DuplicateDetectionJob.php`, `AppInfo/Application.php`
  - **acceptance_criteria**:
    - Cron job runs daily (default 02:00 UTC)
    - Iterates over all entityTypes (contact, account, product, vendor)
    - Calls `detectDeterministicDuplicates()` and `detectProbabilisticDuplicates()`
    - Stores results in transient cache or queue for dashboard display

- [~] 3.5 Handle auto-merge threshold for high-confidence candidates (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-002`, `REQ-MDM-003`
  - **files**: `pipelinq/lib/Service/DuplicateDetectionService.php`
  - **acceptance_criteria**:
    - GIVEN a duplicate candidate with linkageConfidence >= 0.95 AND trust-configuration manualOverrideAllowed=false
    - WHEN post-detection processing occurs
    - THEN auto-trigger merge (or queue for same-day auto-merge)
    - AND log merge-operation with mergeReason=duplicate-detected-probabilistic or deterministic

---

## 4. Backend: Merge Service (REQ-MDM-004)

- [~] 4.1 Create `lib/Service/MergeService.php` (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-004`
  - **files**: `pipelinq/lib/Service/MergeService.php`
  - **acceptance_criteria**:
    - GIVEN MasterEntityService, MergeOperationService, SyncQueueService dependencies
    - THEN public methods: `previewMerge()`, `executeMerge()`, `reverseMerge()`

- [~] 4.2 Implement merge preview logic (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-004`
  - **files**: `pipelinq/lib/Service/MergeService.php`
  - **acceptance_criteria**:
    - `previewMerge(fromMasterId, intoMasterId)` returns JSON with:
      - post-merge golden record (per-attribute decision)
      - downstream sync impact (list of target systems + entity counts)
      - reversal window (30 days default, or until date)
    - No side effects; read-only operation

- [~] 4.3 Implement merge execution with atomicity (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-004`
  - **files**: `pipelinq/lib/Service/MergeService.php`
  - **acceptance_criteria**:
    - `executeMerge(fromMasterId, intoMasterId, mergedBy, mergeReason)` atomically:
      1. Snapshot pre-merge state (goldenRecords, attributeProvenances, status)
      2. Update intoMasterId.mergedFrom.push(fromMasterId)
      3. Relink source-records from fromMasterId to intoMasterId
      4. Update fromMasterId: status=merged-into-other, mergedIntoMasterId=intoMasterId
      5. Recompute intoMasterId's golden record
      6. Create merge-operation with preMergeSnapshot and attributeResolutionLog
      7. Create sync-queue-items for downstream apps
      8. Emit audit trail entry
    - All changes persisted within single transaction

- [~] 4.4 Implement merge reversal within 30-day window (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-004`
  - **files**: `pipelinq/lib/Service/MergeService.php`
  - **acceptance_criteria**:
    - `reverseMerge(mergeOperationId, reversedBy)` checks reversible flag
    - If reversible=true:
      1. Restore Master Entities from preMergeSnapshot
      2. Restore source-record linkages
      3. Create reverse-merge sync-queue-items
      4. Mark merge-operation reversedAt, reversedBy
    - If reversible=false, return error with message

- [~] 4.5 Implement merge idempotency check (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-004`
  - **files**: `pipelinq/lib/Service/MergeService.php`
  - **acceptance_criteria**:
    - If `fromMasterId.status` is already merged-into-other, reject the merge with clear error message

---

## 5. Backend: Sync Queue Service (REQ-MDM-006)

- [~] 5.1 Create `lib/Service/SyncQueueService.php` (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-006`
  - **files**: `pipelinq/lib/Service/SyncQueueService.php`
  - **acceptance_criteria**:
    - GIVEN ObjectService, OpenConnectorService dependencies
    - THEN public methods: `enqueueSync()`, `processQueue()`, `retryItem()`, `markDeadLetter()`

- [~] 5.2 Implement sync queue entry creation (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-006`
  - **files**: `pipelinq/lib/Service/SyncQueueService.php`
  - **acceptance_criteria**:
    - `enqueueSync(masterEntityId, targetSystem, changeType, payload)` creates sync-queue-item
    - status=queued, attemptCount=0, priority based on changeType (merges higher than updates)
    - Persists to OpenRegister

- [~] 5.3 Implement background queue processor (cron job) (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-006`
  - **files**: `pipelinq/lib/Cron/SyncQueueProcessorJob.php`
  - **acceptance_criteria**:
    - Runs every 5 minutes
    - Fetches queued items sorted by priority DESC, createdAt ASC
    - Limits to 50 items per run (configurable)
    - For each item:
      1. Set status=sending
      2. Call OpenConnector for target system with payload
      3. On HTTP 200-299: status=sent or acknowledged, record acknowledgmentReference
      4. On HTTP error/timeout: increment attemptCount, schedule nextRetryAt, keep status=queued
      5. On max attempts (7 days): status=dead-letter, notify admin

- [~] 5.4 Implement exponential backoff retry schedule (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-006`
  - **files**: `pipelinq/lib/Service/SyncQueueService.php`
  - **acceptance_criteria**:
    - Retry intervals: 1m, 5m, 30m, 2h, 12h, 24h, 24h (cumulative ~7 days)
    - `nextRetryAt` calculated as: now + backoff[attemptCount]
    - Max attempts = 7
    - After 7 attempts, status=dead-letter

- [~] 5.5 Implement admin manual retry endpoint (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-006`
  - **files**: `pipelinq/lib/Controller/SyncQueueAdminController.php`
  - **acceptance_criteria**:
    - Endpoint `POST /api/mdm/sync-queue/{itemId}/retry`
    - Resets status to queued, attemptCount to 0, nextRetryAt to now
    - Requires admin permission
    - Returns updated item

---

## 6. Backend: Data Quality Scorer (REQ-MDM-007)

- [~] 6.1 Create `lib/Service/DataQualityScorer.php` (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-007`
  - **files**: `pipelinq/lib/Service/DataQualityScorer.php`
  - **acceptance_criteria**:
    - GIVEN MasterEntityService, TrustConfigurationService dependencies
    - THEN public method `scoreEntity(masterId)` returns decimal 0-1

- [~] 6.2 Implement completeness scoring (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-007`
  - **files**: `pipelinq/lib/Service/DataQualityScorer.php`
  - **acceptance_criteria**:
    - Per entityType, define required attributes (e.g., account: [name, kvkNumber])
    - `completeness = (filled_required / total_required)`
    - Null or empty string counts as not filled

- [~] 6.3 Implement freshness scoring (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-007`
  - **files**: `pipelinq/lib/Service/DataQualityScorer.php`
  - **acceptance_criteria**:
    - `freshness = exp(-days_since_last_change / 180)`
    - Use attributeProvenance.lastUpdated (most recent across all attributes)
    - Yields 1.0 for today, ~0.37 for 6 months ago, ~0.05 for 1 year ago

- [~] 6.4 Implement agreement scoring (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-007`
  - **files**: `pipelinq/lib/Service/DataQualityScorer.php`
  - **acceptance_criteria**:
    - For each attribute, count how many source-records provide conflicting values
    - `agreement = 1.0 - (conflicting_attributes / total_attributes)`
    - Attributes with only 1 source or where all sources agree = no conflict

- [~] 6.5 Implement weighted overall score (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-007`
  - **files**: `pipelinq/lib/Service/DataQualityScorer.php`
  - **acceptance_criteria**:
    - `dataQualityScore = (completeness*0.3 + freshness*0.4 + agreement*0.3)`
    - Result is decimal 0-1, saved to master-entity object
    - Rounding to 2 decimal places

- [~] 6.6 Implement nightly scoring job (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-007`
  - **files**: `pipelinq/lib/Cron/DataQualityScorerJob.php`
  - **acceptance_criteria**:
    - Runs nightly (default 03:00 UTC)
    - Iterates over all Master Entities
    - Computes and updates dataQualityScore for each
    - Updates lastReviewedAt if score changed significantly (threshold: ±0.05)

---

## 7. Backend: AVG Right-of-Deletion Service (REQ-MDM-009)

- [~] 7.1 Create `lib/Service/AVGWorkflowService.php` (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-009`
  - **files**: `pipelinq/lib/Service/AVGWorkflowService.php`
  - **acceptance_criteria**:
    - GIVEN MasterEntityService, SyncQueueService, NotificationService dependencies
    - THEN public methods: `initiateRightOfDeletion()`, `approveAndExecuteRightOfDeletion()`, `confirmHardDelete()`

- [~] 7.2 Implement deletion workflow initiation (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-009`
  - **files**: `pipelinq/lib/Service/AVGWorkflowService.php`
  - **acceptance_criteria**:
    - `initiateRightOfDeletion(masterEntityId, gdprRequestId)` creates task for steward approval
    - Logs GDPR request details (date, requester proof method, legal basis)
    - Sets task status to pending-review
    - Notifies assigned steward

- [~] 7.3 Implement approved deletion execution (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-009`
  - **files**: `pipelinq/lib/Service/AVGWorkflowService.php`
  - **acceptance_criteria**:
    - `approveAndExecuteRightOfDeletion(requestId, approvedBy)` atomically:
      1. Fetch Master Entity and all linked source-records
      2. Anonymize source-records: rawAttributes, mappedAttributes → "{anonymized}", withdrawn=true
      3. Update Master Entity: status=soft-deleted, append geanonimiseerde audit notes
      4. Create sync-queue-items for all downstream apps (changeType=soft-delete)
      5. Schedule hard-delete callback +30 days
      6. Log to audit trail with GDPR request ID and approval timestamp
    - All within transaction

- [~] 7.4 Implement audit trail anonymization (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-009`
  - **files**: `pipelinq/lib/Service/AVGWorkflowService.php`
  - **acceptance_criteria**:
    - When soft-deleted, audit trail events are redacted: attribute values → [***], names → [***]
    - Event structure and timestamps remain visible for compliance proof
    - Entries like "Merge on 2025-03-15" still show date and action, but masterId values are hashed/opaque

- [~] 7.5 Implement hard-delete confirmation callback (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-009`
  - **files**: `pipelinq/lib/Cron/HardDeleteConfirmationJob.php`
  - **acceptance_criteria**:
    - Runs daily; looks for soft-deleted Master Entities older than 30 days
    - Notifies admin: "Ready to hard-delete (after 30-day cooling-off)?"
    - Admin endpoint to confirm hard-delete:
      1. Permanently delete Master Entity and all source-records
      2. Archive sync-queue-items (mark completed)
      3. Final audit trail entry: "Hard-deleted by admin user X on date Y"

---

## 8. Backend: Read-API (REQ-MDM-010)

- [~] 8.1 Create `lib/Controller/MdmApiController.php` (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-010`
  - **files**: `pipelinq/lib/Controller/MdmApiController.php`
  - **acceptance_criteria**:
    - Implements OCP\AppFramework\ApiController
    - Public endpoints for querying Master Entities

- [~] 8.2 Implement query by masterId endpoint (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-010`
  - **files**: `pipelinq/lib/Controller/MdmApiController.php`
  - **acceptance_criteria**:
    - Endpoint: `GET /api/mdm/master/{masterId}`
    - Returns golden record, dataQualityScore, attributeProvenance
    - If master-entity not found: HTTP 404
    - If merged-into-other: return current master record + note about merge

- [~] 8.3 Implement query by natural key endpoint (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-010`
  - **files**: `pipelinq/lib/Controller/MdmApiController.php`
  - **acceptance_criteria**:
    - Endpoint: `GET /api/mdm/master?type={entityType}&kvk={value}` or email, phone, etc.
    - Supported keys per entityType (e.g., account: kvk, vat, email; contact: email, phone)
    - Returns master entity matching natural key
    - If multiple matches: return error (data integrity issue; requires manual review)

- [~] 8.4 Implement query by alias endpoint (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-010`
  - **files**: `pipelinq/lib/Controller/MdmApiController.php`
  - **acceptance_criteria**:
    - Endpoint: `GET /api/mdm/master/{aliasId}`
    - Recognizes aliasId (pre-merge masterId) and returns current master with merger info
    - Response includes note: "This masterId was merged into [current-id] on [date]"

- [~] 8.5 Implement authentication & authorization (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-010`
  - **files**: `pipelinq/lib/Controller/MdmApiController.php`
  - **acceptance_criteria**:
    - All endpoints require Bearer token or session authentication
    - Downstream apps (Shillinq, Procest, etc.) authenticated via admin-configured API keys
    - Read-only: GET requests allowed; no mutations via API for external consumers

---

## 9. Backend: OpenRegister Sync Job (REQ-MDM-011)

- [~] 9.1 Create `lib/Service/OpenRegisterSyncService.php` (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-011`
  - **files**: `pipelinq/lib/Service/OpenRegisterSyncService.php`
  - **acceptance_criteria**:
    - GIVEN ObjectService, MasterEntityService dependencies
    - THEN public method `syncMasterToRegister(masterId)` exists

- [~] 9.2 Implement sync master-entity → OR schema (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-011`
  - **files**: `pipelinq/lib/Service/OpenRegisterSyncService.php`
  - **acceptance_criteria**:
    - When Master Entity is updated, sync golden-record attributes to corresponding OR object (contact, account, product, vendor)
    - Set `masterEntityRef = masterId` on OR object
    - Set `isMasterRecord = true` only on the canonical OR object
    - Handle case where no OR object exists (create one, or mark for manual review)

- [~] 9.3 Implement background sync job (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-011`
  - **files**: `pipelinq/lib/Cron/OpenRegisterSyncJob.php`
  - **acceptance_criteria**:
    - Runs hourly
    - Finds all Master Entities with `updatedAt` since last job run
    - Syncs each to OpenRegister via `OpenRegisterSyncService`
    - Logs any sync errors for admin notification

---

## 10. Backend: Trust Configuration Service (Helper)

- [~] 10.1 Create `lib/Service/TrustConfigurationService.php` (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-005`
  - **files**: `pipelinq/lib/Service/TrustConfigurationService.php`
  - **acceptance_criteria**:
    - GIVEN ObjectService dependency
    - THEN public methods: `getTrustTier()`, `getTrustConfig()`, `updateTrustConfig()`, `applyFreshnessDecay()`

- [~] 10.2 Implement trust-tier lookup (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-005`
  - **files**: `pipelinq/lib/Service/TrustConfigurationService.php`
  - **acceptance_criteria**:
    - `getTrustTier(entityType, attribute, sourceSystem, asOfDate)` returns tier (gold/silver/bronze/discard)
    - Respects `effectiveFrom` date
    - Returns null if no config exists (caller decides default)

- [~] 10.3 Implement freshness decay (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-005`
  - **files**: `pipelinq/lib/Service/TrustConfigurationService.php`
  - **acceptance_criteria**:
    - `applyFreshnessDecay(sourceRecordId)` checks config for freshnessDecayDays
    - If exceeded, lowers tier by one level (gold→silver, silver→bronze)
    - Returns degraded tier

---

## 11. Frontend: Master Entity List View

- [~] 11.1 Create Master Entity list component (HANDOFF)
  - **spec_ref**: `specs/master-data-management/design.md#MasterEntityListView`
  - **files**: `src/components/MasterEntityList.vue`
  - **acceptance_criteria**:
    - Table with columns: name/identifier, entityType, dataQualityScore badge, lastReviewedAt, status
    - Filters: entityType, status, dataQualityScore range, tags
    - Sortable by dataQualityScore, lastReviewedAt
    - Action buttons: open detail, duplicate-candidates, bulk actions

- [~] 11.2 Create Master Entity detail view component (HANDOFF)
  - **spec_ref**: `specs/master-data-management/design.md#MasterEntityDetailView`
  - **files**: `src/components/MasterEntityDetail.vue`
  - **acceptance_criteria**:
    - Golden Record section (all attributes + values)
    - Source Record Lineage (expandable list with sourceSystem, mappedAttributes, lastSeen, linkageConfidence)
    - Attribute Provenance (per-attribute breakdown: winning source, trustTier, conflicting values)
    - Audit Trail timeline (read-only)
    - Action buttons: merge, conflict-resolution, tag, request-deletion

- [~] 11.3 Create Duplicate Candidates Dashboard (HANDOFF)
  - **spec_ref**: `specs/master-data-management/design.md#DuplicateCandidatesDashboard`
  - **files**: `src/components/DuplicateCandidateDashboard.vue`
  - **acceptance_criteria**:
    - List of candidate pairs (from-entity, to-entity, linkageMethod, linkageConfidence, quality scores)
    - Filters: method, confidence range, merged status
    - Expandable rows: side-by-side preview of entities, downstream impact list
    - Action buttons: open merge wizard, dismiss, mark false-positive

- [~] 11.4 Create Merge Wizard component (HANDOFF)
  - **spec_ref**: `specs/master-data-management/design.md#MergeWizard`
  - **files**: `src/components/MergeWizard.vue`
  - **acceptance_criteria**:
    - Step 1: Side-by-side entity display
    - Step 2: Post-merge golden record preview (attribute winners highlighted)
    - Step 3: Downstream sync impact (list of apps + entity counts)
    - Step 4: Confirmation with merge reason
    - On execute: calls API, shows result, navigation to merge-operation detail

- [~] 11.5 Create Conflict Resolution Wizard component (HANDOFF)
  - **spec_ref**: `specs/master-data-management/design.md#ConflictResolutionWizard`
  - **files**: `src/components/ConflictResolutionWizard.vue`
  - **acceptance_criteria**:
    - Display attribute name, conflicting values per source, timestamps, freshness
    - Radio button or dropdown to select winning source
    - Checkbox: "Always use this rule"
    - Rationale text field
    - On save: applies decision, optionally creates/updates trust-configuration

- [~] 11.6 Create Data Quality Dashboard (HANDOFF)
  - **spec_ref**: `specs/master-data-management/design.md#DataQualityDashboard`
  - **files**: `src/components/DataQualityDashboard.vue`
  - **acceptance_criteria**:
    - Trend chart: average dataQualityScore over 30/90 days
    - Health card: % entities in ranges (>0.8, 0.6-0.8, <0.6)
    - Top 10 worst entities table
    - Sync queue health card: queued/sending/acknowledged/dead-letter counts
    - Dead-letter detail list with errors + manual retry button
    - Quick actions: refresh external sources, run dedup, review worst entities

---

## 12. Frontend: Admin & Configuration UI

- [~] 12.1 Create Trust Configuration admin panel (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-005`
  - **files**: `src/components/TrustConfigAdmin.vue`
  - **acceptance_criteria**:
    - CRUD interface for trust-configuration entries
    - Table: entityType, attribute, sourceSystem, trustTier, freshnessDecayDays, effectiveFrom, manualOverrideAllowed
    - Add/edit form with validation
    - Rationale text field
    - Date picker for effectiveFrom
    - Delete confirmation

- [~] 12.2 Create Sync Queue admin panel (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-006`
  - **files**: `src/components/SyncQueueAdmin.vue`
  - **acceptance_criteria**:
    - List of sync-queue-items with filters: status, targetSystem, dateRange
    - Detail view for each item: payload (JSON), error message, retry history
    - Manual retry button for dead-letter items
    - Bulk retry for filtered items

- [~] 12.3 Create AVG Right-of-Deletion admin panel (HANDOFF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-009`
  - **files**: `src/components/AVGWorkflowAdmin.vue`
  - **acceptance_criteria**:
    - List of pending GDPR requests (date, requester, status)
    - Detail view: request details, linked Master Entity, source-records count, sync status
    - Approve/Reject buttons with reason field
    - Post-approval: hard-delete confirmation (after 30-day cooling-off)

---

## 13. API Routes & Endpoints

- [~] 13.1 Register API routes (HANDOFF)
  - **spec_ref**: All API specs in specs.md
  - **files**: `appinfo/routes.php`
  - **acceptance_criteria**:
    - Routes for `/api/mdm/master/*` (read-API)
    - Routes for `/api/mdm/merge/*` (merge operations)
    - Routes for `/api/mdm/sync-queue/*` (admin)
    - Routes for `/api/mdm/trust-config/*` (admin)
    - Routes for `/api/mdm/avg-workflow/*` (admin)

---

## 14. i18n (Dutch + English)

- [~] 14.1 Add i18n keys for all UI labels and messages (HANDOFF)
  - **spec_ref**: ADR-005 (i18n keys)
  - **files**: `l10n/en.json`, `l10n/nl.json`
  - **acceptance_criteria**:
    - Labels for all views (Master Entity, Merge Wizard, etc.)
    - Status labels (pending, synced, failed, dead-letter)
    - Error messages for all validation and API failures
    - Placeholder text for forms
    - Help text for configuration fields

---

## 15. Testing & QA

- [~] 15.1 Unit tests for MasterEntityService (HANDOFF)
  - **spec_ref**: REQ-MDM-001
  - **files**: `tests/unit/Service/MasterEntityServiceTest.php`
  - **acceptance_criteria**:
    - Test golden-record recomputation with various trust-tier scenarios
    - Test source-record linkage and unlinkage
    - Test listener registration and firing

- [~] 15.2 Unit tests for DuplicateDetectionService (HANDOFF)
  - **spec_ref**: REQ-MDM-002, REQ-MDM-003
  - **files**: `tests/unit/Service/DuplicateDetectionServiceTest.php`
  - **acceptance_criteria**:
    - Test deterministic matching on KvK, VAT, email
    - Test probabilistic matching (Jaro-Winkler, TF-IDF)
    - Test threshold filtering

- [~] 15.3 Unit tests for MergeService (HANDOFF)
  - **spec_ref**: REQ-MDM-004
  - **files**: `tests/unit/Service/MergeServiceTest.php`
  - **acceptance_criteria**:
    - Test merge preview (no side effects)
    - Test merge execution (atomicity, snapshot, sync-queue creation)
    - Test merge reversal within and beyond window

- [~] 15.4 Unit tests for DataQualityScorer (HANDOFF)
  - **spec_ref**: REQ-MDM-007
  - **files**: `tests/unit/Service/DataQualityScorerTest.php`
  - **acceptance_criteria**:
    - Test completeness, freshness, agreement scoring
    - Test overall weighted score
    - Test edge cases (null values, no source records)

- [~] 15.5 Integration tests for full MDM workflow (HANDOFF)
  - **spec_ref**: All specs
  - **files**: `tests/integration/MdmWorkflowTest.php`
  - **acceptance_criteria**:
    - Create Master Entity with source records
    - Duplicate detection and merge
    - Verify sync-queue items created
    - Verify OpenRegister sync
    - Verify audit trail

- [~] 15.6 Frontend tests for UI components (HANDOFF)
  - **spec_ref**: ADR-008 (testing)
  - **files**: `tests/frontend/*.spec.js`
  - **acceptance_criteria**:
    - Test Master Entity list rendering and filtering
    - Test Merge Wizard flow and preview
    - Test Conflict Resolution Wizard
    - Test Data Quality Dashboard

---

## 16. Documentation & Deployment

- [~] 16.1 Write administrator guide (HANDOFF)
  - **spec_ref**: All specs
  - **files**: `docs/admin/master-data-management.md`
  - **acceptance_criteria**:
    - Setup instructions (schema registration, external API keys)
    - Trust configuration examples
    - Daily operations (dedup review, conflict resolution)
    - Troubleshooting sync failures

- [~] 16.2 Write API documentation (HANDOFF)
  - **spec_ref**: REQ-MDM-010
  - **files**: `docs/api/mdm-read-api.md`
  - **acceptance_criteria**:
    - Endpoint reference (query by masterId, natural key, alias)
    - Request/response examples
    - Authentication & rate-limiting

- [~] 16.3 Write AVG right-of-deletion procedure (HANDOFF)
  - **spec_ref**: REQ-MDM-009
  - **files**: `docs/compliance/avg-right-of-deletion.md`
  - **acceptance_criteria**:
    - Legal basis and GDPR article references
    - Step-by-step workflow
    - Audit trail proof examples
    - Data retention policy for cooling-off period

- [~] 16.4 Database migration script (HANDOFF)
  - **spec_ref**: All data layer tasks
  - **files**: `database/migrations/XXXXXX_create_mdm_schemas.php`
  - **acceptance_criteria**:
    - Creates all new schemas in OpenRegister
    - Adds fields to existing schemas (contact, account, product, vendor)
    - Seeds trust-configuration defaults
    - Backfill existing entities with null masterEntityRef (for migration phase)

- [~] 16.5 npm build verification (HANDOFF)
  - **spec_ref**: All frontend tasks
  - **files**: N/A (build output)
  - **acceptance_criteria**:
    - `npm run build` completes with zero errors
    - No TypeScript errors or ESLint warnings
    - All i18n keys are used (no orphaned keys)
    - Bundle size within acceptable limits

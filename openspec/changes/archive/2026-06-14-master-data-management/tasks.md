# Tasks: master-data-management

> **ADR corrections applied during implementation:**
> - **ADR-037**: schemas/seeds are NOT written to the monolith `lib/Settings/pipelinq_register.json`. They live in the additive fragment `lib/Settings/register.d/90-master-data-management.json`; `ConfigFileLoaderService::deepMergeConfig` gained an additive-union rule (`unionAdditiveLists`) for `components.objects[]` + per-register `schemas[]` membership, with unit tests.
> - **ADR-022 / contact = NC entity**: the `contact` entity type reuses pipelinq's existing NC-addressbook-synced `contact` schema; the `account` entity type maps onto the existing `client` schema (pipelinq has no `account` schema); `product` reuses the existing `product` schema. `vendor` is a supported master-entity `entityType` but has no dedicated OR schema, so it is excluded from the OR-sync projection (documented in `OpenRegisterSyncService`). All work uses the real OR ObjectService API (`find`/`findAll`/`saveObject`/`deleteObject`).
> - **Schema slugs registered** via `SettingsLoadService::SCHEMA_SLUGS` so the `{slug}_schema` app-config keys resolve.

## 1. Data Layer: Schema Registration & OpenRegister Integration

- [x] 1.1 Register `master-entity` schema in OpenRegister
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-001`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Schema defines: masterId, entityType, goldenRecord, attributeProvenance, aliases, mergedFrom, status, mergedIntoMasterId, dataQualityScore, lastReviewedAt, tags, gdprNotes
    - `masterId` is UUID, required, unique key
    - `attributeProvenance` is object with per-attribute metadata
    - Schema is readable by OR API

- [x] 1.2 Register `source-record` schema in OpenRegister
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-001`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Schema defines: sourceRecordId, sourceSystem, nativeId, entityType, currentMasterEntity, rawAttributes, mappedAttributes, firstSeen, lastSeen, lastChange, confidence, linkageMethod, linkageConfidence, withdrawn
    - `sourceRecordId` is composite key (`sourceSystem:nativeId`)
    - Relations to `master-entity` are configured

- [x] 1.3 Register `trust-configuration` schema in OpenRegister
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-005`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Schema defines: entityType, attribute, sourceSystem, trustTier, freshnessDecayDays, manualOverrideAllowed, rationale, effectiveFrom
    - Supports querying by (entityType, attribute, sourceSystem) tuple
    - Effective-date filtering is supported

- [x] 1.4 Register `merge-operation` schema in OpenRegister
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-004`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Schema defines: id, mergedIntoMasterId, mergedFromMasterIds, mergedAt, mergedBy, mergeReason, preMergeSnapshot, attributeResolutionLog, downstreamSyncStatus, reversible, reversedAt, reversedBy
    - `preMergeSnapshot` is JSON object type
    - Relations to `master-entity` are configured

- [x] 1.5 Register `sync-queue-item` schema in OpenRegister
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-006`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Schema defines: id, masterEntity, targetSystem, changeType, payload, status, attemptCount, lastAttemptAt, nextRetryAt, errorMessage, acknowledgedAt, acknowledgmentReference, priority
    - Status enum: queued, sending, sent, acknowledged, failed, dead-letter
    - Relations to `master-entity` are configured

- [x] 1.6 Extend `contact` schema with MDM fields
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-011`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Add `masterEntityRef` (FK to master-entity, optional)
    - Add `isMasterRecord` (boolean, optional)
    - Backward-compatible: null for existing contacts

- [x] 1.7 Extend `account` schema with MDM fields
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-011`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Add `masterEntityRef`, `isMasterRecord`
    - Backward-compatible

- [x] 1.8 Extend `product` schema with MDM fields
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-011`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - Add `masterEntityRef`, `isMasterRecord`
    - Backward-compatible

- [x] 1.9 Seed data: Create 3 master-entity examples
  - **spec_ref**: `specs/master-data-management/design.md#SeedData`
  - **files**: Database migrations or seed script
  - **acceptance_criteria**:
    - 1 contact example (Maria Jansen) with goldenRecord and attributeProvenance
    - 1 account example (Voorbeeld B.V.) with KvK, VAT, address provenance
    - 1 product example (Implementatieservice - 40 uur)
    - All include dataQualityScore, lastReviewedAt, tags

- [x] 1.10 Seed data: Create 3 trust-configuration examples
  - **spec_ref**: `specs/master-data-management/design.md#SeedData`
  - **files**: Database migrations or seed script
  - **acceptance_criteria**:
    - Account billingAddress: kvk-api=gold, freshnessDecayDays=180
    - Account phone: shillinq-debiteuren=silver, freshnessDecayDays=90
    - Account vatNumber: kvk-api=gold, manualOverrideAllowed=false

---

## 2. Backend: Master Entity Service (REQ-MDM-001)

- [x] 2.1 Create `lib/Service/MasterEntityService.php`
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-001`
  - **files**: `pipelinq/lib/Service/MasterEntityService.php`
  - **acceptance_criteria**:
    - GIVEN a Master Entity object
    - THEN `MasterEntityService` can be instantiated with `ObjectService` dependency
    - AND public methods exist: `recomputeGoldenRecord()`, `linkSourceRecord()`, `unlinkSourceRecord()`

- [x] 2.2 Implement golden-record recomputation algorithm
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

- [x] 2.3 Implement source-record linkage
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-001`
  - **files**: `pipelinq/lib/Service/MasterEntityService.php`
  - **acceptance_criteria**:
    - `linkSourceRecord(sourceRecord, masterId)` sets currentMasterEntity and calls recomputeGoldenRecord
    - `unlinkSourceRecord(sourceRecordId)` clears the link and recomputes the master entity's golden record

- [x] 2.4 Register listener for source-record changes
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-001`
  - **files**: `pipelinq/lib/AppInfo/Application.php`, `pipelinq/lib/Listener/SourceRecordChangedListener.php`
  - **acceptance_criteria**:
    - Listener implements `OCP\EventDispatcher\IEventListener`
    - Listens to `ObjectUpdatedEvent` for `source-record` objects
    - On update: calls `MasterEntityService::recomputeGoldenRecord()` for the linked Master Entity
    - Listener is registered in Application constructor

---

## 3. Backend: Duplicate Detection Service (REQ-MDM-002, REQ-MDM-003)

- [x] 3.1 Create `lib/Service/DuplicateDetectionService.php`
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-002`, `REQ-MDM-003`
  - **files**: `pipelinq/lib/Service/DuplicateDetectionService.php`
  - **acceptance_criteria**:
    - GIVEN the service instantiated with TrustConfigurationService and MasterEntityService dependencies
    - THEN public method `detectDuplicates(entityType)` exists
    - AND returns array of duplicate-candidate DTOs (not persisted)

- [x] 3.2 Implement deterministic key matching
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-002`
  - **files**: `pipelinq/lib/Service/DuplicateDetectionService.php`
  - **acceptance_criteria**:
    - GIVEN Master Entities of same entityType
    - WHEN `detectDeterministicDuplicates()` runs
    - THEN identify pairs with identical KvK, VAT ID, email, or phone values
    - AND assign linkageConfidence=1.0 and linkageMethod=deterministic-key
    - AND return as candidates

- [x] 3.3 Implement probabilistic matching (Jaro-Winkler + TF-IDF)
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-003`
  - **files**: `pipelinq/lib/Service/DuplicateDetectionService.php`
  - **acceptance_criteria**:
    - GIVEN configurable thresholds (e.g., 0.88 for Jaro-Winkler name, 0.85 for TF-IDF address)
    - WHEN `detectProbabilisticDuplicates()` runs
    - THEN compute similarity scores between all Master Entity pairs
    - AND generate candidates for pairs above threshold with computed linkageConfidence
    - AND support custom threshold configuration

- [x] 3.4 Implement cron job for daily duplicate detection
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-002`, `REQ-MDM-003`
  - **files**: `pipelinq/lib/Cron/DuplicateDetectionJob.php`, `AppInfo/Application.php`
  - **acceptance_criteria**:
    - Cron job runs daily (default 02:00 UTC)
    - Iterates over all entityTypes (contact, account, product, vendor)
    - Calls `detectDeterministicDuplicates()` and `detectProbabilisticDuplicates()`
    - Stores results in transient cache or queue for dashboard display

- [x] 3.5 Handle auto-merge threshold for high-confidence candidates
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-002`, `REQ-MDM-003`
  - **files**: `pipelinq/lib/Service/DuplicateDetectionService.php`
  - **acceptance_criteria**:
    - GIVEN a duplicate candidate with linkageConfidence >= 0.95 AND trust-configuration manualOverrideAllowed=false
    - WHEN post-detection processing occurs
    - THEN auto-trigger merge (or queue for same-day auto-merge)
    - AND log merge-operation with mergeReason=duplicate-detected-probabilistic or deterministic

---

## 4. Backend: Merge Service (REQ-MDM-004)

- [x] 4.1 Create `lib/Service/MergeService.php`
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-004`
  - **files**: `pipelinq/lib/Service/MergeService.php`
  - **acceptance_criteria**:
    - GIVEN MasterEntityService, MergeOperationService, SyncQueueService dependencies
    - THEN public methods: `previewMerge()`, `executeMerge()`, `reverseMerge()`

- [x] 4.2 Implement merge preview logic
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-004`
  - **files**: `pipelinq/lib/Service/MergeService.php`
  - **acceptance_criteria**:
    - `previewMerge(fromMasterId, intoMasterId)` returns JSON with:
      - post-merge golden record (per-attribute decision)
      - downstream sync impact (list of target systems + entity counts)
      - reversal window (30 days default, or until date)
    - No side effects; read-only operation

- [x] 4.3 Implement merge execution with atomicity
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

- [x] 4.4 Implement merge reversal within 30-day window
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

- [x] 4.5 Implement merge idempotency check
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-004`
  - **files**: `pipelinq/lib/Service/MergeService.php`
  - **acceptance_criteria**:
    - If `fromMasterId.status` is already merged-into-other, reject the merge with clear error message

---

## 5. Backend: Sync Queue Service (REQ-MDM-006)

- [x] 5.1 Create `lib/Service/SyncQueueService.php`
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-006`
  - **files**: `pipelinq/lib/Service/SyncQueueService.php`
  - **acceptance_criteria**:
    - GIVEN ObjectService, OpenConnectorService dependencies
    - THEN public methods: `enqueueSync()`, `processQueue()`, `retryItem()`, `markDeadLetter()`

- [x] 5.2 Implement sync queue entry creation
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-006`
  - **files**: `pipelinq/lib/Service/SyncQueueService.php`
  - **acceptance_criteria**:
    - `enqueueSync(masterEntityId, targetSystem, changeType, payload)` creates sync-queue-item
    - status=queued, attemptCount=0, priority based on changeType (merges higher than updates)
    - Persists to OpenRegister

- [x] 5.3 Implement background queue processor (cron job)
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

- [x] 5.4 Implement exponential backoff retry schedule
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-006`
  - **files**: `pipelinq/lib/Service/SyncQueueService.php`
  - **acceptance_criteria**:
    - Retry intervals: 1m, 5m, 30m, 2h, 12h, 24h, 24h (cumulative ~7 days)
    - `nextRetryAt` calculated as: now + backoff[attemptCount]
    - Max attempts = 7
    - After 7 attempts, status=dead-letter

- [x] 5.5 Implement admin manual retry endpoint
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-006`
  - **files**: `pipelinq/lib/Controller/SyncQueueAdminController.php`
  - **acceptance_criteria**:
    - Endpoint `POST /api/mdm/sync-queue/{itemId}/retry`
    - Resets status to queued, attemptCount to 0, nextRetryAt to now
    - Requires admin permission
    - Returns updated item

---

## 6. Backend: Data Quality Scorer (REQ-MDM-007)

- [x] 6.1 Create `lib/Service/DataQualityScorer.php`
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-007`
  - **files**: `pipelinq/lib/Service/DataQualityScorer.php`
  - **acceptance_criteria**:
    - GIVEN MasterEntityService, TrustConfigurationService dependencies
    - THEN public method `scoreEntity(masterId)` returns decimal 0-1

- [x] 6.2 Implement completeness scoring
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-007`
  - **files**: `pipelinq/lib/Service/DataQualityScorer.php`
  - **acceptance_criteria**:
    - Per entityType, define required attributes (e.g., account: [name, kvkNumber])
    - `completeness = (filled_required / total_required)`
    - Null or empty string counts as not filled

- [x] 6.3 Implement freshness scoring
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-007`
  - **files**: `pipelinq/lib/Service/DataQualityScorer.php`
  - **acceptance_criteria**:
    - `freshness = exp(-days_since_last_change / 180)`
    - Use attributeProvenance.lastUpdated (most recent across all attributes)
    - Yields 1.0 for today, ~0.37 for 6 months ago, ~0.05 for 1 year ago

- [x] 6.4 Implement agreement scoring
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-007`
  - **files**: `pipelinq/lib/Service/DataQualityScorer.php`
  - **acceptance_criteria**:
    - For each attribute, count how many source-records provide conflicting values
    - `agreement = 1.0 - (conflicting_attributes / total_attributes)`
    - Attributes with only 1 source or where all sources agree = no conflict

- [x] 6.5 Implement weighted overall score
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-007`
  - **files**: `pipelinq/lib/Service/DataQualityScorer.php`
  - **acceptance_criteria**:
    - `dataQualityScore = (completeness*0.3 + freshness*0.4 + agreement*0.3)`
    - Result is decimal 0-1, saved to master-entity object
    - Rounding to 2 decimal places

- [x] 6.6 Implement nightly scoring job
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-007`
  - **files**: `pipelinq/lib/Cron/DataQualityScorerJob.php`
  - **acceptance_criteria**:
    - Runs nightly (default 03:00 UTC)
    - Iterates over all Master Entities
    - Computes and updates dataQualityScore for each
    - Updates lastReviewedAt if score changed significantly (threshold: ±0.05)

---

## 7. Backend: AVG Right-of-Deletion Service (REQ-MDM-009)

- [x] 7.1 Create `lib/Service/AVGWorkflowService.php`
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-009`
  - **files**: `pipelinq/lib/Service/AVGWorkflowService.php`
  - **acceptance_criteria**:
    - GIVEN MasterEntityService, SyncQueueService, NotificationService dependencies
    - THEN public methods: `initiateRightOfDeletion()`, `approveAndExecuteRightOfDeletion()`, `confirmHardDelete()`

- [x] 7.2 Implement deletion workflow initiation
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-009`
  - **files**: `pipelinq/lib/Service/AVGWorkflowService.php`
  - **acceptance_criteria**:
    - `initiateRightOfDeletion(masterEntityId, gdprRequestId)` creates task for steward approval
    - Logs GDPR request details (date, requester proof method, legal basis)
    - Sets task status to pending-review
    - Notifies assigned steward

- [x] 7.3 Implement approved deletion execution
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

- [x] 7.4 Implement audit trail anonymization
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-009`
  - **files**: `pipelinq/lib/Service/AVGWorkflowService.php`
  - **acceptance_criteria**:
    - When soft-deleted, audit trail events are redacted: attribute values → [***], names → [***]
    - Event structure and timestamps remain visible for compliance proof
    - Entries like "Merge on 2025-03-15" still show date and action, but masterId values are hashed/opaque

- [x] 7.5 Implement hard-delete confirmation callback
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

- [x] 8.1 Create `lib/Controller/MdmApiController.php`
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-010`
  - **files**: `pipelinq/lib/Controller/MdmApiController.php`
  - **acceptance_criteria**:
    - Implements OCP\AppFramework\ApiController
    - Public endpoints for querying Master Entities

- [x] 8.2 Implement query by masterId endpoint
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-010`
  - **files**: `pipelinq/lib/Controller/MdmApiController.php`
  - **acceptance_criteria**:
    - Endpoint: `GET /api/mdm/master/{masterId}`
    - Returns golden record, dataQualityScore, attributeProvenance
    - If master-entity not found: HTTP 404
    - If merged-into-other: return current master record + note about merge

- [x] 8.3 Implement query by natural key endpoint
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-010`
  - **files**: `pipelinq/lib/Controller/MdmApiController.php`
  - **acceptance_criteria**:
    - Endpoint: `GET /api/mdm/master?type={entityType}&kvk={value}` or email, phone, etc.
    - Supported keys per entityType (e.g., account: kvk, vat, email; contact: email, phone)
    - Returns master entity matching natural key
    - If multiple matches: return error (data integrity issue; requires manual review)

- [x] 8.4 Implement query by alias endpoint
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-010`
  - **files**: `pipelinq/lib/Controller/MdmApiController.php`
  - **acceptance_criteria**:
    - Endpoint: `GET /api/mdm/master/{aliasId}`
    - Recognizes aliasId (pre-merge masterId) and returns current master with merger info
    - Response includes note: "This masterId was merged into [current-id] on [date]"

- [x] 8.5 Implement authentication & authorization
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-010`
  - **files**: `pipelinq/lib/Controller/MdmApiController.php`
  - **acceptance_criteria**:
    - All endpoints require Bearer token or session authentication
    - Downstream apps (Shillinq, Procest, etc.) authenticated via admin-configured API keys
    - Read-only: GET requests allowed; no mutations via API for external consumers

---

## 9. Backend: OpenRegister Sync Job (REQ-MDM-011)

- [x] 9.1 Create `lib/Service/OpenRegisterSyncService.php`
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-011`
  - **files**: `pipelinq/lib/Service/OpenRegisterSyncService.php`
  - **acceptance_criteria**:
    - GIVEN ObjectService, MasterEntityService dependencies
    - THEN public method `syncMasterToRegister(masterId)` exists

- [x] 9.2 Implement sync master-entity → OR schema
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-011`
  - **files**: `pipelinq/lib/Service/OpenRegisterSyncService.php`
  - **acceptance_criteria**:
    - When Master Entity is updated, sync golden-record attributes to corresponding OR object (contact, account, product, vendor)
    - Set `masterEntityRef = masterId` on OR object
    - Set `isMasterRecord = true` only on the canonical OR object
    - Handle case where no OR object exists (create one, or mark for manual review)

- [x] 9.3 Implement background sync job
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-011`
  - **files**: `pipelinq/lib/Cron/OpenRegisterSyncJob.php`
  - **acceptance_criteria**:
    - Runs hourly
    - Finds all Master Entities with `updatedAt` since last job run
    - Syncs each to OpenRegister via `OpenRegisterSyncService`
    - Logs any sync errors for admin notification

---

## 10. Backend: Trust Configuration Service (Helper)

- [x] 10.1 Create `lib/Service/TrustConfigurationService.php`
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-005`
  - **files**: `pipelinq/lib/Service/TrustConfigurationService.php`
  - **acceptance_criteria**:
    - GIVEN ObjectService dependency
    - THEN public methods: `getTrustTier()`, `getTrustConfig()`, `updateTrustConfig()`, `applyFreshnessDecay()`

- [x] 10.2 Implement trust-tier lookup
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-005`
  - **files**: `pipelinq/lib/Service/TrustConfigurationService.php`
  - **acceptance_criteria**:
    - `getTrustTier(entityType, attribute, sourceSystem, asOfDate)` returns tier (gold/silver/bronze/discard)
    - Respects `effectiveFrom` date
    - Returns null if no config exists (caller decides default)

- [x] 10.3 Implement freshness decay
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-005`
  - **files**: `pipelinq/lib/Service/TrustConfigurationService.php`
  - **acceptance_criteria**:
    - `applyFreshnessDecay(sourceRecordId)` checks config for freshnessDecayDays
    - If exceeded, lowers tier by one level (gold→silver, silver→bronze)
    - Returns degraded tier

---

## 11. Frontend: Master Entity List View

- [x] 11.1 Create Master Entity list component
  - **spec_ref**: `specs/master-data-management/design.md#MasterEntityListView`
  - **files**: `src/components/MasterEntityList.vue`
  - **acceptance_criteria**:
    - Table with columns: name/identifier, entityType, dataQualityScore badge, lastReviewedAt, status
    - Filters: entityType, status, dataQualityScore range, tags
    - Sortable by dataQualityScore, lastReviewedAt
    - Action buttons: open detail, duplicate-candidates, bulk actions

- [x] 11.2 Create Master Entity detail view component
  - **spec_ref**: `specs/master-data-management/design.md#MasterEntityDetailView`
  - **files**: `src/components/MasterEntityDetail.vue`
  - **acceptance_criteria**:
    - Golden Record section (all attributes + values)
    - Source Record Lineage (expandable list with sourceSystem, mappedAttributes, lastSeen, linkageConfidence)
    - Attribute Provenance (per-attribute breakdown: winning source, trustTier, conflicting values)
    - Audit Trail timeline (read-only)
    - Action buttons: merge, conflict-resolution, tag, request-deletion

- [x] 11.3 Create Duplicate Candidates Dashboard
  - **spec_ref**: `specs/master-data-management/design.md#DuplicateCandidatesDashboard`
  - **files**: `src/components/DuplicateCandidateDashboard.vue`
  - **acceptance_criteria**:
    - List of candidate pairs (from-entity, to-entity, linkageMethod, linkageConfidence, quality scores)
    - Filters: method, confidence range, merged status
    - Expandable rows: side-by-side preview of entities, downstream impact list
    - Action buttons: open merge wizard, dismiss, mark false-positive

- [x] 11.4 Create Merge Wizard component
  - **spec_ref**: `specs/master-data-management/design.md#MergeWizard`
  - **files**: `src/components/MergeWizard.vue`
  - **acceptance_criteria**:
    - Step 1: Side-by-side entity display
    - Step 2: Post-merge golden record preview (attribute winners highlighted)
    - Step 3: Downstream sync impact (list of apps + entity counts)
    - Step 4: Confirmation with merge reason
    - On execute: calls API, shows result, navigation to merge-operation detail

- [x] 11.5 Create Conflict Resolution Wizard component
  - **spec_ref**: `specs/master-data-management/design.md#ConflictResolutionWizard`
  - **files**: `src/components/ConflictResolutionWizard.vue`
  - **acceptance_criteria**:
    - Display attribute name, conflicting values per source, timestamps, freshness
    - Radio button or dropdown to select winning source
    - Checkbox: "Always use this rule"
    - Rationale text field
    - On save: applies decision, optionally creates/updates trust-configuration

- [x] 11.6 Create Data Quality Dashboard
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

- [x] 12.1 Create Trust Configuration admin panel
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-005`
  - **files**: `src/components/TrustConfigAdmin.vue`
  - **acceptance_criteria**:
    - CRUD interface for trust-configuration entries
    - Table: entityType, attribute, sourceSystem, trustTier, freshnessDecayDays, effectiveFrom, manualOverrideAllowed
    - Add/edit form with validation
    - Rationale text field
    - Date picker for effectiveFrom
    - Delete confirmation

- [x] 12.2 Create Sync Queue admin panel
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-006`
  - **files**: `src/components/SyncQueueAdmin.vue`
  - **acceptance_criteria**:
    - List of sync-queue-items with filters: status, targetSystem, dateRange
    - Detail view for each item: payload (JSON), error message, retry history
    - Manual retry button for dead-letter items
    - Bulk retry for filtered items

- [x] 12.3 Create AVG Right-of-Deletion admin panel
  - **spec_ref**: `specs/master-data-management/spec.md#REQ-MDM-009`
  - **files**: `src/components/AVGWorkflowAdmin.vue`
  - **acceptance_criteria**:
    - List of pending GDPR requests (date, requester, status)
    - Detail view: request details, linked Master Entity, source-records count, sync status
    - Approve/Reject buttons with reason field
    - Post-approval: hard-delete confirmation (after 30-day cooling-off)

---

## 13. API Routes & Endpoints

- [x] 13.1 Register API routes
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

- [x] 14.1 Add i18n keys for all UI labels and messages
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

- [x] 15.1 Unit tests for MasterEntityService
  - **spec_ref**: REQ-MDM-001
  - **files**: `tests/unit/Service/MasterEntityServiceTest.php`
  - **acceptance_criteria**:
    - Test golden-record recomputation with various trust-tier scenarios
    - Test source-record linkage and unlinkage
    - Test listener registration and firing

- [x] 15.2 Unit tests for DuplicateDetectionService
  - **spec_ref**: REQ-MDM-002, REQ-MDM-003
  - **files**: `tests/unit/Service/DuplicateDetectionServiceTest.php`
  - **acceptance_criteria**:
    - Test deterministic matching on KvK, VAT, email
    - Test probabilistic matching (Jaro-Winkler, TF-IDF)
    - Test threshold filtering

- [x] 15.3 Unit tests for MergeService
  - **spec_ref**: REQ-MDM-004
  - **files**: `tests/unit/Service/MergeServiceTest.php`
  - **acceptance_criteria**:
    - Test merge preview (no side effects)
    - Test merge execution (atomicity, snapshot, sync-queue creation)
    - Test merge reversal within and beyond window

- [x] 15.4 Unit tests for DataQualityScorer
  - **spec_ref**: REQ-MDM-007
  - **files**: `tests/unit/Service/DataQualityScorerTest.php`
  - **acceptance_criteria**:
    - Test completeness, freshness, agreement scoring
    - Test overall weighted score
    - Test edge cases (null values, no source records)

- [x] 15.5 Integration tests for full MDM workflow
  - **spec_ref**: All specs
  - **files**: `tests/integration/MdmWorkflowTest.php`
  - **acceptance_criteria**:
    - Create Master Entity with source records
    - Duplicate detection and merge
    - Verify sync-queue items created
    - Verify OpenRegister sync
    - Verify audit trail

- [x] 15.6 Frontend tests for UI components
  - **spec_ref**: ADR-008 (testing)
  - **files**: `tests/frontend/*.spec.js`
  - **acceptance_criteria**:
    - Test Master Entity list rendering and filtering
    - Test Merge Wizard flow and preview
    - Test Conflict Resolution Wizard
    - Test Data Quality Dashboard

---

## 16. Documentation & Deployment

- [x] 16.1 Write administrator guide _(docs/ uses an autogenerated Docusaurus sidebar (`{type:'autogenerated', dirName:'.'}`), so the guide appears in the nav without manual sidebar wiring; authored at `docs/admin/master-data-management.md`)_
  - **spec_ref**: All specs
  - **files**: `docs/admin/master-data-management.md`
  - **acceptance_criteria**:
    - Setup instructions (schema registration, external API keys)
    - Trust configuration examples
    - Daily operations (dedup review, conflict resolution)
    - Troubleshooting sync failures

- [x] 16.2 Write API documentation _(authored at `docs/api/mdm-read-api.md`; endpoint reference, request/response examples, status codes and auth grounded in `MdmApiController` and the `appinfo/routes.php` MDM routes)_
  - **spec_ref**: REQ-MDM-010
  - **files**: `docs/api/mdm-read-api.md`
  - **acceptance_criteria**:
    - Endpoint reference (query by masterId, natural key, alias)
    - Request/response examples
    - Authentication & rate-limiting

- [x] 16.3 Write AVG right-of-deletion procedure _(authored at `docs/compliance/avg-right-of-deletion.md`; legal basis, soft-delete→cooling-off→hard-delete workflow, audit redaction and retention table grounded in `AVGWorkflowService` + `MdmAvgWorkflowController`)_
  - **spec_ref**: REQ-MDM-009
  - **files**: `docs/compliance/avg-right-of-deletion.md`
  - **acceptance_criteria**:
    - Legal basis and GDPR article references
    - Step-by-step workflow
    - Audit trail proof examples
    - Data retention policy for cooling-off period

- [x] 16.4 Database migration script _(CORRECTED per ADR-037: schema creation + field extensions + seeds are delivered via the `lib/Settings/register.d/90-master-data-management.json` fragment merged by ConfigFileLoaderService and imported by the existing repair step — NOT a bespoke DB migration. masterEntityRef is optional so existing entities backfill as null automatically.)_
  - **spec_ref**: All data layer tasks
  - **files**: `lib/Settings/register.d/90-master-data-management.json`, `lib/Service/ConfigFileLoaderService.php`
  - **acceptance_criteria**:
    - Creates all new schemas in OpenRegister
    - Adds fields to existing schemas (contact, account, product, vendor)
    - Seeds trust-configuration defaults
    - Backfill existing entities with null masterEntityRef (for migration phase)

- [x] 16.5 npm build verification
  - **spec_ref**: All frontend tasks
  - **files**: N/A (build output)
  - **acceptance_criteria**:
    - `npm run build` completes with zero errors
    - No TypeScript errors or ESLint warnings
    - All i18n keys are used (no orphaned keys)
    - Bundle size within acceptable limits

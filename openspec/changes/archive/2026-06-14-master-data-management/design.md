# Design: master-data-management

## Architecture

### Data Layer

#### New Schema: `master-entity`

Represents a single authoritative golden record for an entity (Contact, Account, Product, Vendor).

| Property | Type | Required | Description |
|---|---|---|---|
| `masterId` | string (UUID) | Yes | Unique stable identifier, never reused after merge. Primary key for lifetime of entity. |
| `entityType` | string | Yes | Type: `contact`, `account`, `product`, or `vendor` (extensible). |
| `goldenRecord` | object | Yes | JSON object with winning attribute values per trust-tier configuration. Keys match canonical attribute names (e.g., `name`, `email`, `phone`, `billingAddress`, `kvkNumber`, `vatNumber`). |
| `attributeProvenance` | object | Yes | Per-attribute metadata: `{ <attribute>: { value, sourceSystem, sourceRecordId, trustTier, lastUpdated, confidence } }`. Tracks which source won and why. |
| `aliases` | array | No | Array of previous `masterId` values from merges, for backward-compat lookups (e.g., old app code still references pre-merge masterId). |
| `mergedFrom` | array | No | Array of `masterId` values that were merged into this entity. Audit trail of consolidation. |
| `status` | string | Yes | Lifecycle: `active`, `merged-into-other`, `soft-deleted`, `quarantined`. Soft-deleted = flagged for AVG deletion but waiting cooling-off period. |
| `mergedIntoMasterId` | string (FK) | No | If `status=merged-into-other`, the masterId this entity was merged into. Self-referential. |
| `dataQualityScore` | decimal 0-1 | Yes | Computed score: (completeness*0.3 + freshness*0.4 + agreement*0.3). Updated nightly or on major source-record changes. |
| `lastReviewedAt` | string (timestamp) | No | ISO 8601 UTC. Last time a human data steward reviewed or actioned this entity. |
| `tags` | array | No | Free-form labels (e.g., `["vip-customer", "regulated-entity"]`). |
| `gdprNotes` | string | No | Unstructured text for right-of-deletion tracking and proof-of-deletion notes. |

OpenRegister built-in fields available on all objects (do NOT redefine): `id`, `uuid`, `uri`, `version`, `createdAt`, `updatedAt`, `owner`, `organization`, `register`, `schema`, `relations`, `files`, `auditTrail`, `notes`, `tasks`, `tags`, `status`, `locked`.

---

#### New Schema: `source-record`

Represents a copy of an entity as supplied by a specific external source system (Pipelinq CRM, Shillinq, KvK API, etc.).

| Property | Type | Required | Description |
|---|---|---|---|
| `sourceRecordId` | string | Yes | Composite natural key: `{sourceSystem}:{nativeId}`. E.g., `pipelinq-crm:550e8400-e29b-41d4-a716-446655440000`. |
| `sourceSystem` | string | Yes | Origin system: `pipelinq-crm`, `shillinq-debiteuren`, `procest-stakeholders`, `scholiq-leerlingen`, `decidesk-leden`, `kvk-api`, `vies-vat-api`, `handelsregister`, etc. |
| `nativeId` | string | Yes | Object ID in the source system. |
| `entityType` | string | Yes | Type: `contact`, `account`, `product`, `vendor`. Must match the Master Entity type. |
| `currentMasterEntity` | string (FK) | Yes | UUID of the linked Master Entity. |
| `rawAttributes` | object | Yes | Unmodified source values as received. Preserves exact original data for audit. |
| `mappedAttributes` | object | Yes | After normalization/cleansing (e.g., phone numbers standardized, names titlecased, addresses validated). These values compete in duplicate detection and gold-record recomputation. |
| `firstSeen` | string (timestamp) | Yes | When this source record was first ingested. |
| `lastSeen` | string (timestamp) | Yes | Most recent sync/update from the source system. Tracks freshness. |
| `lastChange` | string (timestamp) | Yes | When the mapped attributes last changed. Used for freshness decay. |
| `confidence` | decimal 0-1 | No | Data steward's confidence in this source record (default 1.0). Can be lowered manually if source is known to be stale or unreliable. |
| `linkageMethod` | string | Yes | How this record was linked to its Master Entity: `deterministic-key` (matched on natural key), `probabilistic-match` (fuzzy matched), `manual-assignment` (data steward merged it), `system-auto-merge` (auto-merged by threshold). |
| `linkageConfidence` | decimal 0-1 | Yes | Confidence of the linkage: 1.0 for deterministic, 0.85-0.99 for probabilistic, 1.0 for manual. |
| `withdrawn` | boolean | No | True if the source system has deleted or retracted this record (e.g., contact opted out). |

---

#### New Schema: `trust-configuration`

Rules that define which source system "wins" for each (entityType, attribute, source) combination.

| Property | Type | Required | Description |
|---|---|---|---|
| `entityType` | string | Yes | Type: `contact`, `account`, `product`, `vendor`. |
| `attribute` | string | Yes | Attribute name (e.g., `name`, `email`, `phone`, `billingAddress`, `kvkNumber`, `vatNumber`, `registrationNumber`). |
| `sourceSystem` | string | Yes | Source system slug. |
| `trustTier` | string | Yes | Confidence tier: `gold` (always wins), `silver` (wins if gold missing), `bronze` (lowest priority), `discard` (never used). |
| `freshnessDecayDays` | integer | No | After N days without update from this source, lower its tier by one level. E.g., gold→silver after 180 days. Null = no decay. |
| `manualOverrideAllowed` | boolean | No | If true, data steward can manually override and pick a different source for this one entity. If false, rule is enforced. |
| `rationale` | string | No | Justification for this tier assignment (e.g., "KvK is government-verified" or "Shillinq billed amounts must match"). |
| `effectiveFrom` | string (date) | No | Date this rule takes effect. Allows backdating trust-tier changes for historical recomputation. |

---

#### New Schema: `merge-operation`

Log of every merge (manual or auto) with metadata for reversal and audit.

| Property | Type | Required | Description |
|---|---|---|---|
| `id` | string (UUID) | Yes | Merge operation identifier. |
| `mergedIntoMasterId` | string (FK) | Yes | The Master Entity that received all merged-in records. |
| `mergedFromMasterIds` | array | Yes | Array of masterId values that were merged away. Typically length 1 (merge two into one), but bulk merges produce longer arrays. |
| `mergedAt` | string (timestamp) | Yes | When the merge occurred. |
| `mergedBy` | string | No | Nextcloud user UID or `system-auto-merge`. |
| `mergeReason` | string | Yes | Classification: `duplicate-detected-deterministic`, `duplicate-detected-probabilistic`, `manual-bulk`, `data-stewardship-review`, `migration`. |
| `preMergeSnapshot` | object | Yes | Deep copy of goldenRecords, attributeProvenances, status values for ALL entities being merged (merged-into + merged-from). Enables reversal. |
| `attributeResolutionLog` | array | Yes | Per-attribute decision log: `[{ attribute, winningSourceSystem, winningValue, conflictingValues: [...], rationale }, ...]`. Transparency on why each attribute resolved as it did. |
| `downstreamSyncStatus` | object | No | Per-app sync outcome: `{ shillinq: "acknowledged", procest: "queued", scholiq: "failed", ... }`. |
| `reversible` | boolean | Yes | True until 30 days post-merge. After 30 days, reversal no longer possible (hard-delete risk). |
| `reversedAt` | string (timestamp) | No | If reversed, when. |
| `reversedBy` | string | No | If reversed, which user initiated it. |

---

#### New Schema: `sync-queue-item`

Outbound event queued for delivery to a downstream app. One item per app per Master Entity per change.

| Property | Type | Required | Description |
|---|---|---|---|
| `id` | string (UUID) | Yes | Item identifier. |
| `masterEntity` | string (FK) | Yes | UUID of the affected Master Entity. |
| `targetSystem` | string | Yes | Destination app: `shillinq`, `procest`, `scholiq`, `opencatalogi`, `decidesk`. |
| `changeType` | string | Yes | Event type: `create`, `update`, `merge`, `soft-delete`, `reverse-merge`. |
| `payload` | object | Yes | JSON data to transmit: golden-record attributes, merge metadata, etc. |
| `status` | string | Yes | Lifecycle: `queued`, `sending`, `sent`, `acknowledged`, `failed`, `dead-letter`. |
| `attemptCount` | integer | No | Number of delivery attempts (retries). |
| `lastAttemptAt` | string (timestamp) | No | When the last delivery attempt occurred. |
| `nextRetryAt` | string (timestamp) | No | When the next retry is scheduled (exponential backoff: 1m, 5m, 30m, 2h, 12h, 24h). |
| `errorMessage` | string | No | HTTP error, timeout, or validation rejection from target. |
| `acknowledgedAt` | string (timestamp) | No | When target app sent confirmation. |
| `acknowledgmentReference` | string | No | Confirmation ID from target (e.g., Shillinq order/batch ID). |
| `priority` | decimal | No | Ordering hint (merges higher priority than attrib updates). |

---

#### Extended Schema: `contact` (from Pipelinq)

The existing Pipelinq `contact` schema gains:

| Property | Type | Required | Description |
|---|---|---|---|
| `masterEntityRef` | string (FK) | No | UUID of the Master Entity this contact maps to. Null for un-linked or legacy contacts. |
| `isMasterRecord` | boolean | No | True only if this contact is the "canonical" CRM representation of the master entity. Prevents multiple master records per entity. |

---

#### Extended Schema: `account` (from Pipelinq)

| Property | Type | Required | Description |
|---|---|---|---|
| `masterEntityRef` | string (FK) | No | UUID of the Master Entity. |
| `isMasterRecord` | boolean | No | True for canonical account record. |

---

### Backend

#### `lib/Service/MasterEntityService.php`

Responsibilities:
- CRUD Master Entity and golden-record recomputation
- `recomputeGoldenRecord(masterEntity)` — applies trust-tier rules to source records, resolves conflicts, updates `goldenRecord`, `attributeProvenance`, `dataQualityScore`
- `linkSourceRecord(sourceRecord, masterId)` — assigns a source record to a Master Entity, triggers recomputation
- `unlinkSourceRecord(sourceRecordId)` — detaches source record (e.g., on source deletion or reclassification), triggers recomputation
- Event listener: whenever a `source-record` object changes, call `recomputeGoldenRecord()` for its linked Master Entity

---

#### `lib/Service/DuplicateDetectionService.php`

Responsibilities:
- Deterministic matching on natural keys: KvK number, VAT ID, email, phone, registration number
- Probabilistic fuzzy matching: Jaro-Winkler on name (threshold 0.88), TF-IDF cosine on address+phone (threshold 0.85)
- Daily job: scan all Master Entities, emit duplicate-candidate objects (not schemas — transient DTO in queue)
- Candidate workflow: if confidence >= 0.95 and `manualOverrideAllowed=false`, auto-merge; else, queue for steward review

---

#### `lib/Service/MergeService.php`

Responsibilities:
- `previewMerge(masterIdFrom, masterIdInto)` — returns JSON showing post-merge golden record, attribute resolution, downstream sync impact
- `executeMerge(masterIdFrom, masterIdInto, mergeBy, mergeReason)` — atomically:
  1. Create `merge-operation` with `preMergeSnapshot`
  2. Update `masterIdInto.mergedFrom.push(masterIdFrom)`
  3. Update all source records linked to `masterIdFrom` to link to `masterIdInto`
  4. Set `masterIdFrom.status = merged-into-other`, `masterIdFrom.mergedIntoMasterId = masterIdInto`
  5. Recompute golden record for `masterIdInto`
  6. Create sync-queue-items for all downstream apps
  7. Emit log entry to audit trail
- `reverseMerge(mergeOperationId, reversedBy)` — if `reversible=true`:
  1. Restore `preMergeSnapshot` to all affected Master Entities
  2. Restore source-record linkages
  3. Create reverse-merge sync-queue-items
  4. Mark merge-operation `reversedAt`, `reversedBy`

---

#### `lib/Service/SyncQueueService.php`

Responsibilities:
- `enqueueSync(masterEntityId, targetSystem, changeType, payload)` — creates sync-queue-item
- Background worker (cron job or queue worker):
  1. Fetch queued items sorted by priority + creation time
  2. For each item, call openconnector REST adapter for target system
  3. On HTTP 200-299: set `status=sent` or `acknowledged`, record `acknowledgmentReference`
  4. On HTTP error or timeout: increment `attemptCount`, schedule `nextRetryAt` (exponential backoff)
  5. After max attempts (7 days): set `status=dead-letter`, alert admin
- Admin endpoint: trigger manual retry of dead-letter items

---

#### `lib/Service/DataQualityScorer.php`

Nightly job:
1. For each Master Entity:
   - `completeness = (required_attrs_filled / total_required_attrs)` — e.g., if name+email are required, score is 1.0 if both present, 0.5 if one missing
   - `freshness = exp(-days_since_last_change / 180)` — decay from 1.0 on day 0 to ~0.37 on day 180
   - `agreement = (1.0 - max_attribute_conflict_ratio)` — if all sources agree on all attrs, score is 1.0; if 50% of attributes conflict, score is 0.5
   - `dataQualityScore = (completeness*0.3 + freshness*0.4 + agreement*0.3)`
2. Save to Master Entity

---

#### `lib/Service/AVGWorkflowService.php`

Responsibilities:
- `initiateRightOfDeletion(masterEntityId, gdprRequestId)` — creates workflow task
- `approveAndExecuteRightOfDeletion(requestId, approvedBy)` — atomically:
  1. Fetch Master Entity and all linked source records
  2. Anonymize source records: `rawAttributes = "{anonymized}", mappedAttributes = "{anonymized}", withdrawn=true`
  3. Set `master-entity.status = soft-deleted`, append geanonimiseerde audit notes to `gdprNotes`
  4. Create sync-queue-items for all downstream apps with `changeType=soft-delete`
  5. Schedule hard-delete callback for +30 days (right-to-be-forgotten confirmation period)
- Admin endpoint: confirm right-of-deletion expiration, hard-delete the Master Entity and all source records after 30-day cooling-off

---

### Frontend

#### Master Entity List View

- Table columns: name (or entity identifier), entityType, `dataQualityScore` (badge: green/yellow/red), lastReviewedAt, `status`
- Filters: entityType, status, dataQualityScore range, tags
- Actions: open detail, open in duplicate-candidates, tag, assign to queue
- Bulk actions: bulk merge, bulk tag

---

#### Master Entity Detail View

- **Golden Record** section: display `goldenRecord` with all attributes and values
- **Source Record Lineage** section: expandable list of source-records linked to this Master Entity, showing `sourceSystem`, `mappedAttributes`, `lastSeen`, `linkageConfidence`
- **Attribute Provenance** section: per-attribute details showing which source won, trustTier, lastUpdated, and conflicting values from other sources
- **Audit Trail** section: timeline of merges, attribute changes, source updates, GDPR events (read-only)
- **Actions** button: open in duplicate-candidates, open conflict-resolution wizard, tag, merge, request right-of-deletion

---

#### Duplicate Candidates Dashboard

- Candidate list: from, to (entity names), `linkageMethod`, `linkageConfidence`, dataQualityScore of both entities
- Filter: method (deterministic / probabilistic), confidence range, merged status
- Detail row (expandable): preview pre-merge and post-merge golden records, downstream impact (list of apps + number of entities to update)
- Actions: open merge wizard, dismiss (snooze), mark false-positive (don't suggest again)

---

#### Merge Wizard

- Step 1: Display the two entities side-by-side with their golden records
- Step 2: Preview post-merge golden record — show each attribute with winning source highlighted
- Step 3: Show downstream sync impact — list of apps and entities that will be updated, estimated completion time
- Step 4: Confirmation — user confirms, wizard executes merge, returns merge-operation ID
- Post-execution: show summary, sync-queue status

---

#### Conflict Resolution Wizard

- Triggered from Master Entity detail when there is an unresolved attribute conflict
- Display: attribute name, conflicting values per source, lastUpdated, freshness, trust-tier
- User selects winning source or enters custom value
- Option: "Always use this rule" → creates/updates trust-configuration entry for this (entityType, attribute, source)
- On save: applies decision to this Master Entity and queues re-computation for all other entities using the same sources

---

#### Data Quality Dashboard

- Trend chart: average `dataQualityScore` over time (30-day, 90-day)
- Health card: % entities with quality > 0.8, 0.6-0.8, < 0.6
- Top 10 worst entities: name, type, quality score, reason (low completeness / freshness / agreement)
- Sync queue health: queued / sending / acknowledged / dead-letter counts; dead-letter item list with error
- Quick actions: refresh external sources (KvK, VIES), run dedup, review worst-quality entities

---

## Seed Data

### 3 Master Entity Examples

#### 1. Master Entity: Contact "Maria Jansen"
```json
{
  "masterId": "550e8400-e29b-41d4-a716-446655440001",
  "entityType": "contact",
  "goldenRecord": {
    "name": "Maria Jansen",
    "email": "m.jansen@voorbeeld.nl",
    "phone": "020-1234567",
    "address": "Kerkstraat 42, 1234 AB Amsterdam, NL"
  },
  "attributeProvenance": {
    "name": { "value": "Maria Jansen", "sourceSystem": "pipelinq-crm", "trustTier": "gold", "lastUpdated": "2026-04-15T10:30:00Z" },
    "email": { "value": "m.jansen@voorbeeld.nl", "sourceSystem": "pipelinq-crm", "trustTier": "gold", "lastUpdated": "2026-04-15T10:30:00Z" },
    "phone": { "value": "020-1234567", "sourceSystem": "shillinq-contacten", "trustTier": "silver", "lastUpdated": "2026-02-01T14:20:00Z" },
    "address": { "value": "Kerkstraat 42, 1234 AB Amsterdam, NL", "sourceSystem": "pipelinq-crm", "trustTier": "silver", "lastUpdated": "2026-03-10T09:15:00Z" }
  },
  "aliases": [],
  "mergedFrom": [],
  "status": "active",
  "dataQualityScore": 0.87,
  "lastReviewedAt": "2026-05-20T16:00:00Z",
  "tags": ["important-client", "dutch-market"]
}
```

#### 2. Master Entity: Account "Voorbeeld B.V."
```json
{
  "masterId": "550e8400-e29b-41d4-a716-446655440002",
  "entityType": "account",
  "goldenRecord": {
    "name": "Voorbeeld B.V.",
    "kvkNumber": "12345678",
    "vatNumber": "NL123456789B01",
    "billingAddress": "Bedrijfsplein 10, 5678 XY Utrecht, NL",
    "registrationNumber": "50.04.12.345",
    "phone": "030-7654321",
    "email": "info@voorbeeld.nl"
  },
  "attributeProvenance": {
    "name": { "value": "Voorbeeld B.V.", "sourceSystem": "kvk-api", "trustTier": "gold", "lastUpdated": "2026-05-10T11:45:00Z" },
    "kvkNumber": { "value": "12345678", "sourceSystem": "kvk-api", "trustTier": "gold", "lastUpdated": "2026-05-10T11:45:00Z" },
    "vatNumber": { "value": "NL123456789B01", "sourceSystem": "kvk-api", "trustTier": "gold", "lastUpdated": "2026-05-10T11:45:00Z" },
    "billingAddress": { "value": "Bedrijfsplein 10, 5678 XY Utrecht, NL", "sourceSystem": "kvk-api", "trustTier": "gold", "lastUpdated": "2026-05-10T11:45:00Z" },
    "registrationNumber": { "value": "50.04.12.345", "sourceSystem": "shillinq-debiteuren", "trustTier": "silver", "lastUpdated": "2026-04-28T13:20:00Z" },
    "phone": { "value": "030-7654321", "sourceSystem": "shillinq-debiteuren", "trustTier": "silver", "lastUpdated": "2026-02-15T08:10:00Z" },
    "email": { "value": "info@voorbeeld.nl", "sourceSystem": "pipelinq-crm", "trustTier": "bronze", "lastUpdated": "2025-12-01T15:30:00Z" }
  },
  "aliases": [],
  "mergedFrom": [],
  "status": "active",
  "dataQualityScore": 0.92,
  "lastReviewedAt": "2026-05-19T14:00:00Z",
  "tags": ["regulated-supplier", "vip"]
}
```

#### 3. Master Entity: Product "Implementatieservice - 40 uur"
```json
{
  "masterId": "550e8400-e29b-41d4-a716-446655440003",
  "entityType": "product",
  "goldenRecord": {
    "name": "Implementatieservice - 40 uur",
    "sku": "IMPL-40",
    "unitPrice": 75.00,
    "description": "Professionele implementatie service, 40 uur consultancy"
  },
  "attributeProvenance": {
    "name": { "value": "Implementatieservice - 40 uur", "sourceSystem": "pipelinq-products", "trustTier": "gold", "lastUpdated": "2026-04-05T10:00:00Z" },
    "sku": { "value": "IMPL-40", "sourceSystem": "pipelinq-products", "trustTier": "gold", "lastUpdated": "2026-04-05T10:00:00Z" },
    "unitPrice": { "value": 75.00, "sourceSystem": "pipelinq-products", "trustTier": "gold", "lastUpdated": "2026-05-15T12:30:00Z" },
    "description": { "value": "Professionele implementatie service, 40 uur consultancy", "sourceSystem": "pipelinq-products", "trustTier": "silver", "lastUpdated": "2026-03-20T09:45:00Z" }
  },
  "aliases": [],
  "mergedFrom": [],
  "status": "active",
  "dataQualityScore": 0.95,
  "lastReviewedAt": "2026-05-21T11:00:00Z",
  "tags": ["service-offering"]
}
```

### 3 Trust Configuration Examples

#### 1. Account billing address
```json
{
  "entityType": "account",
  "attribute": "billingAddress",
  "sourceSystem": "kvk-api",
  "trustTier": "gold",
  "freshnessDecayDays": 180,
  "manualOverrideAllowed": true,
  "rationale": "KvK is government-verified source for Dutch business addresses.",
  "effectiveFrom": "2026-06-01"
}
```

#### 2. Account phone number
```json
{
  "entityType": "account",
  "attribute": "phone",
  "sourceSystem": "shillinq-debiteuren",
  "trustTier": "silver",
  "freshnessDecayDays": 90,
  "manualOverrideAllowed": true,
  "rationale": "Shillinq phone numbers are used for billing communication; fresher than CRM.",
  "effectiveFrom": "2026-06-01"
}
```

#### 3. Account VAT number
```json
{
  "entityType": "account",
  "attribute": "vatNumber",
  "sourceSystem": "kvk-api",
  "trustTier": "gold",
  "freshnessDecayDays": 365,
  "manualOverrideAllowed": false,
  "rationale": "KvK VAT numbers are legally binding; override not permitted.",
  "effectiveFrom": "2026-06-01"
}
```

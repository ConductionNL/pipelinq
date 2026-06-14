# Proposal: master-data-management

## Problem

In multi-app Conduction environments, duplicate master data (contacts, accounts, products, vendors) creates recurring operational and financial damage:

1. **Duplicate entities across systems** — A customer "Jansen BV" exists as 4 separate records in Pipelinq CRM, 2 in Shillinq accounting, 3 in Procest projects, 1 in product catalog. Each system sees a different "truth," leading to fragmented customer view, missed upsell opportunities, and reconciliation failures.

2. **No cross-app data consistency** — When a customer's phone number changes in CRM, Shillinq and Procest continue with stale data. No automated path exists to propagate changes across the ecosystem.

3. **Duplicate invoicing and broken references** — A merge of duplicate accounts in Pipelinq has no coordinated effect on downstream systems. Shillinq continues issuing invoices to both "parent" and "merged" account records. Projects in Procest reference broken stakeholder records.

4. **Data quality invisible until reconciliation fails** — Finance teams discover data problems only at month-end reconciliation, when correcting them is expensive and error-prone.

5. **AVG compliance and audit trail gaps** — A citizen's right-on-deletion request cannot be reliably executed across all systems that hold their data. No audit trail exists to prove GDPR compliance.

## Solution

Implement **Master Data Management (MDM)** as an infrastructure layer in Pipelinq — the CRM-owning and customer-interaction-leading app — that maintains a single "golden record" per master entity (Contact, Account, Product, Vendor), with:

1. **Trust-tier configuration** per (entityType, attribute, source) that automatically selects the most-trusted source when attribute values conflict (e.g., KvK-API address always wins over CRM address).

2. **Deterministic + probabilistic duplicate detection** on natural keys (KvK, VAT ID, email, phone) and fuzzy matching (Jaro-Winkler, TF-IDF on name+address+phone).

3. **Reversible merge operations** with preview of downstream impact, allowing data stewards to safely consolidate duplicates and reverse merges within 30 days if errors are discovered.

4. **Downstream sync queue** that pushes master-entity changes to Shillinq, Procest, Scholiq, OpenCatalogi, and Decidesk via openconnector, with automatic retries and confirmation callbacks.

5. **Data quality scoring** per Master Entity (completeness + freshness + cross-source agreement) exposed on a dashboard for data stewards.

6. **10-year audit trail** of all merges, attribute changes, and source-record lifecycle, enabling GDPR audits and financial compliance.

7. **AVG right-of-deletion workflow** that soft-deletes the Master Entity, anonymizes source records, queues soft-delete sync items, and preserves proof-of-compliance audit trail.

## Scope

New schemas in `mdm` register:
- `master-entity` — gouden record per entity (Contact/Account/Product/Vendor) with attributeProvenance, status, dataQualityScore
- `source-record` — per-system raw data with linkage to Master Entity
- `trust-configuration` — per (entityType, attribute, source) trust-tier rules and freshness decay
- `merge-operation` — log of all merges with pre-merge snapshots for reversal
- `sync-queue-item` — outbound sync events to downstream apps with retry status

Extensions to existing schemas:
- `contact` + `account` + `product` + `vendor` each gain `masterEntityRef` and `isMasterRecord` fields

Backend services:
- `MasterEntityService` — CRUD, golden-record recomputation on source changes
- `DuplicateDetectionService` — deterministic (natural key matching) + probabilistic (fuzzy match)
- `MergeService` — merge logic with preview, snapshot, and reversal support
- `SyncQueueService` — queue management, retry scheduling, confirmation handling
- `DataQualityScorer` — completeness / freshness / agreement computation
- `AVGWorkflowService` — right-of-deletion orchestration

Frontend:
- Master Entity list view with data-quality badge and filter
- Master Entity detail view with source-record lineage, attributeProvenance, audit trail
- Duplicate candidates dashboard with merge wizard (preview + execute)
- Conflict resolution wizard for attribute-level trust decisions
- Data quality dashboard (trend, health, dead-letter items)

API:
- Read-API: `GET /api/mdm/master?type={entityType}&{naturalKey}={value}` to query by masterId, aliasId, or natural keys
- Sync-API: Receives inbound source-record updates from external systems (KvK, VIES, Handelsregister, etc.)
- Admin: Trust configuration CRUD, merge reversal, sync-queue management

Seed data:
- 3 Master Entity examples (one Contact, one Account, one Product) with multiple source records and attributeProvenance
- 3 Trust Configuration examples (KvK > Shillinq for address; Shillinq > CRM for phone; etc.)

**Depends on:** OpenRegister (schema/storage), openconnector (downstream sync), ADR-000 (data model baseline)

## Out of Scope

- Reverse sync (downstream apps → Pipelinq) for non-master sources — MDM is read-only to consumers
- Real-time master-entity sync to OpenRegister catalog — uses OR as storage; reverse sync done asynchronously (separate change)
- Bulk historical deduplication for already-deployed tenants — handled as migration task, not MVP
- Custom matching rules or ML-based fuzzy matching tuning — MVP uses fixed Jaro-Winkler + TF-IDF thresholds
- Scheduled refresh of external sources (KvK, VIES) — managed by separate integration orchestration
- Data lineage visualization beyond attributeProvenance — separate analytics feature

## Success Criteria

- A data steward opens the Master Entity list and filters by `dataQualityScore < 0.6` to find low-quality records
- A duplicate pair is detected (deterministic or probabilistic), appears in the Duplicate Candidates dashboard
- The steward opens the merge wizard, sees a preview (golden record post-merge, downstream impact, reversal window)
- After confirmation, the merge executes, `merge-operation` is logged, and sync-queue-items appear for all downstream apps
- Within 60 seconds, Shillinq's debtor master has been updated via openconnector, all references consolidated, no duplicate invoices
- A steward can reverse the merge within 30 days, re-triggering sync with the pre-merge snapshot
- The audit trail shows complete history of all merges, attribute changes, source updates for the past 10 years
- An AVG right-of-deletion request triggers the workflow: Master Entity soft-deleted, source records anonymized, sync-queue items dispatched, audit trail geanonimiseerd
- The Read-API allows Procest to look up a Master Account by KvK number and receive the current golden record with confidence score

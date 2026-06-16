<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Master Data Management — administrator guide

Master Data Management (MDM) maintains a single authoritative **golden record**
per master entity (Contact, Account, Product, Vendor) inside Pipelinq, and pushes
those records to downstream apps (Shillinq, Procest, Scholiq, OpenCatalogi,
Decidesk) through openconnector. This guide covers setup and daily operations for
data stewards and Nextcloud administrators.

## Prerequisites

- **OpenRegister** is installed and enabled — MDM stores all of its schemas
  (`master-entity`, `source-record`, `trust-configuration`, `merge-operation`,
  `sync-queue-item`) as OpenRegister objects.
- **openconnector** is installed if you want outbound sync to downstream apps.
  Without it, sync-queue items are still created but stay `queued` until a
  consumer is configured.

## Setup

### 1. Schema registration

The MDM schemas, the `masterEntityRef` / `isMasterRecord` extensions to the
existing `contact` / `account` / `product` schemas, and the default
trust-configuration seeds are delivered through the additive config fragment
`lib/Settings/register.d/90-master-data-management.json`. This fragment is merged
into the Pipelinq register by `ConfigFileLoaderService` and imported by the
app's repair step on upgrade — there is no separate database migration to run.

Confirm the schemas were registered after an app upgrade:

```bash
occ pipelinq:config:dump | grep -i master-entity
```

Existing contacts, accounts and products keep a `null` `masterEntityRef` until
they are linked to a master entity, so the extension is fully backward
compatible.

### 2. External API keys

Source systems such as the KvK API or VIES VAT API are configured as openconnector
sources. MDM consumes the resulting `source-record` objects; it does not call the
external APIs directly. Scheduled refresh of those external sources is handled by
the integration orchestration, out of scope for MDM itself.

### 3. Background jobs

MDM registers four background jobs (run automatically by Nextcloud cron):

| Job | Default cadence | Purpose |
|---|---|---|
| `MdmDuplicateDetectionJob` | daily (02:00 UTC) | Deterministic + probabilistic duplicate scan per entity type |
| `MdmDataQualityScorerJob` | nightly (03:00 UTC) | Recompute `dataQualityScore` for every master entity |
| `MdmSyncQueueProcessorJob` | every 5 minutes | Deliver queued sync items, apply backoff, dead-letter |
| `MdmOpenRegisterSyncJob` | hourly | Project changed master entities onto their OR objects |

Ensure Nextcloud is on **Cron** (not AJAX) background mode for reliable cadence.

## Trust configuration

Trust configuration decides which source "wins" for each
`(entityType, attribute, sourceSystem)` combination when source records disagree.
Manage it under **Pipelinq → MDM → Trust configuration**, or via the admin API
(`/api/mdm/trust-config`).

Each rule has:

- **trustTier** — `gold` (always wins), `silver` (wins if no gold), `bronze`
  (lowest), `discard` (never used).
- **freshnessDecayDays** — after N days without an update from that source, its
  tier drops one level (gold → silver → bronze). Leave empty for no decay.
- **manualOverrideAllowed** — if `false`, stewards cannot override the rule for an
  individual entity (use for legally binding attributes such as VAT numbers).
- **rationale** — free text justifying the tier; shown in the conflict-resolution
  wizard.
- **effectiveFrom** — date the rule takes effect (supports backdated recomputation).

### Example: an Account

| Attribute | Source | Tier | Decay | Override | Rationale |
|---|---|---|---|---|---|
| `billingAddress` | `kvk-api` | gold | 180 d | yes | KvK is the government-verified source for Dutch business addresses |
| `phone` | `shillinq-debiteuren` | silver | 90 d | yes | Shillinq phone numbers are used for billing and are fresher than CRM |
| `vatNumber` | `kvk-api` | gold | 365 d | **no** | KvK VAT numbers are legally binding; override not permitted |

These three rules ship as defaults in the config fragment.

## Daily operations

### Reviewing duplicate candidates

1. Open **MDM → Duplicate candidates**. Candidates are produced by the daily
   detection job: deterministic matches (identical KvK, VAT, email or phone →
   `linkageConfidence = 1.0`) and probabilistic matches (Jaro-Winkler name ≥ 0.88,
   TF-IDF address ≥ 0.85).
2. Filter by method, confidence range or merged status.
3. Expand a candidate to see a side-by-side preview and the downstream impact.
4. Dismiss false positives, or open the merge wizard.

High-confidence candidates (`linkageConfidence ≥ 0.95`) on attributes whose trust
rule has `manualOverrideAllowed = false` are queued for same-day **auto-merge**;
these still produce a `merge-operation` record for audit.

### Merging duplicates

The merge wizard runs four steps: side-by-side display → post-merge golden-record
preview → downstream sync impact → confirmation with a merge reason. On execute,
MDM atomically:

1. snapshots the pre-merge state of both entities (`preMergeSnapshot`),
2. relinks the losing entity's source records to the survivor,
3. marks the losing entity `merged-into-other` and records `mergedIntoMasterId`,
4. recomputes the survivor's golden record,
5. logs a `merge-operation` with the per-attribute resolution log,
6. enqueues `merge` sync items for every downstream app.

A merge is **reversible for 30 days**. Reversal restores the pre-merge snapshot,
re-links source records and enqueues `reverse-merge` sync items. After 30 days the
`reversible` flag is `false` and reversal is refused.

### Resolving attribute conflicts

When sources disagree on an attribute, open the conflict-resolution wizard from the
master-entity detail view. Pick the winning source (or a custom value); ticking
**"Always use this rule"** creates or updates the corresponding trust-configuration
entry so the decision applies to all entities of that type.

### Monitoring data quality

The data-quality dashboard shows the average score trend, a health card (% of
entities `> 0.8`, `0.6–0.8`, `< 0.6`), the ten worst entities, and sync-queue
health counts. The score is
`completeness × 0.3 + freshness × 0.4 + agreement × 0.3`, recomputed nightly.

## Troubleshooting sync failures

- **Items stuck in `queued`** — confirm openconnector is installed and a consumer
  is configured for the `targetSystem`; check that `MdmSyncQueueProcessorJob` is
  running (Cron background mode).
- **Items in `dead-letter`** — a delivery failed after 7 attempts (backoff
  1 m → 5 m → 30 m → 2 h → 12 h → 24 h → 24 h, ~7 days). Inspect `errorMessage` in
  the Sync queue admin panel and use **Retry** to reset the item to `queued`
  (`POST /api/mdm/sync-queue/{itemId}/retry`).
- **A master entity is not syncing to OpenRegister** — the hourly
  `MdmOpenRegisterSyncJob` only projects entities changed since its last run, and
  only for entity types with a corresponding OR schema. `vendor` has no dedicated
  OR schema and is intentionally excluded from the OR projection.

## See also

- [MDM read-API reference](../api/mdm-read-api.md)
- [AVG right-of-deletion procedure](../compliance/avg-right-of-deletion.md)

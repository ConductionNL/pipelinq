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

- **OpenRegister** is installed and enabled — MDM stores its schemas
  (`master-entity`, `source-record`, `trust-configuration`, `merge-operation`)
  as OpenRegister objects, and delivers all outbound downstream sync through
  OpenRegister's **WebhookService** (durable webhook logs + `WebhookRetryJob`).
  There is no app-side sync queue: pipelinq dispatches a webhook per downstream
  app at merge/mutation time and OpenRegister owns queueing, delivery logging,
  and retries.
- A **webhook consumer** configured in OpenRegister for the
  `pipelinq.mdm.sync` event if you want the downstream apps to receive updates.
  Without one, dispatches are recorded in OpenRegister's webhook log but reach
  no consumer.

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

Duplicate detection and data-quality scoring are hosted by OpenRegister
(ADR-045 #D). Pipelinq itself no longer runs MDM sync or projection background
jobs: downstream delivery is dispatched event-first to OpenRegister's
WebhookService (retries owned by OR's `WebhookRetryJob`), and the golden-record
→ OR-schema projection runs on the merge/mutation event path
(`ObjectsMergedSyncListener` → `OpenRegisterSyncService`), not a poller.

Ensure Nextcloud is on **Cron** (not AJAX) background mode so OpenRegister's
`WebhookRetryJob` runs reliably.

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
6. dispatches a `merge` sync webhook to every downstream app via OpenRegister's
   WebhookService.

A merge is **reversible for 30 days**. Reversal restores the pre-merge snapshot,
re-links source records and dispatches `reverse-merge` sync webhooks. After 30
days the `reversible` flag is `false` and reversal is refused.

### Resolving attribute conflicts

When sources disagree on an attribute, open the conflict-resolution wizard from the
master-entity detail view. Pick the winning source (or a custom value); ticking
**"Always use this rule"** creates or updates the corresponding trust-configuration
entry so the decision applies to all entities of that type.

### Monitoring data quality

The data-quality dashboard (hosted by OpenRegister) shows the average score
trend, a health card (% of entities `> 0.8`, `0.6–0.8`, `< 0.6`), and the ten
worst entities. The score is
`completeness × 0.3 + freshness × 0.4 + agreement × 0.3`, recomputed nightly.

## Troubleshooting sync failures

Downstream sync delivery is fully owned by OpenRegister — inspect it in
**OpenRegister → Webhooks** (webhook logs), not in Pipelinq:

- **A downstream app is not receiving updates** — confirm a webhook consumer is
  configured in OpenRegister for the `pipelinq.mdm.sync` event, and check the
  OpenRegister webhook log for the delivery attempt and its response.
- **Deliveries keep failing** — OpenRegister's `WebhookRetryJob` reschedules
  failed deliveries via `next_retry_at`; inspect the stored request body and
  response in the webhook log. There is no app-side retry, dead-letter, or
  acknowledgment surface in Pipelinq (retire-mdm-sync-queue).
- **A master entity is not projecting to OpenRegister** — the projection runs on
  the merge/mutation event path (`ObjectsMergedSyncListener`), only for entity
  types with a corresponding OR schema. `vendor` has no dedicated OR schema and
  is intentionally excluded from the OR projection.

## See also

- [AVG right-of-deletion procedure](../compliance/avg-right-of-deletion.md)

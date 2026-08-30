# Design: pipelinq-bookkeeping-to-shillinq

## Context

`PosBookkeepingService` (lib/Service/PosBookkeepingService.php) is the only
pipelinq surface that still **owns bookkeeping artefacts** rather than delegating
them. It:

1. aggregates a `posTransaction` set into a daily `posZReport` (operational — KEEP);
2. translates the Z-report `taxBreakdown` into a **GL-balanced**
   `posJournalEntryOutbound` using the local `glAccountMapping` chart (bookkeeping
   — DELEGATE);
3. POSTs that journal entry to a **hard-coded** `rtrim($endpoint,'/').'/api/JournalEntry'`
   URL built from the `shillinq_endpoint` app-config value with a Bearer token
   and `X-Idempotency-Key` (bypasses ADR-019 — FIX).

Per cross-app contract #3 and ADR-022, the GL chart, the journal entry, and the
VAT posting are shillinq's domain. pipelinq should hand shillinq the **business
facts of the closed POS day** and let shillinq build the journal.

## Key decisions

1. **Z-report stays; journal entry delegates.** `posZReport` is a commercial
   end-of-day takings/cash-drawer reconciliation — operational state a POS app
   legitimately owns. The *journal entry* derived from it is bookkeeping and moves
   to shillinq. The pipelinq Z-report keeps a thin **projection** of the shillinq
   outcome (`shillinqJournalEntryId`, `bookkeepingStatus`) so operators can see
   "this day is booked" without pipelinq re-owning the ledger.

2. **GL mapping leaves pipelinq.** The `glAccountMapping` schema (VAT-rate → debit/
   credit GL accounts) is a chart-of-accounts fragment — pure ledger config. It is
   removed from pipelinq's register; the VAT→GL translation happens in shillinq
   from the raised event's tax breakdown. pipelinq sends the **tax breakdown**, not
   pre-mapped GL lines.

3. **Dispatch through the ADR-019 integration registry.** Replace the hard-coded
   `/api/JournalEntry` HTTP POST with a registry-resolved call: pipelinq raises a
   `shillinq.JournalEntry.raise` integration message whose endpoint, auth, and
   idempotency policy are resolved from the integration registry — matching how
   `pipelinq-project-to-shillinq-ledger` / `-wip` / `-ap` already dispatch through
   OpenRegister's WebhookService rather than bespoke per-service HTTP. The
   deterministic idempotency key (`SHA256(zReport.uuid + reportDate)`) is preserved
   so re-raises resolve to the same shillinq journal.

4. **`posJournalEntryOutbound` retired as an owned record.** It becomes an
   ephemeral dispatch payload, not a persisted parallel-ledger schema. Its persisted
   delivery/retry state collapses into the Z-report projection fields
   (`bookkeepingStatus` pending/raised/failed, `shillinqJournalEntryId`).

5. **Nav/IA correction (ADR-022 docudesk model).** `Boekhoudkundige Afhandeling`
   and `POS bookkeeping` are accounting surfaces that should not be top-level
   transactional nav. The nav entries are removed. The Z-report **list/detail pages
   stay routable** under POS (`/pos/z-reports`, `/pos/z-reports/:id`) for deep links
   and operational reconciliation; only the bookkeeping framing and the GL-mapping
   admin page go.

6. **Billing entry point resolved via registry.** `BillingApproval` already links
   to `/index.php/apps/shillinq/`; this change resolves the target through the
   integration registry so it points at the configured shillinq deployment, and
   relabels it `Timesheet approval` (approval is the pipelinq act; billing is the
   shillinq destination).

## Alternatives considered

- **Delete the whole POS bookkeeping module including the Z-report.** Rejected —
  the Z-report is genuine operational commercial state (cash-drawer close,
  takings, kassakoppeling). Removing it would lose a POS capability, not just a
  bookkeeping duplication.
- **Keep the local journal build, only swap the HTTP for the registry.** Rejected —
  that still leaves pipelinq owning a `glAccountMapping` chart-of-accounts and
  building GL-balanced journals, i.e. a parallel ledger. Contract #3 puts the GL
  in shillinq; pipelinq should send facts, not pre-booked lines.
- **A new dedicated PosJournalDispatcher service.** Rejected per ADR-012 — three
  shillinq dispatchers already exist following the same pattern; reuse that pattern.

## Exact surfaces touched

| Kind | Id / slug | Location | Action |
| --- | --- | --- | --- |
| Nav entry | `ZReports` ("Boekhoudkundige Afhandeling") | src/manifest.json (menu) | **Remove from nav** |
| Nav entry | `PosBookkeepingSettings` ("POS bookkeeping") admin | src/manifest.json (menu) | **Remove from nav** |
| Nav entry | `BillingApproval` ("Timesheet approval & billing") | src/manifest.json (menu) | **Re-aim** href via registry; relabel `Timesheet approval` |
| Page | `ZReports` `/pos/z-reports` | src/manifest.json (pages) | **Stays routable** (POS deep link) |
| Page | `ZReportDetail` `/pos/z-reports/:id` | src/manifest.json (pages) | **Stays routable** |
| Page | `PosBookkeepingSettings` `/admin/pos-bookkeeping` | src/manifest.json (pages) | **Removed** (GL-mapping admin) |
| Schema | `glAccountMapping` | lib/Settings/register.d/50-pos-end-of-day-bookkeeping.json | **Removed** (delegated to shillinq) |
| Schema | `posJournalEntryOutbound` | lib/Settings/register.d/50-pos-end-of-day-bookkeeping.json | **Removed as owned record** (becomes dispatch payload) |
| Schema | `posZReport` | lib/Settings/register.d/50-pos-end-of-day-bookkeeping.json | **Stays**; add `bookkeepingStatus` + `shillinqJournalEntryId` projection fields |
| Service | `PosBookkeepingService` GL build + `/api/JournalEntry` POST | lib/Service/PosBookkeepingService.php | **Re-aimed** to ADR-019 registry; GL build removed |
| Component | `PosBookkeepingSettings.vue` | src/views/admin/ | **Removed** with its nav/page |

## Migration / rollout

- **In-flight `posJournalEntryOutbound` records** (status `pending`/`failed`/`staged`,
  not yet `posted`): a fail-safe, idempotent `lib/Repair/MigratePosBookkeepingToShillinq`
  step re-raises each via the registry path using the **same** deterministic
  idempotency key, so shillinq de-duplicates against any journal already created by
  the old `/api/JournalEntry` POST. Records already `posted` are mapped to the
  Z-report projection (`bookkeepingStatus: raised`, copy `shillinqJournalEntryId`)
  and then the outbound record is left read-only (never dropped).
- **`glAccountMapping` objects** are not deleted by the repair step (no data loss);
  they are simply no longer used by pipelinq. A note in the Z-report projection
  documents that GL mapping now lives in shillinq. Operators export the chart to
  shillinq once (manual, one-off, documented in the change tasks).
- Repair step uses `setRegister(slug)->setSchema(Name)->findAll([])` and POSITIONAL
  args for all OCP service calls (per fleet convention).
- Rollout is additive-then-subtractive: ship the registry dispatch + projection
  fields first, run the repair, then remove the nav entries and the GL/outbound
  schemas in the same release once in-flight records are drained.

## Risks

- **Double-booking** if the deterministic idempotency key changes — mitigated by
  preserving `SHA256(zReport.uuid + reportDate)` exactly.
- **shillinq unavailable at Z-report close** — the raise is fire-and-retry (same as
  the existing AP/WIP/ledger paths); the Z-report itself still closes
  (operational), `bookkeepingStatus` stays `pending`, admin is notified on permanent
  failure. The POS day is never blocked by a bookkeeping outage.
- **Lost GL mapping** — mitigated by NOT deleting `glAccountMapping` objects and by
  the documented one-off export to shillinq.

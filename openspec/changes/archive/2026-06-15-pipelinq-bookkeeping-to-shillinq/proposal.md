---
kind: code
depends_on: [pipelinq-project-to-shillinq-ledger, pipelinq-time-to-shillinq-wip, pipelinq-expense-to-shillinq-ap]
---

# Proposal: pipelinq-bookkeeping-to-shillinq

`kind: code` per ADR-032 — integration glue (registry-mediated dispatch) plus a
nav/IA correction and a non-destructive data migration.

**Cites:** ADR-019 (integration-registry), ADR-022 (apps-consume-or-abstractions),
ADR-012 (deduplication), and **cross-app contract #3** (Bookkeeping / billing /
accounting → shillinq).

## Summary

pipelinq currently carries a parallel **bookkeeping surface** that, per cross-app
contract #3, belongs in shillinq (ledger, journals, invoicing, VAT, billing):

- A top-level nav entry **"Boekhoudkundige Afhandeling"** (`ZReports`,
  `route /pos/z-reports`) and an admin page **"POS bookkeeping"**
  (`PosBookkeepingSettings`, `route /admin/pos-bookkeeping`).
- A locally-owned **general-ledger** chart — the `glAccountMapping` schema (maps
  each Dutch VAT rate to debit/credit GL accounts) — and a locally-built
  **journal-entry** record, `posJournalEntryOutbound`.
- `PosBookkeepingService`, which translates a Z-report's tax breakdown into a
  **GL-balanced journal entry** and posts it to a **hard-coded**
  `/api/JournalEntry` HTTP endpoint (read from the `shillinq_endpoint` app-config
  value), bypassing the ADR-019 integration registry.

Building GL-balanced journal entries and owning a VAT→GL chart-of-accounts is
bookkeeping. This change **removes pipelinq's parallel bookkeeping surface** and
delegates the accounting consequence to shillinq through the ADR-019 integration
registry. A completed POS day (Z-report) **raises a journal entry in shillinq**
(shillinq owns the GL mapping, the VAT posting, and the journal) rather than
pipelinq booking it locally.

It explicitly **keeps** pipelinq's commercial/operational POS records: the
`posTransaction` sale, the `cashShift`, the **`posZReport`** itself (an
end-of-day cash-drawer / takings reconciliation — operational commercial state,
not accounting), and the `kassakoppelingAudit` fiscal log. Only the
**bookkeeping consequence** of those records delegates.

The second targeted nav entry, **"Timesheet approval & billing"**
(`BillingApproval`), is already a plain `href` to `/index.php/apps/shillinq/`.
This change formalises it: the billing destination is **resolved through the
integration registry** (so it points at the configured shillinq instance) rather
than a hard-coded relative path, and the label is clarified to reflect that
approval stays in pipelinq while billing lives in shillinq.

## What stays vs what delegates

| Concern | Object / surface | Decision |
| --- | --- | --- |
| POS sale | `posTransaction` | **Stays** (commercial) |
| Cash drawer / shift | `cashShift` | **Stays** (operational) |
| End-of-day takings reconciliation | `posZReport` | **Stays** (operational commercial record) |
| Fiscal cash-register audit | `kassakoppelingAudit` | **Stays** (operational compliance) |
| Time entry + approval | `timeEntry` + approval workflow | **Stays** (commercial; approval is a pipelinq act) |
| VAT→GL chart of accounts | `glAccountMapping` schema | **Delegates** to shillinq (ledger config) |
| GL-balanced journal entry | `posJournalEntryOutbound` schema | **Delegates** to shillinq (journal authority) |
| Journal posting logic | `PosBookkeepingService` GL build + `/api/JournalEntry` POST | **Delegates** — re-aimed through ADR-019 registry; raises a journal in shillinq |
| Bookkeeping nav | `Boekhoudkundige Afhandeling` (`ZReports`), `POS bookkeeping` (`PosBookkeepingSettings`) | **Removed from nav** (Z-report list stays routable under POS for deep links) |
| Billing entry point | `Timesheet approval & billing` (`BillingApproval`) | **Re-aimed** to registry-resolved shillinq URL |

## Deduplication (ADR-012)

A registry-mediated, event-driven path from pipelinq to shillinq already exists
for three concerns — `pipelinq-project-to-shillinq-ledger`,
`pipelinq-time-to-shillinq-wip`, and `pipelinq-expense-to-shillinq-ap` — each
dispatching CloudEvents to shillinq. This change does **not** introduce a fourth
parallel dispatcher; it **retires** the one remaining surface that still owns
local bookkeeping artefacts (GL mapping + locally-built journal entry + a
hard-coded `/api/JournalEntry` HTTP call) and folds the POS-day journal raise
into the same ADR-019 registry contract the other three already follow. Net
effect: one consistent shillinq integration boundary, fewer schemas, no
duplicate ledger logic.

## Depends on

**Depends on:** `pipelinq-project-to-shillinq-ledger`, `pipelinq-time-to-shillinq-wip`,
`pipelinq-expense-to-shillinq-ap` (establish the pipelinq→shillinq dispatch
pattern and the `shillinq_*` app-config integration toggles this change
generalises onto the registry), and the shillinq ledger/journal endpoint
(consumes the raised journal entry).

## Out of scope

- shillinq-side journal-entry persistence, VAT posting, or GL reconciliation
  (shillinq owns these).
- Reverse sync (shillinq → pipelinq).
- Changes to `posTransaction`, `cashShift`, or the Z-report generation pipeline
  itself (those stay; only the post-Z-report bookkeeping consequence moves).
- Migration of historical shillinq journal entries already created before this
  change.

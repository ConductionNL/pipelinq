# Proposal: pos-end-of-day-bookkeeping-post

## Problem

Pipelinq has no integration pathway from POS transactions to Shillinq journal entries. While pos-transaction-core provides the cart and line-item model, there is no pipeline to:
- Aggregate daily Z-report (end-of-day settlement) from `posTransaction` objects
- Convert the Z-report to an outbound message with proper GL account mapping
- POST the journal entry to Shillinq with idempotency guarantees
- Retry failed submissions with exponential backoff
- Track submission status and reconciliation state

Every surveyed competitor (13/13) implements this as a mandatory integration bridge
for regulatory compliance and automated accounting. Without it, POS transactions are
orphaned in Pipelinq with no audit trail in the ERP system.

## Solution

Implement the POS End-of-day Bookkeeping Post Pipeline with:

1. **Z-report schema** (`posZReport`, `schema:Invoice`) — daily settlement summary aggregating:
   - Sum of all `posTransaction` objects confirmed on a given date (per cashier/terminal/store)
   - Per-rate tax breakdown and totals
   - Settlement amount and payment method breakdown
   - Ledger account mapping configuration per tax rate

2. **Outbound message schema** (`posJournalEntryOutbound`) — staging entity for:
   - Z-report reference and timestamp
   - GL account pair mapping (debit: revenue account, credit: bank/cash account)
   - Per-rate line items matching tax breakdown
   - Idempotency key (SHA256 hash of Z-report UUID + date)
   - Submission status (draft → pending → posted / failed)
   - Retry attempt log with timestamps and error messages

3. **Z-report batch job** — scheduled daily (configurable time, e.g., 23:59):
   - Queries confirmed/settled `posTransaction` objects for the given date
   - Aggregates by terminal and payment method
   - Creates `posZReport` object with settlement summary
   - Triggers automatic outbound message creation

4. **Posting service** — `PosBookkeepingService`:
   - Converts Z-report + outbound message to Shillinq JournalEntry payload
   - POSTs to `shillinq.JournalEntry.post` with `X-Idempotency-Key` header
   - Stores response CloudEvent ID and ledger batch reference
   - On 4xx: marks as failed, logs validation error, alerts accounting team
   - On 5xx or network timeout: increments retry counter, schedules exponential backoff
     (1 min, 5 min, 15 min, 1 hour) up to 5 attempts
   - On 200/201: marks as posted, stores JournalEntry UUID reference

5. **UI & reconciliation**:
   - Z-report list view with status filter (pending, posted, failed, reconciled)
   - Detail view showing transaction rollup, ledger account mapping, submission history
   - Manual resubmit button for failed entries (with override confirmation)
   - Reconciliation flag when Shillinq confirms posting and balance-check passes

6. **Event emission** — on successful posting:
   - Emit `pipelinq.PosJournalEntry.posted` with journal entry reference
   - Emit `pipelinq.PosZReport.submitted` with submission timestamp and ledger batch ID
   - Consumed by Shillinq for reconciliation and audit trail linking

## Scope

- `posZReport` and `posJournalEntryOutbound` schemas in `pipelinq_register.json`
  with seed data (3–5 objects each, various statuses)
- `PosBookkeepingService` with methods:
  - `generateZReport(date, terminalId)` — aggregates transactions, creates Z-report object
  - `createOutboundMessage(zReportId)` — stages journal entry with GL mapping
  - `postToShillinq(outboundMessageId)` — submits with idempotency key and retry logic
  - `handleRetry(outboundMessageId)` — exponential backoff scheduler
- Background job (`IJobList`) for daily Z-report generation (configurable via admin settings)
- Background job for retry attempt execution
- Z-report list view: date filter, terminal filter, status filter, search
- Z-report detail view: transaction summary, tax breakdown, ledger account mapping table,
  submission timeline with error messages, manual resubmit button
- Backend `PosBookkeepingController` for status queries and manual resubmit action
- Admin settings panel to configure:
  - Daily Z-report generation time (HH:MM format)
  - GL account mapping per tax rate (debit/credit accounts, cost center)
  - Shillinq API endpoint and authentication (bearer token)
  - Retry backoff schedule and max attempts
  - Alert email for failed submissions
- Navigation entry "Boekhoudkundige Afhandeling" in Pipelinq sidebar

## Out of scope

- Currency conversion for multi-currency transactions — V1
- Consolidated Z-report across multiple stores/locations — V1
- Audit trail export (XML, SAF-T) — Enterprise
- Bank reconciliation matching against bank statement import — V2
- Payment terminal reconciliation (Adyen, CCV balancing) — V1
- Discount / promotion accounting split rules — V2
- Split revenue recognition (installments, gift cards) — Enterprise
- Reverse journal entries for refunds (automatic or manual) — V1

## Impact

- **Accounting**: Daily transactions automatically flow to Shillinq with full audit trail
- **Compliance**: Ledger account mapping ensures regulatory GL structure is maintained
- **Auditability**: Idempotency keys prevent duplicate journal entries; retry log documents
  all submission attempts
- **Reconciliation**: Z-report status and ledger reference enable balance verification
  against bank statement and Shillinq trial balance
- **Integration**: Shillinq receives reliable, idempotent journal entry feed; can subscribe
  to `pipelinq.PosJournalEntry.posted` for downstream reconciliation workflows

## Dependencies

- **pos-transaction-core** — requires settled/confirmed transaction aggregation capability
- **pos-cash-management** — optional but recommended; Z-report can include payment method
  breakdown from cash drawer management
- **Shillinq API** — `POST /api/JournalEntry` endpoint with idempotency support (CloudEvents)
- **OpenRegister** — `posZReport`, `posJournalEntryOutbound` schemas stored in registers
- **WebhookService** — CloudEvent emission on successful posting
- **AuthorizationService** — permission check for GL account mapping changes (accounting role)

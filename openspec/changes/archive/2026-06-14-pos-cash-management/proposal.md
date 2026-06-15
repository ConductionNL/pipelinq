# Proposal: pos-cash-management

## Problem

POS systems in all 13 surveyed competitors include cash management as a foundational feature: declaring an opening float, removing cash during the shift (mid-shift drops), performing a physical count at close (blind count), and reconciling the variance. Pipelinq's pos-transaction-core enables cashiers to ring sales, but provides no way to manage the cash drawer itself—there is no mechanism to record opening float, track drops, perform a blind count, or emit a cash diff journal entry to Shillinq for accounting reconciliation.

Without cash management, a POS operation cannot close the day, cannot prove cash accountability, and cannot reconcile to bank deposits or daily P&L.

## Solution

Implement cash drawer management via four new OpenRegister schemas in `pipelinq_register.json`:

1. **cashShift** — represents a drawer's shift period (open to close) with opening float declaration, close type (blind vs. declared), and EOD status
2. **cashDrop** — records a mid-shift cash removal with amount, timestamp, and reason
3. **cashCount** — records a physical count (cash on hand at close) with denomination breakdown and count timestamp
4. **cashDiff** — variance report comparing expected (float + sales − drops) to actual (count), with diff amount, percentage, and reconciliation status

The backend emits a `pipelinq.CashDiff.confirmed` CloudEvent on reconciliation so that Shillinq posts the variance as an adjustment journal entry. No new controllers are required; lifecycle transitions are minimal (open/close shift, record count, confirm diff).

## Scope

- Four new schemas: `cashShift`, `cashDrop`, `cashCount`, `cashDiff` added to `pipelinq_register.json`
- Seed data (3–5 objects per schema, Dutch-localized)
- CashShift detail view with float declaration, live drops list, count entry, and diff panel
- CashShift list view: open/closed status filter, date range, drawer filter
- Blind count entry form (no prior cash hint; count confirms when within 2% tolerance or manager approves variance)
- Backend `CashShiftService` for lifecycle transitions and diff calculation
- `pipelinq.CashDiff.confirmed` CloudEvent emission on reconciliation
- Navigation entry "Kassalade" in Pipelinq POS sidebar

## Out of Scope

- Cash register / drawer hardware integration (Epson, Star Micronics) — V1
- Multi-currency cash handling — V1
- Bill / coin denomination breakdown UI (count is entered as a total amount) — V1
- Till reconciliation rules and exception workflows — V1
- Cash aging and obsolescence tracking — V1
- Scheduled cash removal / sweep routes — V1
- Check / traveler's check handling — V1
- Automatic cash prediction (e.g. "expect €500 based on transaction volume") — V1

## Impact

- **POS Operators**: Can now open a shift with declared float, record drops, count cash, and close the drawer
- **Shift Managers**: Can review cash reconciliations, approve variances, and track accountability
- **Shillinq**: Receives `pipelinq.CashDiff.confirmed` events to post cash variance adjustments to the GL
- **Navigation**: New "Kassalade" section in Pipelinq POS sidebar, alongside "Kassabon"

## Dependencies

- **posTransaction-core** — cash flow is derived from sum of confirmed transactions during the shift
- **posTransactionLine** — individual sales contribute to expected cash
- **OpenRegister** — object storage, REST API, CloudEvent dispatch via WebhookService
- **Shillinq** — must subscribe to `pipelinq.CashDiff.confirmed` for GL posting

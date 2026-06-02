# Proposal: pipelinq-expense-to-shillinq-ap

## Problem

Captured and approved expenses in pipelinq carry no path to shillinq's Accounts Payable (AP) system. After `expense-capture-core` marks an expense as `approved`, two critical gaps persist:

1. **No AP voucher creation on approval** — Shillinq has no knowledge of approved pipelinq expenses. Finance teams must manually review pipelinq's approved expense list and re-enter values into shillinq's AP ledger, creating reconciliation risk and payment delay. The industry standard (Expensify, Concur, Ramp, Pleo) is automatic AP voucher creation at the moment of approval.

2. **No audit trail for the cross-app event** — Without a structured integration there is no record of which approved expenses have been sent to shillinq's AP system and which have not. Manual transfers leave no system-level trace, making expense reconciliation unreliable and audit non-compliant.

3. **Billable cost routing accuracy** — Expenses marked as billable must flow to the correct project in shillinq for pass-through billing. Without integration, billable status and project mapping are lost, creating invoicing errors.

## Solution

Implement a one-way event-driven integration that fires an AP voucher event to shillinq the moment an expense is approved:

1. **Expense approval event hook** — Register a PHP listener (`ExpenseApprovalListener`) on the `ExpenseApprovedEvent` dispatched by `expense-capture-core`. The listener fires when an `expense` status transitions to `approved`.

2. **AP voucher dispatch** — The listener calls `ShillinqApService::dispatchApEvent()`, which constructs a CloudEvents-formatted AP payload and dispatches it to shillinq's AP endpoint via OpenRegister's `WebhookService`. The payload includes: expense UUID, amount, category, client reference, project reference (if billable), approved-by user, and approved-at timestamp.

3. **Sync status tracking** — The `expense` schema is extended with `apSyncStatus` (pending / synced / failed) and `apSyncedAt` fields. The listener updates these fields on dispatch outcome. `WebhookService`'s built-in retry queue handles failed deliveries; after retry exhaustion the admin receives a Nextcloud notification.

## Scope

- `lib/Listener/ExpenseApprovalListener.php` — listens for `ExpenseApprovedEvent`; sets `apSyncStatus` and triggers dispatch
- `lib/Service/ShillinqApService.php` — maps `expense` fields to CloudEvents AP payload; calls `WebhookService`
- Extend `expense` schema in `lib/Settings/pipelinq_register.json` with `apSyncStatus` and `apSyncedAt`
- `apSyncStatus` badge column in expense list view (from `expense-capture-core`)
- Shillinq AP section + retry button in expense detail view (from `expense-capture-core`)
- Admin settings field: `shillinq_ap_webhook_url` stored via `IAppConfig`
- i18n keys for sync status labels and admin setting (Dutch + English)
- 5 seed `expense` objects demonstrating the new `apSyncStatus` field values

**Depends on:** `expense-capture-core` (provides `ExpenseApprovedEvent`; `expense` with `status: approved` transition), billable status and project mapping fields on `expense`

## Out of Scope

- Reverse sync (shillinq → pipelinq)
- AP invoice generation or payment workflows on the shillinq side
- Expense rate cards or reimbursement policy configuration in pipelinq
- Unapproval / reversal event handling (AP credit notes)
- Real-time AP dashboard widget in pipelinq (separate change)
- Bulk retroactive sync for expenses approved before this change is deployed

## Success Criteria

- When an `expense` status transitions to `approved`, an AP event is dispatched to the configured shillinq webhook within 5 seconds
- The event payload contains: `expenseId`, `amount`, `categoryId`, `clientId`, `projectId` (if billable), `approvedBy`, `approvedAt`
- `apSyncStatus` on the `expense` is set to `synced` on successful delivery; `failed` after retry exhaustion
- The admin receives a Nextcloud notification when an AP dispatch permanently fails
- An administrator can trigger manual re-dispatch from the expense detail view for `failed` entries
- An administrator can configure `shillinq_ap_webhook_url` in the pipelinq admin settings panel
- The expense list view shows an `apSyncStatus` badge per row
- `npm run build` produces zero errors after all changes

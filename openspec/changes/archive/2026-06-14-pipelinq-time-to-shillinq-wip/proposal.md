# Proposal: pipelinq-time-to-shillinq-wip

## Problem

Approved time entries in pipelinq carry no path to shillinq's billable WIP (Work In Progress) balance. After `time-approval-workflow` marks a time entry as `approved`, three gaps persist:

1. **No WIP accrual on approval** — Shillinq has no knowledge of approved pipelinq time. Finance teams must manually review pipelinq's approved hours list and re-enter values into shillinq's WIP ledger, creating reconciliation risk and billing delay. The industry standard (Harvest, Tempo, Everhour, Bigtime) is automatic WIP accrual at the moment of approval.

2. **No audit trail for the cross-app event** — Without a structured integration there is no record of which approved time entries have been sent to shillinq's WIP balance and which have not. Manual transfers leave no system-level trace, making billing reconciliation unreliable.

3. **WIP balance timing inaccuracy** — Delayed manual sync means shillinq's WIP balance does not reflect the organization's true billable position in real time. For professional services organizations billing on time and materials, this creates cash-flow forecasting errors and invoice timing issues.

## Solution

Implement a one-way event-driven integration that fires a WIP ledger event to shillinq the moment a time entry is approved:

1. **Time approval event hook** — Register a PHP listener (`TimeApprovalListener`) on the `TimeEntryApprovedEvent` dispatched by `time-approval-workflow`. The listener fires when a `timeEntry` status transitions to `approved`.

2. **WIP event dispatch** — The listener calls `ShillinqWipService::dispatchWipEvent()`, which constructs a CloudEvents-formatted WIP payload and dispatches it to shillinq's WIP ledger endpoint via OpenRegister's `WebhookService`. The payload includes: time entry UUID, hours, billing category, client reference, lead reference, approved-by user, and approved-at timestamp.

3. **Sync status tracking** — The `timeEntry` schema is extended with `wipSyncStatus` (pending / synced / failed) and `wipSyncedAt` fields. The listener updates these fields on dispatch outcome. `WebhookService`'s built-in retry queue handles failed deliveries; after retry exhaustion the admin receives a Nextcloud notification.

## Scope

- `lib/Listener/TimeApprovalListener.php` — listens for `TimeEntryApprovedEvent`; sets `wipSyncStatus` and triggers dispatch
- `lib/Service/ShillinqWipService.php` — maps `timeEntry` fields to CloudEvents WIP payload; calls `WebhookService`
- Extend `timeEntry` schema in `lib/Settings/pipelinq_register.json` with `wipSyncStatus` and `wipSyncedAt`
- `wipSyncStatus` badge column in time entry list view (from `time-entry-core`)
- Shillinq WIP section + retry button in time entry detail view (from `time-entry-core`)
- Admin settings field: `shillinq_wip_webhook_url` stored via `IAppConfig`
- i18n keys for sync status labels and admin setting (Dutch + English)
- 5 seed `timeEntry` objects demonstrating the new `wipSyncStatus` field values

**Depends on:** `time-approval-workflow` (provides `TimeEntryApprovedEvent`; `timeEntry` with `status: approved` transition), `time-entry-core` (provides `timeEntry` schema), `billable-categories-and-tags` (provides `billingCategory` field on `timeEntry`)

## Out of Scope

- Reverse sync (shillinq → pipelinq)
- WIP invoice generation or billing workflows on the shillinq side
- Time entry rate card or pricing configuration in pipelinq
- Unapproval / reversal event handling (WIP credit notes)
- Real-time WIP dashboard widget in pipelinq (separate change)
- Bulk retroactive sync for time entries approved before this change is deployed

## Success Criteria

- When a `timeEntry` status transitions to `approved`, a WIP event is dispatched to the configured shillinq webhook within 5 seconds
- The event payload contains: `timeEntryId`, `hours`, `billingCategoryId`, `clientId`, `leadId`, `approvedBy`, `approvedAt`
- `wipSyncStatus` on the `timeEntry` is set to `synced` on successful delivery; `failed` after retry exhaustion
- The admin receives a Nextcloud notification when a WIP dispatch permanently fails
- An administrator can trigger manual re-dispatch from the time entry detail view for `failed` entries
- An administrator can configure `shillinq_wip_webhook_url` in the pipelinq admin settings panel
- The time entry list view shows a `wipSyncStatus` badge per row
- `npm run build` produces zero errors after all changes

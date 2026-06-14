# Proposal: pipelinq-project-to-shillinq-ledger

## Problem

Projects created and managed in pipelinq carry no path to shillinq's project ledger. After `project-task-hierarchy` establishes the project entity, three gaps persist:

1. **No ledger entry on project creation** — Shillinq has no knowledge of projects created in pipelinq. Finance teams must manually log each new project into shillinq's project ledger, creating duplication risk and administrative overhead. The industry standard (Kantata, Deltek, Harvest, Everhour) is automatic ledger entry at the moment of project creation.

2. **No status sync for phase changes** — When a project transitions through phases (open → in_progress → completed → cancelled), shillinq's ledger does not reflect the change. Project financials, billing status, and phase completion tracking become out of sync. Manual updates are required to track project state across systems.

3. **No audit trail for cross-app events** — Without a structured integration there is no record of which projects have been synced to shillinq or at what phase. This creates reconciliation gaps and makes financial reporting unreliable.

## Solution

Implement a one-way event-driven integration that fires a project ledger event to shillinq when a project is created and whenever its status changes:

1. **Project creation event hook** — Register a PHP listener (`ProjectCreationListener`) that fires when a new `project` object is created via OpenRegister. The listener dispatches a project ledger event to shillinq with project details (name, client, budget, dates, billable status).

2. **Phase status change event hook** — Register a listener (`ProjectPhaseStatusListener`) that fires when a `projectPhase` or `project` status changes (e.g., open → in_progress → completed). The listener constructs a status update ledger event and dispatches it to shillinq's ledger endpoint via `WebhookService`.

3. **Sync status tracking** — The `project` schema is extended with `ledgerSyncStatus` (pending / synced / failed) and `ledgerSyncedAt` fields. Listeners update these fields on dispatch outcome. `WebhookService`'s built-in retry queue handles failed deliveries; after retry exhaustion the admin receives a Nextcloud notification.

## Scope

- `lib/Listener/ProjectCreationListener.php` — listens for new `project` objects; dispatches initial ledger event
- `lib/Listener/ProjectPhaseStatusListener.php` — listens for `projectPhase` and `project` status changes; dispatches update event
- `lib/Service/ShillinqLedgerService.php` — maps `project` and `projectPhase` fields to CloudEvents ledger payload; calls `WebhookService`
- Extend `project` schema in `lib/Settings/pipelinq_register.json` with `ledgerSyncStatus` and `ledgerSyncedAt`
- `ledgerSyncStatus` badge column in project list view
- Project ledger section + retry button in project detail view
- Admin settings field: `shillinq_ledger_webhook_url` stored via `IAppConfig`
- i18n keys for sync status labels and admin setting (Dutch + English)
- 3 seed `project` objects demonstrating the new `ledgerSyncStatus` field values

**Depends on:** `project-task-hierarchy` (provides `project`, `projectPhase` schemas and creation/update lifecycle)

## Out of Scope

- Reverse sync (shillinq → pipelinq)
- Ledger invoice generation or billing workflows on the shillinq side
- Budget reallocation or variance tracking triggered from shillinq
- Project closure or archival workflows triggered from shillinq
- Real-time project financials dashboard widget in pipelinq (separate change)
- Bulk retroactive sync for projects created before this change is deployed

## Success Criteria

- When a new `project` is created, a ledger event is dispatched to the configured shillinq webhook within 5 seconds
- When a `project` or `projectPhase` status changes, an update event is dispatched to shillinq within 5 seconds
- Event payload contains: `projectId`, `projectName`, `clientId`, `phase`, `status`, `billable`, `budgetAmount`, `startDate`, `endDate`, `createdBy`, `createdAt`
- `ledgerSyncStatus` on the `project` is set to `synced` on successful delivery; `failed` after retry exhaustion
- The admin receives a Nextcloud notification when a ledger dispatch permanently fails
- An administrator can trigger manual re-dispatch from the project detail view for `failed` entries
- An administrator can configure `shillinq_ledger_webhook_url` in the pipelinq admin settings panel
- The project list view shows a `ledgerSyncStatus` badge per row
- `npm run build` produces zero errors after all changes

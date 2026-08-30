# Design: pipelinq-project-to-shillinq-ledger

## Architecture

### Data Layer

#### Extended Schema: `project` (from `project-task-hierarchy`)

No new schema is introduced. The `project` entity (from `project-task-hierarchy`) gains two additional properties:

| Property | Type | Required | Description |
|---|---|---|---|
| `ledgerSyncStatus` | string | No | Shillinq ledger sync state: `pending`, `synced`, or `failed`. Null for projects created before this change. |
| `ledgerSyncedAt` | string | No | ISO 8601 UTC timestamp of the last successful ledger sync delivery. |

State transitions:
- On project creation → `pending`
- On `WebhookService` successful delivery → `synced` + `ledgerSyncedAt = now()`
- On `WebhookService` retry exhaustion → `failed`
- On project status change (if `ledgerSyncStatus` is `synced`) → back to `pending`, then resolved as above
- On admin manual retry → back to `pending`, then resolved as above

**Schema.org mapping:** `ledgerSyncStatus` is infrastructure metadata with no schema.org equivalent. Stored as an implementation detail on the object; not exposed in the Dutch API mapping layer.

OpenRegister built-in fields available automatically on `project` (do NOT redefine): `id`, `uuid`, `uri`, `version`, `createdAt`, `updatedAt`, `owner`, `organization`, `register`, `schema`, `relations`, `files`, `auditTrail`, `notes`, `tasks`, `tags`, `status`, `locked`.

---

### Backend

#### `lib/Listener/ProjectCreationListener.php`

Implements `OCP\EventDispatcher\IEventListener`. Registered in `lib/AppInfo/Application.php` for the `ObjectCreatedEvent` from OpenRegister, filtered to `project` schema.

Responsibilities:
1. Receive `ObjectCreatedEvent` carrying the new `project` object
2. Call `ShillinqLedgerService::shouldDispatch()` — returns false if `shillinq_ledger_webhook_url` is not configured; no-op if unconfigured
3. Set `ledgerSyncStatus = pending` on the `project` via `ObjectService::saveObject()`
4. Call `ShillinqLedgerService::dispatchProjectEvent($project, 'created')`
5. On success: update `ledgerSyncStatus = synced`, `ledgerSyncedAt = now()`
6. On `WebhookService` dispatch failure after retries: set `ledgerSyncStatus = failed`; call `NotificationService` to notify admin

The listener MUST be idempotent: if `ledgerSyncStatus` is already `synced`, skip dispatch for creation events. This prevents duplicate ledger entries.

---

#### `lib/Listener/ProjectPhaseStatusListener.php`

Implements `OCP\EventDispatcher\IEventListener`. Registered in `lib/AppInfo/Application.php` for the `ObjectUpdatedEvent` from OpenRegister, filtered to `project` and `projectPhase` schemas.

Responsibilities:
1. Receive `ObjectUpdatedEvent` carrying the updated `project` or `projectPhase` object
2. Check if the `status` property changed (compare old and new objects)
3. If status changed, call `ShillinqLedgerService::shouldDispatch()` — return early if unconfigured
4. Call `ShillinqLedgerService::dispatchPhaseChangeEvent($project, $oldStatus, $newStatus)`
5. Update `ledgerSyncStatus` on the parent `project` based on outcome
6. On failure, notify admin

For `projectPhase` updates, the listener MUST fetch the parent `project` object to update its sync status and dispatch the event in the project's context (not phase-specific).

---

#### `lib/Service/ShillinqLedgerService.php`

Maps `project` and `projectPhase` data to the shillinq ledger CloudEvents payload and dispatches via `WebhookService`.

**Method: `dispatchProjectEvent(array $project, string $eventType): bool`**

Constructs payload:

```json
{
  "specversion": "1.0",
  "type": "nl.conduction.pipelinq.project.created",
  "source": "/apps/pipelinq/projects",
  "id": "<project.uuid>",
  "time": "<project.createdAt>",
  "data": {
    "projectId": "<project.uuid>",
    "projectName": "<project.name>",
    "clientId": "<project.client>",
    "phase": "initial",
    "status": "<project.status>",
    "billable": <project.billable>,
    "budgetAmount": <project.budgetAmount>,
    "budgetHours": <project.budgetHours>,
    "startDate": "<project.startDate>",
    "endDate": "<project.endDate>",
    "createdBy": "<project.owner>",
    "createdAt": "<project.createdAt>"
  }
}
```

Dispatches via `WebhookService::dispatchEvent($webhookUrl, $payload)`. Returns `true` on successful HTTP delivery, `false` on error.

**Method: `dispatchPhaseChangeEvent(array $project, string $oldStatus, string $newStatus): bool`**

Constructs payload:

```json
{
  "specversion": "1.0",
  "type": "nl.conduction.pipelinq.project.status-changed",
  "source": "/apps/pipelinq/projects",
  "id": "<project.uuid>-<timestamp>",
  "time": "<now-in-iso>",
  "data": {
    "projectId": "<project.uuid>",
    "projectName": "<project.name>",
    "clientId": "<project.client>",
    "oldStatus": "<oldStatus>",
    "newStatus": "<newStatus>",
    "phase": "<phase-name-if-applicable>",
    "billable": <project.billable>,
    "budgetAmount": <project.budgetAmount>,
    "updatedAt": "<now>"
  }
}
```

Maps pipelinq status values to shillinq phase names:
- `open` → `initial`
- `in_progress` → `active`
- `completed` → `closed` (or `won` if project marked successful)
- `cancelled` → `cancelled`

Dispatches via `WebhookService::dispatchEvent($webhookUrl, $payload)`.

**Method: `shouldDispatch(): bool`**

Returns `true` only if `shillinq_ledger_webhook_url` is a non-empty valid URL. Called by listeners before any dispatch attempt.

---

#### `lib/Settings/Admin.php` (modified)

Add `shillinq_ledger_webhook_url` to the pipelinq admin settings response. The Vue admin settings panel gains a URL input field under an "Integraties" section header. Value is persisted via `IAppConfig`.

---

### Frontend

#### Project list view (from `project-task-hierarchy`) — modified

Add a `ledgerSyncStatus` column to the project data table. Column renders a color-coded badge:

| `ledgerSyncStatus` value | Badge text (Dutch) | Color |
|---|---|---|
| `synced` | `Ledger gesynchroniseerd` | green (`#28a745`) |
| `pending` | `Ledger in behandeling` | yellow (`#ffc107`) |
| `failed` | `Ledger mislukt` | red (`#dc3545`) |
| null | `–` | grey (`#6c757d`) |

Badge is a `<span>` with inline `background-color`. No separate component file needed.

#### Project detail view (from `project-task-hierarchy`) — modified

Add a shillinq ledger section above the WBS tree:

- **Ledger status card** showing current `ledgerSyncStatus` with a colored badge
- **Last synced** timestamp (from `ledgerSyncedAt`)
- **Manual retry button** (enabled only if `ledgerSyncStatus` is `failed`):
  - On click: POST to `/apps/pipelinq/api/ledger/retry/{projectId}`
  - On success: update `ledgerSyncStatus` and refresh the view
  - On error: show error toast with details

The section is visible only if the admin has configured a shillinq webhook URL (check via app settings).

---

## Seed Data

Added to `lib/Settings/pipelinq_register.json` under `components.objects[]`.

### project objects

```json
{
  "@self": { "register": "pipelinq", "schema": "project", "slug": "project-digitalisering-amsterdam" },
  "name": "Digitalisering Dienstverlening",
  "client": "@ref:client-gemeente-amsterdam",
  "description": "Modernisering van de publieke dienstverlening via digitale kanalen",
  "status": "in_progress",
  "billable": true,
  "budgetHours": 400,
  "budgetAmount": 56000.00,
  "hourlyRate": 140.00,
  "startDate": "2026-02-01",
  "endDate": "2026-08-31",
  "color": "#4A90D9",
  "ledgerSyncStatus": "synced",
  "ledgerSyncedAt": "2026-05-20T14:32:00Z"
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "project", "slug": "project-website-devries" },
  "name": "Website Herontwerp",
  "client": "@ref:client-devries-partners",
  "description": "Redesign van de corporate website inclusief CMS-migratie",
  "status": "open",
  "billable": true,
  "budgetHours": 160,
  "budgetAmount": 19200.00,
  "hourlyRate": 120.00,
  "startDate": "2026-03-15",
  "endDate": "2026-06-30",
  "color": "#F5A623",
  "ledgerSyncStatus": "pending",
  "ledgerSyncedAt": null
}
```

```json
{
  "@self": { "register": "pipelinq", "schema": "project", "slug": "project-hr-techbedrijf" },
  "name": "HR Systeem Implementatie",
  "client": "@ref:client-techbedrijf-bv",
  "description": "Implementatie en migratie naar nieuw HR-platform",
  "status": "completed",
  "billable": false,
  "budgetHours": 80,
  "budgetAmount": 0,
  "hourlyRate": 0,
  "startDate": "2026-05-01",
  "endDate": "2026-07-31",
  "color": "#7ED321",
  "ledgerSyncStatus": "failed",
  "ledgerSyncedAt": "2026-05-15T09:12:00Z"
}
```

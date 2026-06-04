# Design: pipelinq-time-to-shillinq-wip

## Architecture

### Data Layer

#### Extended Schema: `timeEntry` (from `time-entry-core`)

No new schema is introduced. The `timeEntry` entity (from `time-entry-core`, extended previously by `billable-categories-and-tags`) gains two additional properties:

| Property | Type | Required | Description |
|---|---|---|---|
| `wipSyncStatus` | string | No | Shillinq WIP sync state: `pending`, `synced`, or `failed`. Null for entries not yet approved or created before this change. |
| `wipSyncedAt` | string | No | ISO 8601 UTC timestamp of the last successful WIP sync delivery. |

State transitions:
- On approval event received → `pending`
- On `WebhookService` successful delivery → `synced` + `wipSyncedAt = now()`
- On `WebhookService` retry exhaustion → `failed`
- On admin manual retry → back to `pending`, then resolved as above

**Schema.org mapping:** `wipSyncStatus` is infrastructure metadata with no schema.org equivalent. Stored as an implementation detail on the object; not exposed in the Dutch API mapping layer.

OpenRegister built-in fields available automatically on `timeEntry` (do NOT redefine): `id`, `uuid`, `uri`, `version`, `createdAt`, `updatedAt`, `owner`, `organization`, `register`, `schema`, `relations`, `files`, `auditTrail`, `notes`, `tasks`, `tags`, `status`, `locked`.

---

### Backend

#### `lib/Listener/TimeApprovalListener.php`

Implements `OCP\EventDispatcher\IEventListener`. Registered in `lib/AppInfo/Application.php` for the `TimeEntryApprovedEvent` class from `time-approval-workflow`.

Responsibilities:
1. Receive `TimeEntryApprovedEvent` carrying the approved `timeEntry` UUID and `approvedBy` user
2. Call `ShillinqWipService::shouldDispatch()` — returns false if `shillinq_wip_webhook_url` is not configured; no-op if unconfigured
3. Set `wipSyncStatus = pending` on the `timeEntry` via `ObjectService::saveObject()`
4. Call `ShillinqWipService::dispatchWipEvent($timeEntry, $approvedBy, $approvedAt)`
5. On success: update `wipSyncStatus = synced`, `wipSyncedAt = now()`
6. On `WebhookService` dispatch failure after retries: set `wipSyncStatus = failed`; call `NotificationService` to notify admin

The listener MUST be idempotent: if `wipSyncStatus` is already `synced`, skip dispatch. This prevents duplicate WIP entries if the event is emitted more than once.

#### `lib/Service/ShillinqWipService.php`

Maps `timeEntry` data to the shillinq WIP CloudEvents payload and dispatches via `WebhookService`.

**Method: `dispatchWipEvent(array $timeEntry, string $approvedBy, string $approvedAt): bool`**

Constructs payload:

```json
{
  "specversion": "1.0",
  "type": "nl.conduction.pipelinq.time.approved",
  "source": "/apps/pipelinq/time-entries",
  "id": "<timeEntry.uuid>",
  "time": "<approvedAt>",
  "data": {
    "timeEntryId": "<timeEntry.uuid>",
    "hours": 2.5,
    "billingCategoryId": "<timeEntry.billingCategory>",
    "clientId": "<timeEntry.client>",
    "leadId": "<timeEntry.lead>",
    "approvedBy": "<approvedBy>",
    "approvedAt": "<approvedAt>"
  }
}
```

Dispatches via `WebhookService::dispatchEvent($webhookUrl, $payload)`. Returns `true` on successful HTTP delivery, `false` on error. The `WebhookService` handles retry logic (3 retries, exponential backoff) — `ShillinqWipService` receives the final delivery outcome.

Webhook URL is read from `IAppConfig::getValueString('pipelinq', 'shillinq_wip_webhook_url', '')`.

**Method: `shouldDispatch(): bool`**

Returns `true` only if `shillinq_wip_webhook_url` is a non-empty valid URL. Called by `TimeApprovalListener` before any dispatch attempt.

#### `lib/Settings/Admin.php` (modified)

Add `shillinq_wip_webhook_url` to the pipelinq admin settings response. The Vue admin settings panel gains a URL input field under an "Integraties" section header. Value is persisted via `IAppConfig`.

---

### Frontend

#### Time entry list view (from `time-entry-core`) — modified

Add a `wipSyncStatus` column to the `CnDataTable`. Column renders a color-coded badge:

| `wipSyncStatus` value | Badge text (Dutch) | Color |
|---|---|---|
| `synced` | `WIP gesynchroniseerd` | green (`#28a745`) |
| `pending` | `WIP in behandeling` | yellow (`#ffc107`) |
| `failed` | `WIP mislukt` | red (`#dc3545`) |
| null | `–` | grey (`#6c757d`) |

Badge is a `<span>` with inline `background-color`. No separate component file needed.

#### Time entry detail view (from `time-entry-core`) — modified

Add a "Shillinq WIP" section to the detail sidebar. Section shows:
- `wipSyncStatus` badge (same color scheme as list)
- `wipSyncedAt` timestamp formatted with `t('pipelinq', 'WIP synced at')` label (hidden when null)
- "Opnieuw synchroniseren" `NcButton` visible only when `wipSyncStatus === 'failed'`

The retry button calls `ShillinqWipService::dispatchWipEvent()` via a small `POST` to a new backend endpoint `POST /api/time-entries/{uuid}/wip-retry`. The endpoint is protected by Nextcloud CSRF and requires admin or manager role.

#### Admin settings panel — modified

Under existing admin settings page, add "Integraties" section with a single URL field:
- Label: `t('pipelinq', 'Shillinq WIP webhook URL')`
- Placeholder: `https://shillinq.example.com/api/wip/events`
- Saved via `IAppConfig` on blur/submit
- Validated client-side as a valid HTTP(S) URL pattern

---

### Integration Points

| System | Integration |
|---|---|
| `time-approval-workflow` | Emits `TimeEntryApprovedEvent`; `TimeApprovalListener` consumes it |
| OpenRegister `WebhookService` | Dispatches CloudEvents WIP payload to shillinq endpoint with retry |
| OpenRegister `ObjectService` | Updates `timeEntry.wipSyncStatus` and `wipSyncedAt` after dispatch |
| Nextcloud `NotificationService` | Notifies admin on permanent WIP sync failure |
| Nextcloud `IAppConfig` | Stores `shillinq_wip_webhook_url` app setting |
| Nextcloud `IEventDispatcher` | Registers `TimeApprovalListener` in `Application.php` |

---

### Reuse Analysis

| Capability needed | OpenRegister / nextcloud-vue component | Custom code needed? |
|---|---|---|
| Event dispatch to shillinq | `WebhookService::dispatchEvent()` | Payload mapping only |
| Retry on delivery failure | `WebhookService` built-in retry queue | No |
| `timeEntry` schema extension | `lib/Settings/pipelinq_register.json` | 2 new properties |
| `wipSyncStatus` badge in list | Inline `<span>` with class binding | Minimal (badge only) |
| Admin setting for webhook URL | `IAppConfig` + existing admin panel | Config field only |
| Failure notification | `NotificationService` (built-in) | Notification dispatch |
| Audit trail for sync events | Automatic via `AuditTrailService` on `ObjectService::saveObject()` | No |
| Retry endpoint | New `POST /api/time-entries/{uuid}/wip-retry` | Small PHP controller action |

`WebhookService` was evaluated as the correct dispatch mechanism per ADR-001-data-layer ("Webhooks & Events — NO custom webhook controllers or event dispatchers"). A custom HTTP client call to shillinq without `WebhookService` would duplicate retry logic and CloudEvents formatting — both already provided.

---

### i18n

| Key | English | Dutch |
|---|---|---|
| `WIP sync status` | `WIP sync status` | `WIP-synchronisatiestatus` |
| `WIP synced` | `WIP synced` | `WIP gesynchroniseerd` |
| `WIP pending` | `WIP pending` | `WIP in behandeling` |
| `WIP sync failed` | `WIP sync failed` | `WIP synchronisatie mislukt` |
| `Retry WIP sync` | `Retry WIP sync` | `Opnieuw synchroniseren` |
| `Shillinq WIP webhook URL` | `Shillinq WIP webhook URL` | `Shillinq WIP-webhook-URL` |
| `WIP synced at` | `WIP synced at` | `WIP gesynchroniseerd op` |
| `Not synced` | `Not synced` | `Niet gesynchroniseerd` |

All keys follow ADR-007 sentence case with English as the key string.

---

## Seed Data

This change extends the `timeEntry` schema with `wipSyncStatus` and `wipSyncedAt` but introduces no new schema. Per ADR-001-data-layer, seed data is required when a change introduces or modifies schemas. The following 5 example `timeEntry` seed objects are added to `lib/Settings/pipelinq_register.json` under `components.objects[]`. They supplement the `time-entry-core` seed data and demonstrate all three `wipSyncStatus` states.

### 1. Declarabel uur — Adviestraject De Vries BV (gesynchroniseerd)

```json
{
  "@self": { "register": "pipelinq", "schema": "timeEntry", "slug": "time-entry-wip-synced-1" },
  "title": "Adviesgesprek intake De Vries BV",
  "description": "Initieel adviesgesprek over digitaliseringsproject; scope en planning besproken met directie.",
  "hours": 2.5,
  "date": "2026-05-15",
  "status": "approved",
  "billingCategory": "billing-category-declarabel",
  "wipSyncStatus": "synced",
  "wipSyncedAt": "2026-05-15T16:05:23Z"
}
```

### 2. Declarabel uur — Implementatiebegeleiding Bakker & Partners (gesynchroniseerd)

```json
{
  "@self": { "register": "pipelinq", "schema": "timeEntry", "slug": "time-entry-wip-synced-2" },
  "title": "Technische implementatie OpenRegister module",
  "description": "Configuratie en integratietest van de OpenRegister koppeling op de klantomgeving van Bakker & Partners.",
  "hours": 4.0,
  "date": "2026-05-16",
  "status": "approved",
  "billingCategory": "billing-category-declarabel",
  "wipSyncStatus": "synced",
  "wipSyncedAt": "2026-05-16T09:42:10Z"
}
```

### 3. WBSO O&O uur — Gemeente Westerhout (gesynchroniseerd)

```json
{
  "@self": { "register": "pipelinq", "schema": "timeEntry", "slug": "time-entry-wip-synced-3" },
  "title": "Onderzoek algoritmisch routeren vergunningaanvragen",
  "description": "Haalbaarheidsonderzoek automatische categorisering van vergunningaanvragen via machine learning; bevindingen gedocumenteerd.",
  "hours": 6.0,
  "date": "2026-05-17",
  "status": "approved",
  "billingCategory": "billing-category-wbso",
  "wipSyncStatus": "synced",
  "wipSyncedAt": "2026-05-17T14:20:05Z"
}
```

### 4. Declarabel uur — Stichting GroenNet (in behandeling)

```json
{
  "@self": { "register": "pipelinq", "schema": "timeEntry", "slug": "time-entry-wip-pending-1" },
  "title": "Projectopstartgesprek subsidieportaal GroenNet",
  "description": "Kickoff meeting gehouden; klantvereisten voor het subsidieportaal geïnventariseerd en vastgelegd.",
  "hours": 1.5,
  "date": "2026-05-20",
  "status": "approved",
  "billingCategory": "billing-category-declarabel",
  "wipSyncStatus": "pending",
  "wipSyncedAt": null
}
```

### 5. DBA Opdracht — Zeelands Logistiek BV (mislukt)

```json
{
  "@self": { "register": "pipelinq", "schema": "timeEntry", "slug": "time-entry-wip-failed-1" },
  "title": "ZZP-advies supply chain optimalisatie",
  "description": "Advies inzake koppeling transport-managementsysteem met klantportaal; oplossingsrichting gedefinieerd.",
  "hours": 3.0,
  "date": "2026-05-19",
  "status": "approved",
  "billingCategory": "billing-category-dba",
  "wipSyncStatus": "failed",
  "wipSyncedAt": null
}
```

---

## Files Changed

### New Files

| File | Purpose |
|---|---|
| `lib/Listener/TimeApprovalListener.php` | Event listener for `TimeEntryApprovedEvent`; sets sync status and triggers dispatch |
| `lib/Service/ShillinqWipService.php` | Maps `timeEntry` to CloudEvents WIP payload; dispatches via `WebhookService` |
| `specs/pipelinq-time-to-shillinq-wip/spec.md` | Formal requirements and BDD scenarios |

### Modified Files

| File | Change |
|---|---|
| `lib/AppInfo/Application.php` | Register `TimeApprovalListener` for `TimeEntryApprovedEvent` |
| `lib/Settings/pipelinq_register.json` | Add `wipSyncStatus` and `wipSyncedAt` to `timeEntry` schema; add 5 seed objects |
| Time entry list view (from `time-entry-core`) | Add `wipSyncStatus` badge column |
| Time entry detail view (from `time-entry-core`) | Add Shillinq WIP sidebar section with retry button |
| `lib/Settings/Admin.php` | Add `shillinq_wip_webhook_url` configuration field under "Integraties" |
| `l10n/en.json` | Add 8 new translation keys |
| `l10n/nl.json` | Add Dutch translations for 8 keys |

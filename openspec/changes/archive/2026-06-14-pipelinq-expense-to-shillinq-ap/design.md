# Design: pipelinq-expense-to-shillinq-ap

## Architecture

### Data Layer

#### Extended Schema: `expense` (from `expense-capture-core`)

No new schema is introduced. The `expense` entity (from `expense-capture-core`) gains two additional properties:

| Property | Type | Required | Description |
|---|---|---|---|
| `apSyncStatus` | string | No | Shillinq AP sync state: `pending`, `synced`, or `failed`. Null for entries not yet approved or created before this change. |
| `apSyncedAt` | string | No | ISO 8601 UTC timestamp of the last successful AP sync delivery. |

State transitions:
- On approval event received → `pending`
- On `WebhookService` successful delivery → `synced` + `apSyncedAt = now()`
- On `WebhookService` retry exhaustion → `failed`
- On admin manual retry → back to `pending`, then resolved as above

**Schema.org mapping:** `apSyncStatus` is infrastructure metadata with no schema.org equivalent. Stored as an implementation detail on the object; not exposed in the Dutch API mapping layer.

OpenRegister built-in fields available automatically on `expense` (do NOT redefine): `id`, `uuid`, `uri`, `version`, `createdAt`, `updatedAt`, `owner`, `organization`, `register`, `schema`, `relations`, `files`, `auditTrail`, `notes`, `tasks`, `tags`, `status`, `locked`.

---

### Backend

#### `lib/Listener/ExpenseApprovalListener.php`

Implements `OCP\EventDispatcher\IEventListener`. Registered in `lib/AppInfo/Application.php` for the `ExpenseApprovedEvent` class from `expense-capture-core`.

Responsibilities:
1. Receive `ExpenseApprovedEvent` carrying the approved `expense` UUID and `approvedBy` user
2. Call `ShillinqApService::shouldDispatch()` — returns false if `shillinq_ap_webhook_url` is not configured; no-op if unconfigured
3. Set `apSyncStatus = pending` on the `expense` via `ObjectService::saveObject()`
4. Call `ShillinqApService::dispatchApEvent($expense, $approvedBy, $approvedAt)`
5. On success: update `apSyncStatus = synced`, `apSyncedAt = now()`
6. On `WebhookService` dispatch failure after retries: set `apSyncStatus = failed`; call `NotificationService` to notify admin

The listener MUST be idempotent: if `apSyncStatus` is already `synced`, skip dispatch. This prevents duplicate AP vouchers if the event is emitted more than once.

#### `lib/Service/ShillinqApService.php`

Maps `expense` data to the shillinq AP CloudEvents payload and dispatches via `WebhookService`.

**Method: `dispatchApEvent(array $expense, string $approvedBy, string $approvedAt): bool`**

Constructs payload:

```json
{
  "specversion": "1.0",
  "type": "nl.conduction.pipelinq.expense.approved",
  "source": "/apps/pipelinq/expenses",
  "id": "<expense.uuid>",
  "time": "<approvedAt>",
  "data": {
    "expenseId": "<expense.uuid>",
    "amount": 125.50,
    "categoryId": "<expense.category>",
    "clientId": "<expense.client>",
    "projectId": "<expense.project>",
    "billable": true,
    "approvedBy": "<approvedBy>",
    "approvedAt": "<approvedAt>"
  }
}
```

Dispatches via `WebhookService::dispatchEvent($webhookUrl, $payload)`. Returns `true` on successful HTTP delivery, `false` on error. The `WebhookService` handles retry logic (3 retries, exponential backoff) — `ShillinqApService` receives the final delivery outcome.

Webhook URL is read from `IAppConfig::getValueString('pipelinq', 'shillinq_ap_webhook_url', '')`.

**Method: `shouldDispatch(): bool`**

Returns `true` only if `shillinq_ap_webhook_url` is a non-empty valid URL. Called by `ExpenseApprovalListener` before any dispatch attempt.

#### `lib/Settings/Admin.php` (modified)

Add `shillinq_ap_webhook_url` to the pipelinq admin settings response. The Vue admin settings panel gains a URL input field under an "Integraties" section header. Value is persisted via `IAppConfig`.

---

### Frontend

#### Expense list view (from `expense-capture-core`) — modified

Add an `apSyncStatus` column to the expense list table. Column renders a color-coded badge:

| `apSyncStatus` value | Badge text (Dutch) | Color |
|---|---|---|
| `synced` | `AP gesynchroniseerd` | green (`#28a745`) |
| `pending` | `AP in behandeling` | yellow (`#ffc107`) |
| `failed` | `AP mislukt` | red (`#dc3545`) |
| null | `–` | grey (`#6c757d`) |

Badge is a `<span>` with inline `background-color`. No separate component file needed.

#### Expense detail view (from `expense-capture-core`) — modified

If `apSyncStatus` is not null, add a "Shillinq AP" card below the main expense details. Card contains:

- Status badge (same colors and labels as list view)
- `apSyncedAt` timestamp displayed as a human-readable date (Dutch locale)
- If `apSyncStatus == failed`: a "Opnieuw versturen" (Retry) button that triggers `ShillinqApService::retryDispatch($expenseId)`
- If `apSyncStatus == pending`: an informational message "Verzending in progress, moment geduld a.u.b."

No user action is needed if `apSyncStatus == synced` (informational only).

---

## Seed Data

The `expense` schema gains 5 seed objects demonstrating the three `apSyncStatus` states:

### Seed object 1: `expense-ap-synced-1` (Reimbursable expense, synced)

```json
{
  "@self": {
    "register": "pipelinq",
    "schema": "expense",
    "slug": "expense-ap-synced-1",
    "force": false
  },
  "title": "Hotelaccommodatie Amsterdam",
  "description": "Verblijf Hotel de l'Europa, Amsterdam",
  "amount": 185.50,
  "currency": "EUR",
  "category": "accommodation",
  "client": "<client-uuid-1>",
  "project": null,
  "billable": false,
  "status": "approved",
  "approvedBy": "<user-uuid-1>",
  "approvedAt": "2026-05-15T14:30:00Z",
  "apSyncStatus": "synced",
  "apSyncedAt": "2026-05-15T14:35:00Z"
}
```

### Seed object 2: `expense-ap-synced-2` (Billable expense, synced)

```json
{
  "@self": {
    "register": "pipelinq",
    "schema": "expense",
    "slug": "expense-ap-synced-2",
    "force": false
  },
  "title": "Reiskosten client bezoek",
  "description": "Treinkaartje Amsterdam–Rotterdam, client project",
  "amount": 45.00,
  "currency": "EUR",
  "category": "travel",
  "client": "<client-uuid-2>",
  "project": "<project-uuid-1>",
  "billable": true,
  "status": "approved",
  "approvedBy": "<user-uuid-1>",
  "approvedAt": "2026-05-14T09:00:00Z",
  "apSyncStatus": "synced",
  "apSyncedAt": "2026-05-14T09:05:00Z"
}
```

### Seed object 3: `expense-ap-pending-1` (Pending sync)

```json
{
  "@self": {
    "register": "pipelinq",
    "schema": "expense",
    "slug": "expense-ap-pending-1",
    "force": false
  },
  "title": "Kantoorbenodigdheden",
  "description": "Printpapier en pennen, kantoor Amsterdam",
  "amount": 32.95,
  "currency": "EUR",
  "category": "supplies",
  "client": null,
  "project": null,
  "billable": false,
  "status": "approved",
  "approvedBy": "<user-uuid-2>",
  "approvedAt": "2026-05-20T16:45:00Z",
  "apSyncStatus": "pending",
  "apSyncedAt": null
}
```

### Seed object 4: `expense-ap-failed-1` (Failed sync)

```json
{
  "@self": {
    "register": "pipelinq",
    "schema": "expense",
    "slug": "expense-ap-failed-1",
    "force": false
  },
  "title": "Catering vergadering",
  "description": "Koffie en thee, team meeting 2026-05-10",
  "amount": 78.30,
  "currency": "EUR",
  "category": "meals",
  "client": null,
  "project": null,
  "billable": false,
  "status": "approved",
  "approvedBy": "<user-uuid-1>",
  "approvedAt": "2026-05-10T11:20:00Z",
  "apSyncStatus": "failed",
  "apSyncedAt": null
}
```

### Seed object 5: `expense-ap-synced-3` (Complex billable, synced)

```json
{
  "@self": {
    "register": "pipelinq",
    "schema": "expense",
    "slug": "expense-ap-synced-3",
    "force": false
  },
  "title": "Software licentie (jaarlijks)",
  "description": "Adobe Creative Suite jaarlicentie, project Digitalisering",
  "amount": 595.00,
  "currency": "EUR",
  "category": "software",
  "client": "<client-uuid-2>",
  "project": "<project-uuid-2>",
  "billable": true,
  "status": "approved",
  "approvedBy": "<user-uuid-2>",
  "approvedAt": "2026-05-01T10:00:00Z",
  "apSyncStatus": "synced",
  "apSyncedAt": "2026-05-01T10:05:00Z"
}
```

---

## i18n Keys

Dutch and English translations are added to `lib/Settings/i18n.json`:

```json
{
  "nl": {
    "pipelinq": {
      "admin.shillinq_ap_webhook_url": "Shillinq AP webhook URL",
      "admin.shillinq_ap_webhook_url.help": "Voer de webhook URL in voor de Shillinq AP integratie. Laat leeg om uitgeschakeld te laten.",
      "expense.apSyncStatus.synced": "AP gesynchroniseerd",
      "expense.apSyncStatus.pending": "AP in behandeling",
      "expense.apSyncStatus.failed": "AP mislukt",
      "expense.shillinqAp.retryButton": "Opnieuw versturen",
      "expense.shillinqAp.pending": "Verzending in progress, moment geduld a.u.b.",
      "notification.apSyncFailed": "Shillinq AP sync mislukt voor onkosten-ID {id}. Controlleer de instellingen en probeer opnieuw."
    }
  },
  "en": {
    "pipelinq": {
      "admin.shillinq_ap_webhook_url": "Shillinq AP webhook URL",
      "admin.shillinq_ap_webhook_url.help": "Enter the webhook URL for the Shillinq AP integration. Leave blank to disable.",
      "expense.apSyncStatus.synced": "AP synchronized",
      "expense.apSyncStatus.pending": "AP processing",
      "expense.apSyncStatus.failed": "AP failed",
      "expense.shillinqAp.retryButton": "Retry",
      "expense.shillinqAp.pending": "Sending in progress, please wait.",
      "notification.apSyncFailed": "Shillinq AP sync failed for expense ID {id}. Check your settings and try again."
    }
  }
}
```

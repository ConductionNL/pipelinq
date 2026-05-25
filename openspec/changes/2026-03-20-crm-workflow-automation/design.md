# Design: crm-workflow-automation

## Architecture

### Data Model (OpenRegister Schemas)

Two schemas added to `pipelinq_register.json` (both already defined in ADR-000):

#### automation

| Property | Type | Required | Notes |
|----------|------|----------|-------|
| name | string | Yes | Automation display name |
| trigger | string | Yes | Enum: `lead_created`, `lead_stage_changed`, `lead_assigned`, `contact_created` |
| triggerConditions | object | No | Filter conditions: `{ stage, pipeline, valueThreshold }` |
| actions | array | No | Ordered action objects: `[{ type, params }]` |
| isActive | boolean | No | Default: false. Whether the automation fires on matching events |
| lastRun | string (date-time) | No | ISO timestamp of most recent execution |
| runCount | integer | No | Default: 0. Total execution count |
| webhookUrl | string | No | n8n webhook URL for `webhook` action type |
| n8nWorkflowId | string | No | Reference to n8n workflow ID |

Action types in `actions[].type`: `assign_lead`, `move_stage`, `send_notification`, `add_note`, `webhook`

#### automationLog

| Property | Type | Required | Notes |
|----------|------|----------|-------|
| automation | string (uuid) | Yes | Reference to the automation that executed |
| triggeredAt | string (date-time) | Yes | When the trigger fired |
| triggerEntity | string (uuid) | No | UUID of the entity that triggered the automation |
| actionsExecuted | array | No | `[{ type, params, result, success }]` per action |
| status | string | Yes | Enum: `success`, `failure` |
| error | string | No | Error message if status is `failure` |

### Reuse Analysis

| Capability | Platform provided | Custom needed |
|------------|------------------|---------------|
| Object CRUD | `ObjectService.findObjects/saveObject/deleteObject` | No |
| Event listening | `ObjectEventHandlerService` (extend) | No new class; modify existing |
| Notification dispatch | `NotificationService` | No; called from AutomationService |
| Webhook delivery | `WebhookService` | Not applicable — automations require trigger-conditional logic not in generic WebhookService |
| Frontend CRUD | `createObjectStore` | No custom store |
| List view | `CnIndexPage` + `useListView` | No custom pagination |
| Form dialog | `CnFormDialog` (builder) | Custom builder for action chain ordering |

### Backend

#### AutomationService (`lib/Service/AutomationService.php`)

All persistence via `ObjectService` with 3-arg signatures per ADR-015.

| Method | Signature | Description |
|--------|-----------|-------------|
| `listAutomations` | `(array $params = []): array` | `findObjects($register, 'automation', $params)` |
| `getAutomation` | `(string $id): array` | `findObject($register, 'automation', $id)` |
| `saveAutomation` | `(array $data): array` | `saveObject($register, 'automation', $data)` |
| `deleteAutomation` | `(string $id): void` | `deleteObject($register, 'automation', $id)` |
| `getMatchingAutomations` | `(string $trigger, array $entity): array` | Query by trigger, filter by triggerConditions |
| `executeAutomation` | `(array $automation, array $entity): void` | Dispatch each action in `actions` sequence |
| `logExecution` | `(string $automationId, array $result): void` | `saveObject($register, 'automationLog', $data)` |

Action dispatch in `executeAutomation`:
- `assign_lead` — update `lead.assignee` via `ObjectService::saveObject`
- `move_stage` — update `lead.stage` + `lead.stageOrder`
- `send_notification` — call `NotificationService`
- `add_note` — append to entity's `notes` array
- `webhook` — HTTP POST to `automation.webhookUrl` via `IClientService`

#### AutomationController (`lib/Controller/AutomationController.php`)

Thin controller — validation + response only. All logic in `AutomationService`.

All endpoints require authentication (`@NoAdminRequired` unless noted). Mutation endpoints (`POST`/`PUT`/`DELETE`) enforce `IGroupManager::isAdmin()` check.

| Method | URL | Action | Auth |
|--------|-----|--------|------|
| GET | `/api/automations` | `index` — list automations | `@NoAdminRequired` |
| GET | `/api/automations/{id}` | `show` — get single | `@NoAdminRequired` |
| POST | `/api/automations` | `create` — create automation | Admin |
| PUT | `/api/automations/{id}` | `update` — update automation | Admin |
| DELETE | `/api/automations/{id}` | `destroy` — delete automation | Admin |
| GET | `/api/automations/{id}/history` | `history` — execution log | `@NoAdminRequired` |

#### ObjectEventHandlerService (modified)

In the `handle(Event $event)` method, after existing event logic:

```
$automations = $this->automationService->getMatchingAutomations($triggerType, $entityData);
foreach ($automations as $automation) {
    $this->automationService->executeAutomation($automation, $entityData);
}
```

Trigger type mapping:
- Lead created → `lead_created`
- Lead `stage` property changed → `lead_stage_changed`
- Lead `assignee` property changed → `lead_assigned`
- Contact created → `contact_created`

### Frontend

#### AutomationList.vue (`src/views/automations/AutomationList.vue`)

Uses `CnIndexPage` + `useListView('automation', ...)`.

Columns: Name, Trigger, Status (toggle), Last Run, Run Count, Actions (edit / delete).

Status toggle calls `PUT /api/automations/{id}` to flip `isActive`.

#### AutomationBuilder.vue (`src/views/automations/AutomationBuilder.vue`)

Form-based builder. Sections:
1. **General** — Name (text input)
2. **Trigger** — trigger type dropdown + conditional config fields (stage selector shown only for `lead_stage_changed`; pipeline selector for `lead_created`/`lead_stage_changed`)
3. **Actions** — ordered list of action cards; each card has `type` dropdown + type-specific params; drag-reorder supported
4. **Save / Activate** — Save as inactive, or Save + Activate toggle

Uses `CnFormDialog` wrapper for create. On edit, renders as full page with `CnDetailPage` shell.

#### AutomationHistory.vue (`src/views/automations/AutomationHistory.vue`)

`CnIndexPage` filtered by `automation={id}` querying `automationLog` schema.

Columns: Triggered At, Trigger Entity, Status (badge), Actions Executed (count), Error.

Row click expands inline to show `actionsExecuted` detail.

### Navigation

Add "Automatisering" nav item to `MainMenu.vue` (settings footer section, not primary navigation).

Route: `/automatisering` → `AutomationList.vue`.

From `AutomationList`, edit button navigates to `/automatisering/{id}` → `AutomationBuilder.vue`.
History button navigates to `/automatisering/{id}/history` → `AutomationHistory.vue`.

## Seed Data

### automation (3 examples)

**1. Welkomstmelding bij nieuw lead**
```json
{
  "@self": { "register": "pipelinq", "schema": "automation", "slug": "auto-lead-created-notify" },
  "name": "Welkomstmelding bij nieuw lead",
  "trigger": "lead_created",
  "triggerConditions": {},
  "actions": [
    { "type": "send_notification", "params": { "message": "Nieuw lead aangemaakt: {{lead.title}}", "users": ["admin"] } }
  ],
  "isActive": true,
  "runCount": 0
}
```

**2. Toewijzen bij faseverandering naar Gekwalificeerd**
```json
{
  "@self": { "register": "pipelinq", "schema": "automation", "slug": "auto-stage-qualified-assign" },
  "name": "Toewijzen aan senior accountmanager bij Gekwalificeerd",
  "trigger": "lead_stage_changed",
  "triggerConditions": { "stage": "Gekwalificeerd" },
  "actions": [
    { "type": "assign_lead", "params": { "assignee": "j.bakker" } },
    { "type": "send_notification", "params": { "message": "Lead gekwalificeerd en toegewezen", "users": ["j.bakker"] } }
  ],
  "isActive": true,
  "runCount": 0
}
```

**3. n8n webhook bij nieuw contactpersoon**
```json
{
  "@self": { "register": "pipelinq", "schema": "automation", "slug": "auto-contact-created-webhook" },
  "name": "Synchroniseer nieuw contactpersoon naar Mailchimp via n8n",
  "trigger": "contact_created",
  "triggerConditions": {},
  "actions": [
    { "type": "webhook", "params": {} }
  ],
  "webhookUrl": "https://n8n.example.nl/webhook/contact-sync",
  "n8nWorkflowId": "wf-042",
  "isActive": false,
  "runCount": 0
}
```

### automationLog (3 examples)

**1. Succesvolle uitvoering welkomstmelding**
```json
{
  "@self": { "register": "pipelinq", "schema": "automationLog", "slug": "alog-001" },
  "automation": "auto-lead-created-notify",
  "triggeredAt": "2026-03-10T09:15:00Z",
  "triggerEntity": "lead-gemeente-amsterdam",
  "actionsExecuted": [
    { "type": "send_notification", "success": true, "result": "Notification sent to admin" }
  ],
  "status": "success"
}
```

**2. Succesvolle uitvoering toewijzingsautomatisering**
```json
{
  "@self": { "register": "pipelinq", "schema": "automationLog", "slug": "alog-002" },
  "automation": "auto-stage-qualified-assign",
  "triggeredAt": "2026-03-12T14:32:00Z",
  "triggerEntity": "lead-techcorp-website",
  "actionsExecuted": [
    { "type": "assign_lead", "success": true, "result": "Assigned to j.bakker" },
    { "type": "send_notification", "success": true, "result": "Notification sent to j.bakker" }
  ],
  "status": "success"
}
```

**3. Mislukte webhook uitvoering**
```json
{
  "@self": { "register": "pipelinq", "schema": "automationLog", "slug": "alog-003" },
  "automation": "auto-contact-created-webhook",
  "triggeredAt": "2026-03-14T11:05:00Z",
  "triggerEntity": "contact-petra-jansen",
  "actionsExecuted": [
    { "type": "webhook", "success": false, "result": "HTTP 503 from webhook endpoint" }
  ],
  "status": "failure",
  "error": "Webhook POST to https://n8n.example.nl/webhook/contact-sync returned HTTP 503"
}
```

## Files Changed

### New Files
- `lib/Service/AutomationService.php`
- `lib/Controller/AutomationController.php`
- `src/views/automations/AutomationList.vue`
- `src/views/automations/AutomationBuilder.vue`
- `src/views/automations/AutomationHistory.vue`

### Modified Files
- `lib/Settings/pipelinq_register.json` — Add `automation` and `automationLog` schemas; add seed data
- `lib/Service/ObjectEventHandlerService.php` — Add automation trigger dispatch after event handling
- `appinfo/routes.php` — Add 6 automation API routes
- `src/store/store.js` — Register `automation` and `automation-log` object types
- `src/router/index.js` — Add automatisering routes
- `src/navigation/MainMenu.vue` — Add Automatisering nav item (settings section)

# Design: crm-workflow-automation

## Architecture Overview

The automation system builds on the existing Pipelinq event infrastructure and OpenRegister's `WebhookService`. The `automation` and `automationLog` schemas already exist in the data model (ADR-000). This change wires them into a fully functional automation pipeline with a REST API, DMN decision service integration, runtime variable query endpoints, and a marketing automation sequencer.

All data is stored in OpenRegister objects. No custom database tables are introduced (ADR-001).

---

## Data Model

### Schema: `automation` (existing — no changes required)

As defined in ADR-000:

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Automation name |
| trigger | string | Yes | CRM event that activates this automation |
| triggerConditions | object | No | Filter conditions (stage, pipeline, value threshold, segment) |
| actions | array | No | Ordered list of actions to execute |
| isActive | boolean | No | Whether the automation is enabled |
| lastRun | string | No | ISO timestamp of last execution |
| runCount | integer | No | Total execution count |
| webhookUrl | string | No | n8n or external webhook URL |
| n8nWorkflowId | string | No | Reference to n8n workflow ID |

### Schema: `automationLog` (existing — no changes required)

As defined in ADR-000:

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| automation | string | Yes | UUID reference to the automation that executed |
| triggeredAt | string | Yes | When the automation was triggered |
| triggerEntity | string | No | UUID of the entity that triggered the automation |
| actionsExecuted | array | No | List of actions executed and their results |
| status | string | Yes | Execution outcome: success / failure |
| error | string | No | Error message if execution failed |

**Note:** Both schemas are already registered in the pipelinq register. This change implements the service logic and API layer on top of them.

---

## Reuse Analysis

This change deliberately reuses OpenRegister platform capabilities rather than reimplementing them:

| Capability | OpenRegister Service Used |
|------------|--------------------------|
| Webhook creation, testing, retry | `WebhookService` — all webhook CRUD and dispatch |
| CloudEvents format | `WebhookService` — automatic |
| Automation object CRUD | `ObjectService::saveObject()`, `findObjects()`, `deleteObject()` |
| Automation log persistence | `ObjectService::saveObject()` with automationLog schema |
| Event listening on entity save | `ObjectEventListener` — existing dispatcher hook |
| Notification dispatch | `NotificationService` — existing service |
| Activity feed events | `ActivityService` — existing service |
| Frontend list + CRUD | `CnIndexPage` + `createObjectStore` + `useListView` |
| Frontend detail view | `CnDetailPage` + `useDetailView` |
| Schema-driven forms | `CnFormDialog` auto-generated from automation schema |
| Workflow orchestration | `WorkflowEngineRegistry` — for DMN evaluation |

**New custom logic** (app-specific business rules only):
- `AutomationService`: trigger matching, condition evaluation, action execution sequence
- `DmnDecisionService`: wrap WorkflowEngineRegistry for CRM entity evaluation
- `AutomationVariableService`: query runtime variable state from automationLog objects
- `MarketingSequenceService`: segment evaluation + sequential action scheduling

---

## Backend Architecture

### Services

#### `AutomationService` (`lib/Service/AutomationService.php`)

| Method | Description |
|--------|-------------|
| `getMatchingAutomations(string $trigger, array $entity): array` | Find active automations whose trigger and conditions match the given CRM event |
| `executeAutomation(array $automation, array $entityData): array` | Execute the automation's action list in sequence; return result summary |
| `logExecution(string $automationId, string $entityId, array $result): void` | Persist an automationLog object with execution result |
| `evaluateTriggerConditions(array $conditions, array $entity): bool` | Check if entity data satisfies all trigger conditions |
| `dispatchAction(string $actionType, array $actionConfig, array $entityData): array` | Execute a single action (assign, notify, webhook, etc.) |

#### `DmnDecisionService` (`lib/Service/DmnDecisionService.php`)

| Method | Description |
|--------|-------------|
| `evaluateDecision(string $decisionTableId, array $inputData): array` | Execute a DMN decision table via WorkflowEngineRegistry; return output values |
| `applyDecisionToEntity(string $entityId, string $schema, array $decisionOutput): void` | Write decision output properties back to the entity via ObjectService |

#### `AutomationVariableService` (`lib/Service/AutomationVariableService.php`)

| Method | Description |
|--------|-------------|
| `getActiveAutomations(): array` | Return automations with runCount > 0 and lastRun within the query window |
| `getRuntimeState(string $automationId): array` | Return last execution context from automationLog for a given automation |
| `getVariableBindings(string $automationId): array` | Extract actionsExecuted variables from the most recent automationLog |

#### `MarketingSequenceService` (`lib/Service/MarketingSequenceService.php`)

| Method | Description |
|--------|-------------|
| `evaluateSegment(array $segmentConditions, array $entity): bool` | Check if a contact/lead matches segment filter conditions |
| `enqueueSequence(string $automationId, string $entityId): void` | Schedule the automation's action sequence for the matched entity |
| `executeNextStep(string $automationId, string $entityId, int $stepIndex): void` | Execute one step of a marketing sequence; schedule next step if applicable |

### Controllers

#### `AutomationController` (`lib/Controller/AutomationController.php`)

| Method | HTTP | URL | Description |
|--------|------|-----|-------------|
| `index()` | GET | `/api/automations` | List all automations |
| `show(string $id)` | GET | `/api/automations/{id}` | Get automation detail |
| `create()` | POST | `/api/automations` | Create automation |
| `update(string $id)` | PUT | `/api/automations/{id}` | Update automation |
| `destroy(string $id)` | DELETE | `/api/automations/{id}` | Delete automation |
| `history(string $id)` | GET | `/api/automations/{id}/history` | List execution logs |
| `activate(string $id)` | PUT | `/api/automations/{id}/activate` | Toggle isActive |

#### `WebhookController` (`lib/Controller/WebhookController.php`)

Thin wrapper over `WebhookService`. Delegates all logic.

| Method | HTTP | URL | Description |
|--------|------|-----|-------------|
| `index()` | GET | `/api/webhooks` | List webhook subscriptions |
| `create()` | POST | `/api/webhooks` | Create webhook subscription |
| `show(string $id)` | GET | `/api/webhooks/{id}` | Get webhook detail |
| `update(string $id)` | PUT | `/api/webhooks/{id}` | Update webhook |
| `destroy(string $id)` | DELETE | `/api/webhooks/{id}` | Delete webhook |
| `test(string $id)` | POST | `/api/webhooks/{id}/test` | Fire test event to webhook URL |

#### `DmnController` (`lib/Controller/DmnController.php`)

| Method | HTTP | URL | Description |
|--------|------|-----|-------------|
| `evaluate()` | POST | `/api/dmn/evaluate` | Execute decision table against input data |
| `listTables()` | GET | `/api/dmn/tables` | List available DMN decision tables |

#### `AutomationVariableController` (`lib/Controller/AutomationVariableController.php`)

| Method | HTTP | URL | Description |
|--------|------|-----|-------------|
| `index()` | GET | `/api/automations/runtime` | List active automations with state |
| `variables(string $id)` | GET | `/api/automations/{id}/variables` | Get variable bindings for an automation |

---

## Frontend Architecture

### Store

Register automation and automationLog object types in `src/store/store.js` using `createObjectStore`:
- `automation` (schema slug: `automation`)
- `automation-log` (schema slug: `automationLog`)

### Views

#### `AutomationList.vue` (`src/views/automations/AutomationList.vue`)

Uses `CnIndexPage` + `useListView`. Displays:
- Name, trigger type, status (active/inactive badge), last run timestamp, run count
- Row actions: Edit, Delete, View History
- Header action: "New Automation" button → navigate to `AutomationDetail` with id=new
- Status toggle in row actions calls `PUT /api/automations/{id}/activate`

#### `AutomationDetail.vue` (`src/views/automations/AutomationDetail.vue`)

Two modes (view / edit). Uses `CnDetailPage` with:
- **Automation Information** section: name, trigger, isActive status, lastRun, runCount
- **Trigger Conditions** section: JSON viewer (`CnJsonViewer`) for triggerConditions object
- **Actions** section: ordered list of configured actions with type and parameters
- **Webhook** section (if webhookUrl set): URL, n8nWorkflowId, test button
- **Execution History** section: linked automationLog records via `fetchUsed` reverse lookup

#### `AutomationBuilder.vue` (`src/views/automations/AutomationBuilder.vue`)

Form for create/edit using `CnFormDialog` schema-driven form:
- Name field
- Trigger dropdown: lead_created, lead_stage_changed, lead_assigned, contact_created, request_created, request_status_changed, marketing_segment_match
- Trigger conditions: key-value editor for stage/pipeline/value/tag filters
- Actions: ordered list of action cards (add/remove/reorder); each card has type + config
- Webhook URL field (optional)
- Save + Activate / Save as Draft buttons

#### `WebhookList.vue` (`src/views/webhooks/WebhookList.vue`)

Uses `CnIndexPage` + `useListView`. Displays:
- URL (truncated), subscribed events, status (active badge), last fired timestamp
- Row actions: Test, Edit, Delete

#### `AutomationHistory.vue` (`src/views/automations/AutomationHistory.vue`)

Execution history table for a single automation using `CnDataTable`:
- triggeredAt, triggerEntity (linked to entity), status badge, actionsExecuted summary, error (if any)

### Navigation

Add "Automatiseringen" section to `MainMenu.vue` with items:
- Automations → `/automations`
- Webhooks → `/webhooks`

### Router

Add named routes:
- `{ path: '/automations', name: 'AutomationList', component: AutomationList }`
- `{ path: '/automations/:id', name: 'AutomationDetail', component: AutomationDetail, props: ... }`
- `{ path: '/webhooks', name: 'WebhookList', component: WebhookList }`

---

## Event Integration

`ObjectEventHandlerService.php` (existing file) is modified to:
1. After any entity save, call `AutomationService::getMatchingAutomations($trigger, $entityData)`
2. For each matching automation, call `AutomationService::executeAutomation($automation, $entityData)` in a background job queue entry
3. Log results via `AutomationService::logExecution()`

Background execution uses OpenRegister's job queue to avoid blocking the save response.

---

## Seed Data

Seed objects for `automation` schema (3 examples, Dutch CRM context):

```json
[
  {
    "@self": {
      "register": "pipelinq",
      "schema": "automation",
      "slug": "automation-lead-hoog-waarde"
    },
    "name": "Nieuwe lead met hoge waarde toewijzen",
    "trigger": "lead_created",
    "triggerConditions": { "value": { "gte": 10000 } },
    "actions": [
      { "type": "assign_lead", "assignee": "senior-verkoop" },
      { "type": "send_notification", "message": "Nieuwe lead boven €10.000 ontvangen" }
    ],
    "isActive": true,
    "lastRun": "2026-04-10T09:15:00Z",
    "runCount": 12,
    "webhookUrl": null,
    "n8nWorkflowId": null
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "automation",
      "slug": "automation-lead-fase-offerte"
    },
    "name": "Lead naar Offerte fase — webhook n8n",
    "trigger": "lead_stage_changed",
    "triggerConditions": { "stage": "Offerte" },
    "actions": [
      { "type": "fire_webhook", "url": "https://n8n.intern.gemeente.nl/webhook/offerte-trigger" },
      { "type": "add_note", "text": "Offerte fase bereikt — extern systeem geïnformeerd" }
    ],
    "isActive": true,
    "lastRun": "2026-04-14T13:42:00Z",
    "runCount": 7,
    "webhookUrl": "https://n8n.intern.gemeente.nl/webhook/offerte-trigger",
    "n8nWorkflowId": "wf-offerte-pipeline-001"
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "automation",
      "slug": "automation-contact-nieuw-welkom"
    },
    "name": "Nieuw contact — welkomsttaak aanmaken",
    "trigger": "contact_created",
    "triggerConditions": {},
    "actions": [
      { "type": "send_notification", "message": "Nieuw contact aangemaakt — maak kennis!" },
      { "type": "update_tag", "tag": "nieuw-contact" }
    ],
    "isActive": false,
    "lastRun": null,
    "runCount": 0,
    "webhookUrl": null,
    "n8nWorkflowId": null
  }
]
```

Seed objects for `automationLog` schema (3 examples):

```json
[
  {
    "@self": {
      "register": "pipelinq",
      "schema": "automationLog",
      "slug": "log-lead-hoog-waarde-001"
    },
    "automation": "automation-lead-hoog-waarde",
    "triggeredAt": "2026-04-10T09:15:00Z",
    "triggerEntity": "lead-renovatie-stadhuis",
    "actionsExecuted": [
      { "type": "assign_lead", "result": "success", "assignee": "senior-verkoop" },
      { "type": "send_notification", "result": "success" }
    ],
    "status": "success",
    "error": null
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "automationLog",
      "slug": "log-offerte-fase-001"
    },
    "automation": "automation-lead-fase-offerte",
    "triggeredAt": "2026-04-14T13:42:00Z",
    "triggerEntity": "lead-ict-infra-2026",
    "actionsExecuted": [
      { "type": "fire_webhook", "result": "success", "httpStatus": 200 },
      { "type": "add_note", "result": "success" }
    ],
    "status": "success",
    "error": null
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "automationLog",
      "slug": "log-offerte-fase-002"
    },
    "automation": "automation-lead-fase-offerte",
    "triggeredAt": "2026-04-12T16:05:00Z",
    "triggerEntity": "lead-zorg-inkoop-q2",
    "actionsExecuted": [
      { "type": "fire_webhook", "result": "failure", "httpStatus": 503, "error": "Upstream unavailable" }
    ],
    "status": "failure",
    "error": "Webhook endpoint returned HTTP 503"
  }
]
```

---

## Files Changed

### New Files

- `lib/Service/AutomationService.php`
- `lib/Service/DmnDecisionService.php`
- `lib/Service/AutomationVariableService.php`
- `lib/Service/MarketingSequenceService.php`
- `lib/Controller/AutomationController.php`
- `lib/Controller/WebhookController.php`
- `lib/Controller/DmnController.php`
- `lib/Controller/AutomationVariableController.php`
- `src/views/automations/AutomationList.vue`
- `src/views/automations/AutomationDetail.vue`
- `src/views/automations/AutomationBuilder.vue`
- `src/views/automations/AutomationHistory.vue`
- `src/views/webhooks/WebhookList.vue`

### Modified Files

- `lib/Service/ObjectEventHandlerService.php` — add automation trigger dispatch
- `appinfo/routes.php` — add automation, webhook, DMN, runtime variable routes
- `src/store/store.js` — register `automation` and `automation-log` object types
- `src/router/index.js` — add automation and webhook routes
- `src/navigation/MainMenu.vue` — add Automatiseringen nav section

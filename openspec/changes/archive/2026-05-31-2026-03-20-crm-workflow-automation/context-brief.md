# Proposal: crm-workflow-automation

## Problem

Pipelinq has a working event system (ObjectEventListener, NotificationService, ActivityService) but no way for users to create custom automations from the UI. CRM events like lead stage changes, new lead creation, or stale leads cannot trigger automated workflows. The n8n MCP integration exists at the infrastructure level but is not exposed in the Pipelinq UI.

## Solution

Implement a CRM automation system with:
1. **AutomationService** for managing automation configurations (stored as OpenRegister objects)
2. **AutomationController** with CRUD endpoints for automation management
3. **Webhook bridge** in ObjectEventHandlerService to fire matching automations on CRM events
4. **Automation builder UI** in Settings > Automatisering for creating trigger-action automations
5. **Automation list** showing all automations with status, last run, and execution history

## Scope

- Automation CRUD (create, list, edit, activate/deactivate, delete)
- CRM triggers: lead_created, lead_stage_changed, lead_assigned, contact_created
- CRM actions: assign_lead, move_stage, send_notification, add_note, webhook
- Webhook firing from ObjectEventHandlerService to n8n
- Automation execution logging
- Settings navigation entry for Automatisering

## Out of scope

- Visual n8n workflow editor embedding (V2)
- Scheduled triggers / cron-based automations (V1)
- Dry-run / preview capability (V1)
- Email template actions (V1)
- Quote status triggers (V1)



## Design

# Design: crm-workflow-automation

## Architecture

### Data Model

New schema `automation` in the pipelinq register:

| Property | Type | Description |
|----------|------|-------------|
| name | string | Automation name |
| trigger | string | Trigger type (lead_created, lead_stage_changed, lead_assigned, contact_created) |
| triggerConditions | object | Filter conditions (stage, pipeline, value threshold) |
| actions | array | Ordered list of actions to execute |
| isActive | boolean | Whether the automation is enabled |
| lastRun | string | ISO timestamp of last execution |
| runCount | integer | Total execution count |
| webhookUrl | string | n8n webhook URL (if created) |
| n8nWorkflowId | string | Reference to n8n workflow ID |

New schema `automationLog` for execution history:

| Property | Type | Description |
|----------|------|-------------|
| automation | string (uuid) | Reference to automation |
| triggeredAt | string (datetime) | When the trigger fired |
| triggerEntity | string (uuid) | Entity that triggered |
| actionsExecuted | array | List of actions and their results |
| status | string | success/failure |
| error | string | Error message if failed |

### Backend

#### AutomationService (`lib/Service/AutomationService.php`)

- **listAutomations()**: List all automations from OpenRegister
- **getAutomation(string $id)**: Get single automation
- **saveAutomation(array $data)**: Create or update automation
- **deleteAutomation(string $id)**: Delete automation
- **executeAutomation(array $automation, array $entityData)**: Execute actions
- **getMatchingAutomations(string $trigger, array $entity)**: Find automations matching trigger and conditions
- **logExecution(string $automationId, array $result)**: Write execution log

#### AutomationController (`lib/Controller/AutomationController.php`)

| Method | URL | Action |
|--------|-----|--------|
| GET | `/api/automations` | List all automations |
| GET | `/api/automations/{id}` | Get automation detail |
| POST | `/api/automations` | Create automation |
| PUT | `/api/automations/{id}` | Update automation |
| DELETE | `/api/automations/{id}` | Delete automation |
| GET | `/api/automations/{id}/history` | Get execution history |

### Frontend

#### AutomationList.vue (`src/views/automations/AutomationList.vue`)

List all automations: name, trigger summary, status toggle, last run, run count, edit/delete actions.

#### AutomationBuilder.vue (`src/views/automations/AutomationBuilder.vue`)

Form-based builder: name, trigger dropdown, condition config, action chain (ordered cards), save/activate.

#### AutomationHistory.vue (`src/views/automations/AutomationHistory.vue`)

Execution history table for a single automation.

## Files Changed

- `lib/Settings/pipelinq_register.json` (modified -- add automation and automationLog schemas)
- `lib/Service/AutomationService.php` (new)
- `lib/Controller/AutomationController.php` (new)
- `lib/Service/ObjectEventHandlerService.php` (modified -- add automation trigger check)
- `appinfo/routes.php` (modified -- add automation routes)
- `src/store/store.js` (modified -- register automation object type)
- `src/router/index.js` (modified -- add automation routes)
- `src/navigation/MainMenu.vue` (modified -- add Automations settings nav item)
- `src/views/automations/AutomationList.vue` (new)
- `src/views/automations/AutomationBuilder.vue` (new)
- `src/views/automations/AutomationHistory.vue` (new)



## Tasks

# Tasks: crm-workflow-automation

## 1. Schema Definition

- [ ] 1.1 Add `automation` and `automationLog` schemas to `lib/Settings/pipelinq_register.json`.
- [ ] 1.2 Register both schemas in the pipelinq register schemas array.

## 2. Backend Service

- [ ] 2.1 Create `lib/Service/AutomationService.php` with CRUD, matching, execution, and logging methods.

## 3. Backend Controller and Routes

- [ ] 3.1 Create `lib/Controller/AutomationController.php` with index, show, create, update, destroy, history actions.
- [ ] 3.2 Add 6 automation routes to `appinfo/routes.php`.

## 4. Event Integration

- [ ] 4.1 Modify `ObjectEventHandlerService.php` to fire matching automations on entity events.

## 5. Frontend Store

- [ ] 5.1 Register `automation` and `automationLog` object types in `src/store/store.js`.

## 6. Frontend Views

- [ ] 6.1 Create `src/views/automations/AutomationList.vue`.
- [ ] 6.2 Create `src/views/automations/AutomationBuilder.vue`.
- [ ] 6.3 Create `src/views/automations/AutomationHistory.vue`.

## 7. Navigation and Routing

- [ ] 7.1 Add automation routes to `src/router/index.js`.
- [ ] 7.2 Add Automations settings nav item to `src/navigation/MainMenu.vue`.
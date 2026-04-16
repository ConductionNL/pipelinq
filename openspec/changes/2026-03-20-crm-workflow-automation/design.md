# Design: crm-workflow-automation

**Status:** pr-created (https://github.com/ConductionNL/pipelinq/pull/220)

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

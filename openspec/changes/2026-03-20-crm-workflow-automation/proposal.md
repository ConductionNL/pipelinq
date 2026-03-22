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

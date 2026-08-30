> SUPERSEDED 2026-05-31: feature implemented; archived twin archive/2026-03-21-crm-workflow-automation. Archived as already-delivered. (Bespoke automation engine subsequently migrated to the OpenRegister flow leaf — see migrate-automation-to-flow-leaf.)

# Proposal: crm-workflow-automation

## Problem

Pipelinq has a working event system (ObjectEventListener, NotificationService, ActivityService) but no way for users to create custom automations from the UI. CRM events like lead stage changes, new lead creation, or stale leads cannot trigger automated workflows. The n8n MCP integration exists at the infrastructure level but is not exposed in the Pipelinq UI.

Agents are forced to manually check for stale leads, manually reassign items, and manually notify team members — repetitive work that should be automated. Without workflow automation, Pipelinq lags behind competing CRMs (HubSpot, Pipedrive, Salesforce) that all offer no-code trigger-action builders as a standard feature.

## Solution

Implement a CRM automation system with:
1. **AutomationService** for managing automation configurations (stored as OpenRegister objects)
2. **AutomationController** with CRUD endpoints for automation management
3. **Webhook bridge** in ObjectEventHandlerService to fire matching automations on CRM events
4. **Automation builder UI** in Settings > Automatisering for creating trigger-action automations
5. **Automation list** showing all automations with status, last run, and execution history

### Approach

- Add `automation` and `automationLog` schemas to `pipelinq_register.json` — per ADR-000 these entities are already defined in the data model
- Create `AutomationService` for CRUD, matching, execution, and logging via OpenRegister `ObjectService`
- Create `AutomationController` with 6 REST endpoints (`/api/automations`)
- Hook into `ObjectEventHandlerService` — when a CRM entity event fires, query matching automations and execute in sequence
- Frontend: `AutomationList.vue` (index page), `AutomationBuilder.vue` (form builder), `AutomationHistory.vue` (execution log)
- Settings navigation entry for Automatisering — exposed as a navigation section, not a modal settings page

## Scope

- Automation CRUD (create, list, edit, activate/deactivate, delete)
- CRM triggers: `lead_created`, `lead_stage_changed`, `lead_assigned`, `contact_created`
- CRM actions: `assign_lead`, `move_stage`, `send_notification`, `add_note`, `webhook`
- Webhook firing from ObjectEventHandlerService to n8n
- Automation execution logging (automationLog schema)
- Settings navigation entry for Automatisering

## Out of scope

- Visual n8n workflow editor embedding (V2)
- Scheduled triggers / cron-based automations (V1)
- Dry-run / preview capability (V1)
- Email template actions (V1)
- Quote status triggers (V1)

## ADR-011 Reuse Check

Before building:
- **Event dispatch**: Pipelinq already uses `ObjectEventHandlerService` and `NotificationService` — the automation webhook bridge extends `ObjectEventHandlerService`, not a new dispatcher
- **Webhook delivery**: OpenRegister `WebhookService` provides generic webhook delivery; however, automation webhooks require conditional trigger matching and sequenced action execution that the generic WebhookService does not support — custom `AutomationService` is justified
- **CRUD**: All automation object persistence goes via `ObjectService` (`findObjects`, `saveObject`, `deleteObject`) — no custom tables or mappers
- **Frontend store**: Uses `createObjectStore` — no hand-rolled Pinia stores

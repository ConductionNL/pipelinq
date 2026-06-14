# Proposal: crm-workflow-automation

## Problem

Pipelinq CRM has an event infrastructure (ObjectEventListener, NotificationService, ActivityService) but provides no user-facing automation layer. CRM users cannot create webhook subscriptions to external systems, cannot execute business rules via DMN decision services, cannot query runtime automation state, and cannot build targeted marketing campaigns triggered by CRM events.

Key pain points:
- Sales teams cannot push CRM events (lead stage changes, new leads) to external systems like n8n, Zapier, or custom endpoints without developer help
- Business analysts cannot apply DMN-based decision tables to determine lead routing, SLA tiers, or eligibility rules at runtime
- Administrators cannot inspect which automations are running or query their runtime variable state
- Marketing teams cannot segment contacts and trigger automated outreach sequences based on CRM behavior

## Proposed Change

Implement a first-class CRM workflow automation system with four capability areas:

### 1. Webhooks API (demand: 602)
First-class webhooks management: create, list, activate/deactivate, test, and delete webhook subscriptions from the UI and via REST API. Webhooks fire on CRM entity events (lead_created, lead_stage_changed, contact_created, etc.) using CloudEvents format. Leverages OpenRegister's existing `WebhookService`.

### 2. DMN Decision Service Integration (demand: 270)
Expose DMN REST API endpoints that allow executing decision service tables against CRM entity data. Used for automated lead scoring, SLA tier assignment, routing rules, and eligibility evaluation. Decision tables are stored and evaluated server-side; results are applied as automation actions.

### 3. Runtime Variable Query API (demand: 77)
REST endpoints to query the runtime state of automation variables — which automations are running, their last trigger data, execution context, and variable bindings. Enables external tools (n8n, dashboards) to inspect automation state without direct database access.

### 4. Marketing Automation (demand: 5–1)
Trigger-based marketing automation sequences: when a contact or lead matches a segment condition (tag, pipeline stage, source, industry), execute a sequence of marketing actions (send email, update tag, enqueue follow-up task, fire webhook). Sequences are defined in the Automation builder UI.

## Scope

- Automation CRUD via UI and REST API (create, list, edit, activate/deactivate, delete)
- Webhook subscription management: create, list, test, retry, delete
- CRM event triggers: lead_created, lead_stage_changed, lead_assigned, contact_created, request_created, request_status_changed
- CRM automation actions: assign_lead, move_stage, send_notification, add_note, fire_webhook, update_tag
- DMN decision service endpoint: evaluate decision table against entity data
- Runtime variable query endpoint: list active automations and their execution state
- Marketing automation sequences: segment conditions + action chains
- Automation execution logging via `automationLog` schema
- Settings navigation entry for Automatiseringen

## Out of Scope

- Visual DMN table editor in UI (V2 — use external tooling)
- Scheduled / cron-based automations (V2)
- A/B testing in marketing sequences (V2)
- Email template designer (V2)
- Embedded n8n workflow editor (V2)
- Dry-run / preview execution (V2)
- Quote or invoice status triggers (V2)

---
status: draft
---

# crm-workflow-automation Specification

## Purpose
Expose n8n workflow automation capabilities within the Pipelinq UI. Visual workflow builder for CRM automation: trigger-action workflows, conditional branching, and scheduled actions. Bridges the gap between n8n's powerful backend automation and Pipelinq's user-facing CRM interface.

## Context
Built-in automation engines are a standard expectation in modern CRM platforms. Our architecture uses n8n as the workflow engine (via MCP), which is more powerful than typical built-in CRM automation. The gap is in surfacing these automations in the Pipelinq UI so that CRM users can create and manage automations without directly accessing n8n. This spec defines the bridge between Pipelinq's CRM events and n8n workflows.

**Relation to existing specs:** The `workflow-engine-abstraction` spec in OpenRegister covers the general n8n integration layer. This spec focuses on CRM-specific automation patterns and UI.

**Competitive landscape:** EspoCRM provides a two-tier automation system (simple workflows + full BPMN 2.0 via Advanced Pack at $395/year). Krayin offers event-driven workflows with condition builders and 7 action types. Twenty CRM has a built-in trigger-action engine with 7 trigger types and 12 action types, including branching, iteration, and JavaScript execution. Our n8n-based approach is architecturally more powerful than all competitors but requires a UX bridge to match their no-code accessibility.

**Tender relevance:** Workflow/procesautomatisering appears in 38% of government tenders (26/69). The combination of automation with klantinteractie requirements (65% of tenders) makes CRM-specific automation a high-value differentiator.

## ADDED Requirements

---

### Requirement: CRM Automation Triggers
The system MUST expose CRM events as automation triggers selectable in the Pipelinq UI.

**Feature tier**: MVP

#### Scenario: Available CRM triggers for leads
- GIVEN the automation builder in Pipelinq
- THEN the following lead triggers MUST be available:
  - **Lead created** -- fires when a new lead is created
  - **Lead stage changed** -- fires when a lead moves to a specific stage
  - **Lead assigned** -- fires when a lead is (re)assigned to a user
  - **Lead value changed** -- fires when the monetary value of a lead changes
  - **Lead stale** -- fires when a lead exceeds the stale threshold (configurable days without activity)
  - **Lead won** -- fires when a lead moves to the "Won" stage
  - **Lead lost** -- fires when a lead moves to the "Lost" stage

#### Scenario: Available CRM triggers for requests
- GIVEN the automation builder in Pipelinq
- THEN the following request triggers MUST be available:
  - **Request created** -- fires when a new request is created
  - **Request status changed** -- fires when a request moves to a specific status
  - **Request assigned** -- fires when a request is (re)assigned to a user

#### Scenario: Available CRM triggers for contacts
- GIVEN the automation builder in Pipelinq
- THEN the following contact triggers MUST be available:
  - **Contact created** -- fires when a new contact is created
  - **Contact updated** -- fires when a contact's details are modified

#### Scenario: Scheduled triggers
- GIVEN the automation builder in Pipelinq
- THEN the following scheduled triggers MUST be available:
  - **Daily schedule** -- fires at a configured time each day
  - **Weekly schedule** -- fires on a configured day and time each week
  - **Custom cron** -- fires on a custom cron expression

#### Scenario: Trigger integration with existing event system
- GIVEN the existing ObjectEventListener that listens to OpenRegister's ObjectCreatedEvent and ObjectUpdatedEvent
- AND the ObjectEventHandlerService that resolves entity types via SchemaMapService
- AND the ObjectUpdateDiffService that detects field changes (assignee, stage, status)
- WHEN a CRM entity is created or updated
- THEN the existing event detection pipeline MUST be extended to also fire n8n webhooks
- AND the webhook MUST fire after the Activity and Notification dispatchers have completed

---

### Requirement: Trigger Conditions
The system MUST allow users to add conditions to triggers so automations only fire for specific scenarios.

**Feature tier**: MVP

#### Scenario: Configure trigger with stage filter
- GIVEN a trigger "Lead stage changed"
- WHEN the user configures the trigger
- THEN they MUST be able to add conditions:
  - "Only when stage changes to: [selectable stage]"
  - "Only for pipeline: [selectable pipeline]"
  - "Only when previous stage was: [selectable stage]"
- AND multiple conditions MUST be combinable with AND logic

#### Scenario: Configure trigger with value filter
- GIVEN a trigger "Lead created" or "Lead value changed"
- WHEN the user configures the trigger
- THEN they MUST be able to add value conditions:
  - "Only when value > EUR [amount]"
  - "Only when value < EUR [amount]"
  - "Only when value between EUR [min] and EUR [max]"

#### Scenario: Configure trigger with assignee filter
- GIVEN any lead or request trigger
- WHEN the user configures the trigger
- THEN they MUST be able to add assignee conditions:
  - "Only when assigned to: [selectable user]"
  - "Only when assigned to group: [selectable group]"
  - "Only when unassigned"

#### Scenario: Configure trigger with source filter
- GIVEN a trigger "Lead created"
- WHEN the user configures the trigger
- THEN they MUST be able to filter by lead source:
  - "Only for source: [selectable source from SystemTag lead sources]"
- AND the available sources MUST match the lead sources configured in Settings

#### Scenario: Condition evaluation uses diff data
- GIVEN a trigger "Lead stage changed" with condition "Only when stage changes to Qualified"
- WHEN a lead's stage changes from "New" to "In Progress"
- THEN the automation MUST NOT fire
- WHEN a lead's stage changes from "In Progress" to "Qualified"
- THEN the automation MUST fire
- AND the condition evaluation MUST use the ObjectUpdateDiffService's old/new comparison

---

### Requirement: CRM Automation Actions
The system MUST expose CRM actions that automations can execute when triggered.

**Feature tier**: MVP

#### Scenario: Available lead management actions
- GIVEN the automation builder
- THEN the following lead actions MUST be available:
  - **Assign lead** -- set assignedTo to a specific user or round-robin
  - **Move lead to stage** -- change the lead's pipeline stage
  - **Update field** -- set a field value on the lead (title, value, probability, priority, source, expectedCloseDate)

#### Scenario: Available communication actions
- GIVEN the automation builder
- THEN the following communication actions MUST be available:
  - **Send notification** -- send a Nextcloud notification to a specific user, the assignee, or the lead creator
  - **Send email** -- send an email using a configurable template via n8n's email nodes
  - **Add note** -- add a note to the entity's timeline via the existing NotesService

#### Scenario: Available task and workflow actions
- GIVEN the automation builder
- THEN the following task actions MUST be available:
  - **Create task** -- create a Nextcloud task linked to the lead/request
  - **Webhook** -- call an external URL with entity data (configurable URL, method, headers)
  - **Trigger another automation** -- chain automations by triggering another automation by name

#### Scenario: Round-robin lead assignment
- GIVEN an automation with trigger "Lead created" and action "Assign lead (round-robin)"
- AND configured users: jan, maria, pieter
- WHEN 3 new leads are created
- THEN the first lead MUST be assigned to jan
- AND the second to maria
- AND the third to pieter
- AND the fourth cycles back to jan
- AND the round-robin state MUST persist across server restarts (stored in IAppConfig)

#### Scenario: Action configures email template with placeholders
- GIVEN an automation action "Send email"
- WHEN the user configures the email action
- THEN they MUST be able to select or write an email template
- AND the template MUST support placeholders: `{lead.title}`, `{lead.value}`, `{lead.stage}`, `{lead.assignee}`, `{contact.name}`, `{contact.email}`
- AND placeholders MUST be resolved from the trigger's entity data at execution time

---

### Requirement: Multi-Step Action Chains
Automations MUST support executing multiple actions in sequence when a trigger fires.

**Feature tier**: MVP

#### Scenario: Automation with sequential action chain
- GIVEN an automation for trigger "Lead moved to Won"
- WHEN the user adds actions:
  1. Send notification to the lead's assignee: "Lead gewonnen!"
  2. Send email to client: "Bedankt voor uw vertrouwen" template
  3. Create task: "Contract opstellen" assigned to the lead's owner
- THEN all three actions MUST execute in sequence when the trigger fires
- AND if an action fails, subsequent actions MUST still execute (fail-forward)
- AND each action's result (success/failure) MUST be logged individually

#### Scenario: Conditional branching in action chain
- GIVEN an automation for trigger "Lead created"
- WHEN the user configures the action chain
- THEN they MUST be able to add a condition between actions:
  - "If lead value > EUR 50,000: assign to senior-team AND send priority notification"
  - "Else: assign via round-robin AND send standard notification"
- AND the branching MUST be configured via a simple if/else UI (not full BPMN)

#### Scenario: Delay between actions
- GIVEN an automation action chain
- WHEN the user inserts a "Wait" action
- THEN they MUST be able to configure a delay: minutes, hours, or days
- AND the delay MUST be implemented via n8n's Wait node
- AND subsequent actions MUST execute after the delay period

---

### Requirement: Automation Builder UI
The system MUST provide a visual automation builder within the Pipelinq interface.

**Feature tier**: V1

#### Scenario: Create a new automation
- GIVEN a Pipelinq user with admin permissions
- WHEN they navigate to Settings > Automatisering > Nieuw
- THEN a visual builder MUST display:
  - Step 1: Trigger selection (dropdown grouped by entity type: leads, requests, contacts, scheduled)
  - Step 2: Condition configuration (add/remove condition rows with field/operator/value)
  - Step 3: Action chain (add/reorder/remove actions with per-action configuration)
- AND the user MUST be able to name the automation and set a description
- AND the user MUST be able to toggle active/inactive status

#### Scenario: Automation builder uses existing UI patterns
- GIVEN the automation builder UI
- THEN it MUST use Nextcloud Vue components (NcSelect, NcTextField, NcButton, NcModal)
- AND it MUST follow the same form layout patterns as LeadForm.vue and RequestForm.vue
- AND it MUST support Dutch and English labels via the i18n system
- AND the settings section MUST integrate alongside existing Settings tabs (Pipeline, Product Categories, Lead Sources, Request Channels)

#### Scenario: Preview automation before activation
- GIVEN a configured automation
- WHEN the user clicks "Testen"
- THEN the system MUST show which entities currently match the trigger conditions (limited to 10 results)
- AND a dry-run MUST show what actions would execute without actually running them
- AND the dry-run result MUST display: entity matched, actions that would fire, resolved placeholder values

---

### Requirement: Automation Management
The system MUST provide a list view for managing all automations.

**Feature tier**: V1

#### Scenario: Automation list view
- WHEN the user navigates to Settings > Automatisering
- THEN all automations MUST be listed with columns: name, trigger summary, status (active/inactive), last run timestamp, total run count
- AND each automation MUST have actions: edit, activate/deactivate, duplicate, delete, view history
- AND the list MUST be sortable by name, status, and last run

#### Scenario: Automation execution history
- GIVEN an automation that has fired 25 times
- WHEN the user views the automation's history
- THEN each execution MUST show: timestamp, trigger entity (with link to entity detail), actions executed, result per action (success/failure), duration
- AND failed executions MUST show the error message from n8n
- AND the history MUST be paginated (20 per page)

#### Scenario: Bulk automation management
- GIVEN the automation list with 5 automations
- WHEN the user selects multiple automations via checkboxes
- THEN they MUST be able to bulk activate, deactivate, or delete the selected automations
- AND a confirmation dialog MUST appear before bulk delete

---

### Requirement: n8n Backend Integration
Automations MUST be stored and executed as n8n workflows via the n8n MCP integration.

**Feature tier**: MVP

#### Scenario: Automation creates n8n workflow
- GIVEN a user saves an automation in the Pipelinq UI
- WHEN the automation is saved
- THEN a corresponding n8n workflow MUST be created via the n8n MCP tools (mcp__n8n__n8n_create_workflow)
- AND the workflow MUST be configured with a webhook trigger node that accepts POST requests
- AND the workflow MUST contain action nodes matching the configured actions
- AND the Pipelinq automation record MUST store the n8n workflow ID for reference

#### Scenario: CRM events trigger n8n via webhook
- GIVEN an active automation with trigger "Lead stage changed to Qualified"
- AND the automation's n8n workflow has a webhook URL
- WHEN a lead is moved to the Qualified stage in Pipelinq
- THEN the ObjectEventDispatcher MUST fire an HTTP POST to the n8n webhook URL
- AND the webhook payload MUST include:
  - `event`: the trigger event name (e.g., "lead.stage.changed")
  - `entity`: the full entity object data (all lead fields)
  - `changes`: the diff between old and new values (from ObjectUpdateDiffService)
  - `user`: the user who triggered the change
  - `timestamp`: ISO 8601 timestamp
- AND the webhook MUST use a configurable timeout (default 10 seconds)

#### Scenario: n8n workflow synchronization
- GIVEN an automation linked to n8n workflow ID 42
- WHEN the user edits the automation in Pipelinq and changes actions
- THEN the corresponding n8n workflow MUST be updated via mcp__n8n__n8n_update_workflow (not recreated)
- AND if the n8n workflow no longer exists (deleted externally), the system MUST recreate it and update the stored workflow ID

#### Scenario: Automation deactivation syncs to n8n
- GIVEN an active automation linked to n8n workflow ID 42
- WHEN the user deactivates the automation
- THEN the n8n workflow MUST also be deactivated (set to inactive in n8n)
- AND when re-activated, the n8n workflow MUST be re-activated

---

### Requirement: Automation Data Storage
Automation configurations MUST be stored as OpenRegister objects with a dedicated schema.

**Feature tier**: MVP

#### Scenario: Automation schema definition
- GIVEN the Pipelinq register in OpenRegister
- THEN an `automation` schema MUST be defined with the following properties:
  - `title` (string, required): automation name
  - `description` (string, optional): automation description
  - `active` (boolean, required): whether the automation is active
  - `trigger` (object, required): `{type, entityType, conditions[]}`
  - `actions` (array, required): `[{type, config}]` ordered action list
  - `n8nWorkflowId` (string, optional): reference to the n8n workflow
  - `lastRunAt` (datetime, optional): timestamp of last execution
  - `runCount` (integer, default 0): total execution count
- AND the schema MUST be added to `lib/Settings/pipelinq_register.json`

#### Scenario: Automation execution log storage
- GIVEN an automation execution completes (success or failure)
- THEN an execution log entry MUST be stored with:
  - `automationId`: reference to the automation
  - `triggeredAt`: ISO 8601 timestamp
  - `triggerEntity`: reference to the entity that triggered (type + ID)
  - `actions`: array of `{type, status, error?, duration}`
  - `status`: overall status ("success" | "partial_failure" | "failure")
- AND execution logs older than 90 days MUST be automatically purged

#### Scenario: Automation CRUD via OpenRegister API
- GIVEN the automation schema is registered
- THEN automations MUST be queryable via the standard OpenRegister API
- AND the frontend automation list MUST use the same Pinia store pattern as leads, requests, and contacts

---

### Requirement: SLA Escalation Automation
The system MUST support SLA-based escalation automations that monitor time-sensitive deadlines.

**Feature tier**: V1

#### Scenario: Lead stale detection escalation
- GIVEN an automation with trigger "Lead stale" and threshold 7 days
- WHEN a lead has not been updated for 7 days
- THEN the automation MUST fire with action: send notification to the lead's assignee
- AND if the lead remains stale for 14 days, a second escalation MUST fire to the assignee's manager
- AND the stale check MUST run via a scheduled n8n workflow (daily cron)

#### Scenario: Request response time SLA
- GIVEN an automation with trigger "Request created" and an SLA rule "respond within 24 hours"
- WHEN a request has status "new" for more than 24 hours
- THEN the automation MUST fire with actions:
  - Send notification to the assignee: "SLA overschrijding: [request.title]"
  - Update the request priority to "high"
  - Add note: "Automatische escalatie: antwoordtermijn overschreden"

#### Scenario: Configurable SLA thresholds
- GIVEN the SLA escalation configuration
- THEN the user MUST be able to set thresholds in: hours, business days, or calendar days
- AND business days MUST exclude weekends (Saturday and Sunday)
- AND the thresholds MUST be configurable per pipeline or per status

---

### Requirement: Email Sequence Automation
The system MUST support multi-step email sequences triggered by CRM events.

**Feature tier**: Enterprise

#### Scenario: Lead nurture email sequence
- GIVEN an automation with trigger "Lead created" and source "website"
- WHEN the automation fires
- THEN the following email sequence MUST execute:
  - Day 0: Send welcome email
  - Day 3: Send product information email
  - Day 7: Send case study email
  - Day 14: Send follow-up email with meeting link
- AND each email MUST use a configured template with entity placeholders
- AND the sequence MUST stop if the lead stage changes to "Qualified" or "Lost" before completion

#### Scenario: Email sequence opt-out
- GIVEN a lead in an active email sequence
- WHEN the contact clicks "Uitschrijven" in any email
- THEN the email sequence MUST immediately stop for that lead
- AND the lead MUST be tagged with "email-opted-out"
- AND no further automated emails MUST be sent to that contact

#### Scenario: Email sequence analytics
- GIVEN an email sequence automation
- WHEN the user views the automation's analytics
- THEN the system MUST show per step: emails sent, open rate, click rate, unsubscribe rate
- AND the system MUST show overall conversion rate (leads that reached "Qualified" stage)

---

### Requirement: Permission Control
The system MUST enforce permissions for automation management.

**Feature tier**: MVP

#### Scenario: Admin-only automation management
- GIVEN a Pipelinq user without admin permissions
- WHEN they navigate to Settings
- THEN the "Automatisering" section MUST NOT be visible
- AND API requests to create/update/delete automations MUST return 403 Forbidden

#### Scenario: Automation execution respects entity permissions
- GIVEN an automation that updates a lead's assignee
- WHEN the automation executes via n8n
- THEN the n8n workflow MUST authenticate as a service account (not as the triggering user)
- AND the service account MUST have permissions to modify all CRM entities
- AND the audit trail MUST record the automation as the author (not a human user)

#### Scenario: Automation visibility for non-admin users
- GIVEN a non-admin user viewing a lead detail page
- WHEN an automation has recently executed on this lead
- THEN the activity timeline MUST show the automation action (e.g., "Automation 'Lead toewijzing' has assigned this lead to maria")
- AND the user MUST NOT be able to edit or disable the automation from this view

---

### Requirement: Error Handling and Monitoring
The system MUST handle automation failures gracefully and provide monitoring tools.

**Feature tier**: V1

#### Scenario: n8n webhook delivery failure
- GIVEN an active automation with a configured n8n webhook
- WHEN the webhook delivery fails (n8n unreachable, timeout, 5xx response)
- THEN the system MUST retry delivery up to 3 times with exponential backoff (1s, 5s, 30s)
- AND if all retries fail, the execution MUST be logged as "failed" with the error details
- AND a notification MUST be sent to the admin user: "Automatisering '[name]' mislukt"

#### Scenario: n8n workflow execution error
- GIVEN an automation's n8n workflow encounters an error during execution
- WHEN n8n reports the workflow execution as failed
- THEN the execution log MUST record the error message from n8n
- AND the automation MUST remain active (not auto-disabled)
- AND if the same automation fails 5 consecutive times, a warning notification MUST be sent to admin

#### Scenario: Automation loop detection
- GIVEN automation A triggers on "Lead stage changed" and moves the lead to stage "Qualified"
- AND automation B triggers on "Lead stage changed to Qualified" and moves the lead to stage "In Review"
- WHEN automation A fires
- THEN the system MUST detect the potential loop after 5 chained executions
- AND MUST halt execution with error: "Automatiseringslus gedetecteerd"
- AND MUST log the full chain of triggered automations

---

## Dependencies
- n8n MCP integration (workflow creation and execution via mcp__n8n__* tools)
- Pipelinq event system (ObjectEventListener, ObjectEventHandlerService, ObjectUpdateDiffService)
- OpenRegister webhook infrastructure
- Nextcloud notification system (OCP\Notification\IManager)
- Nextcloud activity system (OCP\Activity\IManager)
- SystemTag service for lead sources (used in trigger conditions)

---

### Current Implementation Status

**Partially implemented** at the infrastructure level (event system and notifications), but the automation builder UI and n8n workflow creation from Pipelinq are NOT implemented.

Implemented (infrastructure only):
- **Event detection**: `lib/Listener/ObjectEventListener.php` listens to OpenRegister `ObjectCreatedEvent` and `ObjectUpdatedEvent`. `ObjectEventHandlerService.php` identifies entity types and detects changes (assignee, stage, status). `ObjectUpdateDiffService.php` computes diffs between old and new objects.
- **Event dispatching**: `lib/Service/ObjectEventDispatcher.php` dispatches CRM events to the Activity stream and Notification system.
- **Activity publishing**: `lib/Service/ActivityService.php` publishes events: `lead_created`, `request_created`, `lead_assigned`, `request_assigned`, `lead_stage_changed`, `request_status_changed`, `note_added`.
- **Notifications**: `lib/Service/NotificationService.php` and `lib/Notification/Notifier.php` -- sends Nextcloud notifications on assignment and stage/status changes. Per-user notification preferences in `SettingsService` (`notify_assignments`, `notify_stage_status`, `notify_notes`).
- **n8n MCP** is configured in the workspace `.mcp.json` and available for workflow creation, but not integrated into the Pipelinq UI.

NOT implemented:
- No automation builder UI (`Settings > Automatisering` does not exist).
- No CRM automation triggers exposed in the UI (lead stage changed, lead created, etc.).
- No CRM automation actions configurable from the UI (assign lead, move stage, send email, etc.).
- No n8n workflow creation from Pipelinq (no programmatic bridge to n8n MCP).
- No automation management list (active/inactive, execution history).
- No webhook firing to n8n on CRM events.
- No round-robin assignment logic.
- No conditional trigger configuration.
- No automation preview/dry-run capability.
- No scheduled automation triggers.
- No automation schema in pipelinq_register.json.
- No SLA escalation automation.
- No email sequence automation.
- No automation loop detection.

### Standards & References
- n8n Workflow API -- for programmatic workflow creation and execution
- n8n MCP (Model Context Protocol) -- stdio-based integration for workflow management (tools: n8n_create_workflow, n8n_test_workflow, n8n_get_workflow, n8n_list_workflows, n8n_executions)
- Nextcloud Activity API (`OCP\Activity\IManager`) -- used for event publishing
- Nextcloud Notification API (`OCP\Notification\IManager`) -- used for user notifications
- OpenRegister Event System (`ObjectCreatedEvent`, `ObjectUpdatedEvent`) -- triggers for CRM state changes
- EspoCRM workflow patterns (trigger types, action types, condition builders) -- competitive reference
- Krayin automation workflows (event-driven, webhook service) -- competitive reference
- Twenty CRM workflow engine (trigger-action, branching, delays) -- competitive reference

### Specificity Assessment
- The spec now defines 12 requirements with 3-5 scenarios each, covering triggers, conditions, actions, UI, management, n8n integration, data storage, SLA, email sequences, permissions, and error handling.
- **Implementable incrementally**: MVP tier covers triggers, conditions, actions, n8n integration, data storage, and permissions. V1 adds the builder UI, management list, SLA escalation, and error monitoring. Enterprise adds email sequences.
- **Resolved**: Webhook payload format is now specified (event, entity, changes, user, timestamp).
- **Resolved**: Automation storage is now specified (OpenRegister objects with automation schema).
- **Resolved**: Error handling is now specified (retry logic, loop detection, failure notifications).
- **Resolved**: Permissions are now specified (admin-only management, service account execution).
- **Design decision**: The automation builder is a Pipelinq-native UI that generates n8n workflows, not a wrapper around n8n's workflow editor. This provides a CRM-focused UX while leveraging n8n's execution engine.

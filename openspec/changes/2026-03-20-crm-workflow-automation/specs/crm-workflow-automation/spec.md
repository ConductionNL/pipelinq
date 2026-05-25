# Spec: crm-workflow-automation

## Purpose

Define functional requirements for the CRM workflow automation feature in Pipelinq. This enables users to configure trigger-action automations that fire automatically on CRM events (lead created, stage changed, lead assigned, contact created).

**Feature tier**: V1
**Entities**: `automation` (ADR-000), `automationLog` (ADR-000)

---

## Requirements

### REQ-AUTO-001: Automation List View [V1]

The system MUST provide a list view of all configured automations with status, last run, and run count.

#### Scenario: View automation list

- GIVEN a user navigates to Automatisering
- WHEN 3 automations exist: "Welkomstmelding" (active), "Toewijzen bij Gekwalificeerd" (active), "Mailchimp sync" (inactive)
- THEN a table MUST display columns: Name, Trigger, Status, Last Run, Run Count, Actions
- AND active automations MUST display a green "Actief" badge
- AND inactive automations MUST display a grey "Inactief" badge

#### Scenario: Empty state

- GIVEN no automations exist
- WHEN the user navigates to Automatisering
- THEN an empty state MUST display with message "Geen automatiseringen geconfigureerd" and a "Nieuwe automatisering" button

#### Scenario: Activate / deactivate toggle

- GIVEN an automation "Welkomstmelding" with `isActive: false`
- WHEN the user clicks the status toggle
- THEN the system MUST call `PUT /api/automations/{id}` with `{ isActive: true }`
- AND the status badge MUST update to "Actief" without page reload

---

### REQ-AUTO-002: Automation Create & Edit [V1]

The system MUST provide a builder form for creating and editing automations.

#### Scenario: Create automation with trigger and action

- GIVEN the user clicks "Nieuwe automatisering"
- WHEN they enter name "Welkomstmelding bij nieuw lead", select trigger "lead_created", and add action "send_notification" with message "Nieuw lead: {{lead.title}}"
- THEN the automation MUST be created via `POST /api/automations` with `isActive: false`
- AND the user MUST be navigated to the automation list
- AND the new automation MUST appear with status "Inactief"

#### Scenario: Name is required

- GIVEN the automation builder form
- WHEN the user submits without entering a name
- THEN validation error "Naam is verplicht" MUST appear
- AND the Save button MUST remain disabled

#### Scenario: Trigger is required

- GIVEN the automation builder form
- WHEN the user submits without selecting a trigger
- THEN validation error "Selecteer een trigger" MUST appear

#### Scenario: At least one action required

- GIVEN the automation builder form with name and trigger filled
- WHEN the user submits with no actions added
- THEN validation error "Voeg minimaal één actie toe" MUST appear

#### Scenario: Edit existing automation

- GIVEN automation "Toewijzen bij Gekwalificeerd" with one action "assign_lead"
- WHEN the user clicks Edit and adds a second action "send_notification"
- THEN the form MUST pre-populate all existing values
- AND saving MUST call `PUT /api/automations/{id}` with both actions in the `actions` array

#### Scenario: Trigger conditions for lead_stage_changed

- GIVEN the user selects trigger "lead_stage_changed"
- THEN a stage selector MUST appear to optionally filter by specific stage
- AND if a stage is selected, the automation MUST only fire when the lead enters that specific stage

#### Scenario: Trigger conditions for lead_created with pipeline filter

- GIVEN the user selects trigger "lead_created"
- THEN a pipeline selector MUST appear to optionally limit to leads in a specific pipeline

---

### REQ-AUTO-003: Action Types [V1]

The automation builder MUST support 5 action types.

#### Scenario: assign_lead action

- GIVEN the user adds action type "assign_lead"
- THEN an assignee user selector MUST appear
- AND on execution the automation MUST update `lead.assignee` to the configured user UID

#### Scenario: move_stage action

- GIVEN the user adds action type "move_stage"
- THEN a stage name input MUST appear
- AND on execution the automation MUST update `lead.stage` to the configured stage name

#### Scenario: send_notification action

- GIVEN the user adds action type "send_notification"
- THEN message text input and user selector MUST appear
- AND on execution the automation MUST call `NotificationService` for each configured user

#### Scenario: add_note action

- GIVEN the user adds action type "add_note"
- THEN a note text input MUST appear
- AND on execution the automation MUST append a note to the trigger entity's `notes` array

#### Scenario: webhook action

- GIVEN the user selects action type "webhook"
- THEN a webhook URL field MUST appear (or use the automation's `webhookUrl` property)
- AND on execution the automation MUST HTTP POST the entity payload to that URL

#### Scenario: Action ordering

- GIVEN an automation with actions: [assign_lead, send_notification]
- WHEN the automation fires
- THEN `assign_lead` MUST execute BEFORE `send_notification`
- AND if `assign_lead` fails, `send_notification` MUST NOT execute
- AND the failure MUST be recorded in `automationLog.actionsExecuted`

---

### REQ-AUTO-004: Delete Automation [V1]

The system MUST allow deleting an automation with confirmation.

#### Scenario: Delete automation

- GIVEN automation "Mailchimp sync" exists
- WHEN the user clicks Delete and confirms
- THEN the system MUST call `DELETE /api/automations/{id}`
- AND the automation MUST be removed from the list
- AND its `automationLog` entries MUST be preserved (not cascade-deleted)

#### Scenario: Delete confirmation dialog

- GIVEN the user clicks Delete on an automation
- THEN a confirmation dialog MUST appear using `NcDialog` or `CnDeleteDialog`
- AND `window.confirm()` MUST NOT be used

---

### REQ-AUTO-005: CRM Event Triggers [V1]

The system MUST fire automations automatically when CRM events occur.

#### Scenario: Automation fires on lead_created

- GIVEN automation "Welkomstmelding" with trigger `lead_created` and `isActive: true`
- WHEN a new lead "Gemeente Amsterdam deal" is saved via `ObjectEventHandlerService`
- THEN `AutomationService::getMatchingAutomations('lead_created', $leadData)` MUST be called
- AND the matching automation MUST execute its configured actions
- AND an `automationLog` entry MUST be created with `status: success`

#### Scenario: Inactive automation does not fire

- GIVEN automation "Mailchimp sync" with `isActive: false`
- WHEN a contact is created
- THEN the automation MUST NOT execute
- AND no `automationLog` entry is created

#### Scenario: Trigger conditions filter automations

- GIVEN automation "Toewijzen senior" with trigger `lead_stage_changed` and condition `{ stage: "Gekwalificeerd" }`
- WHEN a lead moves to stage "Prospecting"
- THEN the automation MUST NOT fire
- AND when the same lead moves to stage "Gekwalificeerd"
- THEN the automation MUST fire

#### Scenario: Multiple automations for same trigger

- GIVEN two active automations both triggered by `lead_created`
- WHEN a lead is created
- THEN BOTH automations MUST execute
- AND each MUST produce its own `automationLog` entry

---

### REQ-AUTO-006: Automation Execution History [V1]

The system MUST provide an execution history view per automation.

#### Scenario: View execution history

- GIVEN automation "Toewijzen bij Gekwalificeerd" has fired 3 times
- WHEN the user clicks the History button for that automation
- THEN a table MUST display: Triggered At, Trigger Entity, Status, Actions Executed (count), Error
- AND successful executions MUST show a green "Succes" badge
- AND failed executions MUST show a red "Mislukt" badge

#### Scenario: Execution history empty state

- GIVEN a newly created automation that has never fired
- WHEN the user views its history
- THEN the table MUST show empty state message "Nog geen uitvoeringen"

#### Scenario: Execution error detail

- GIVEN an automationLog entry with `status: failure` and `error: "HTTP 503"`
- WHEN the user views the history row
- THEN the error message MUST be visible (expanded inline or via row click)

---

### REQ-AUTO-007: Execution Logging [V1]

The system MUST record every automation execution attempt.

#### Scenario: Log successful execution

- GIVEN automation "Welkomstmelding" fires on lead creation
- WHEN all actions complete without error
- THEN an `automationLog` MUST be saved with:
  - `automation` = automation UUID
  - `triggeredAt` = current timestamp
  - `triggerEntity` = lead UUID
  - `actionsExecuted` = array with one entry `{ type: "send_notification", success: true }`
  - `status` = "success"

#### Scenario: Log failed execution

- GIVEN automation "Mailchimp sync" fires but the webhook returns HTTP 503
- THEN an `automationLog` MUST be saved with:
  - `status` = "failure"
  - `error` = human-readable error description
  - `actionsExecuted` = array with entry `{ type: "webhook", success: false, result: "HTTP 503" }`

#### Scenario: Execution count updated

- GIVEN automation "Welkomstmelding" has `runCount: 5`
- WHEN the automation fires successfully
- THEN `automation.runCount` MUST be incremented to 6
- AND `automation.lastRun` MUST be updated to the current timestamp

---

### REQ-AUTO-008: n8n Webhook Integration [V1]

The system MUST support firing webhooks to n8n from automation actions.

#### Scenario: Configure webhook URL

- GIVEN the user creates an automation with action type "webhook"
- WHEN they enter webhook URL "https://n8n.example.nl/webhook/contact-sync"
- THEN the URL MUST be stored on the automation object as `webhookUrl`

#### Scenario: Webhook payload format

- GIVEN automation "Mailchimp sync" fires on `contact_created`
- WHEN the webhook action executes
- THEN the system MUST HTTP POST a JSON payload to `webhookUrl` containing:
  - `trigger` (string) — trigger type
  - `entity` (object) — the triggering entity data
  - `automation` (string) — automation UUID
  - `timestamp` (string) — ISO 8601 firing time

#### Scenario: Webhook failure handling

- GIVEN the webhook URL returns an error (4xx or 5xx) or times out
- THEN the failure MUST be logged in `automationLog`
- AND the automation MUST NOT retry automatically (V1 — retries deferred to V2)
- AND subsequent actions in the sequence MUST NOT execute after a failed webhook action

---

### REQ-AUTO-009: Navigation and Routing [V1]

Automations MUST be accessible via the main navigation.

#### Scenario: Automatisering nav item

- GIVEN the user is logged into Pipelinq
- THEN "Automatisering" MUST appear in the settings/gear section of the main navigation (`MainMenu.vue`)

#### Scenario: Automation routes

- GIVEN the URL is `/automatisering`
- THEN `AutomationList.vue` MUST render
- AND given `/automatisering/{id}`
- THEN `AutomationBuilder.vue` MUST render in edit mode for that automation
- AND given `/automatisering/{id}/history`
- THEN `AutomationHistory.vue` MUST render for that automation

---

### REQ-AUTO-010: Authorization [V1]

Automation management MUST be restricted to administrators.

#### Scenario: Non-admin can view but not modify

- GIVEN a non-admin user
- WHEN they navigate to Automatisering
- THEN the automation list MUST be visible (read-only)
- AND Create, Edit, Delete buttons MUST be hidden or disabled

#### Scenario: Admin-only mutation endpoints

- GIVEN a non-admin user sends `POST /api/automations`
- THEN the server MUST return HTTP 403
- AND no automation MUST be created

#### Scenario: Error responses never expose internals

- GIVEN any exception occurs in `AutomationController`
- THEN the response MUST return a static error message (e.g., `{ "message": "Operation failed" }`)
- AND the exception details MUST be logged server-side via `ILogger`
- AND `$e->getMessage()` MUST NOT appear in the API response

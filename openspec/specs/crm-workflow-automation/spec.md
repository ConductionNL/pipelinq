# crm-workflow-automation Specification

## Purpose
Expose n8n workflow automation capabilities within the Pipelinq UI. Visual workflow builder for CRM automation: trigger-action workflows, conditional branching, and scheduled actions. Bridges the gap between n8n's powerful backend automation and Pipelinq's user-facing CRM interface.

## Context
Built-in automation engines are a standard expectation in modern CRM platforms. Our architecture uses n8n as the workflow engine (via MCP), which is more powerful than typical built-in CRM automation. The gap is in surfacing these automations in the Pipelinq UI so that CRM users can create and manage automations without directly accessing n8n. This spec defines the bridge between Pipelinq's CRM events and n8n workflows.

**Relation to existing specs:** The `workflow-engine-abstraction` spec in OpenRegister covers the general n8n integration layer. This spec focuses on CRM-specific automation patterns and UI.

## ADDED Requirements

### Requirement: CRM automation triggers
The system MUST expose CRM events as automation triggers selectable in the Pipelinq UI.

#### Scenario: Available CRM triggers
- GIVEN the automation builder in Pipelinq
- THEN the following triggers MUST be available:
  - **Lead stage changed** -- fires when a lead moves to a specific stage
  - **Lead created** -- fires when a new lead is created
  - **Lead assigned** -- fires when a lead is (re)assigned
  - **Lead value changed** -- fires when the monetary value changes
  - **Contact created** -- fires when a new contact is created
  - **Lead stale** -- fires when a lead exceeds the stale threshold
  - **Quote accepted/rejected** -- fires on quote status change
  - **Scheduled** -- fires on a time-based schedule (daily, weekly, custom cron)

#### Scenario: Configure trigger with conditions
- GIVEN a trigger "Lead stage changed"
- WHEN the user configures the trigger
- THEN they MUST be able to add conditions:
  - "Only when stage changes to: Qualified"
  - "Only for pipeline: Sales Pipeline"
  - "Only when value > EUR 10,000"

### Requirement: CRM automation actions
The system MUST expose CRM actions that automations can execute.

#### Scenario: Available CRM actions
- GIVEN the automation builder
- THEN the following actions MUST be available:
  - **Assign lead** -- set assignedTo to a specific user or round-robin
  - **Move lead to stage** -- change the lead's pipeline stage
  - **Send notification** -- send a Nextcloud notification to a user
  - **Send email** -- send an email using a template
  - **Create task** -- create a task linked to the lead
  - **Update field** -- set a field value on the lead/contact
  - **Add note** -- add a note to the entity's timeline
  - **Webhook** -- call an external URL with entity data

#### Scenario: Round-robin lead assignment
- GIVEN an automation with trigger "Lead created" and action "Assign lead (round-robin)"
- AND configured users: jan, maria, pieter
- WHEN 3 new leads are created
- THEN the first lead MUST be assigned to jan
- AND the second to maria
- AND the third to pieter
- AND the fourth cycles back to jan

### Requirement: Automation builder UI
The system MUST provide a visual automation builder within the Pipelinq interface.

#### Scenario: Create a simple automation
- GIVEN a Pipelinq user with automation management permissions
- WHEN they navigate to Settings > Automatisering > Nieuw
- THEN a visual builder MUST display: trigger selection, condition configuration, and action chain
- AND the user MUST be able to name the automation and set it active/inactive

#### Scenario: Automation with multiple actions
- GIVEN an automation for trigger "Lead moved to Won"
- WHEN the user adds actions:
  1. Send notification to the lead's assignee: "Lead gewonnen!"
  2. Send email to client: "Bedankt voor uw vertrouwen" template
  3. Create task: "Contract opstellen" assigned to the lead's owner
- THEN all three actions MUST execute in sequence when the trigger fires

#### Scenario: Preview automation
- GIVEN a configured automation
- WHEN the user clicks "Testen"
- THEN the system MUST show which leads currently match the trigger conditions
- AND a dry-run MUST show what actions would execute without actually running them

### Requirement: Automation management
The system MUST provide a list view for managing automations.

#### Scenario: Automation list
- WHEN the user navigates to Settings > Automatisering
- THEN all automations MUST be listed with: name, trigger summary, status (active/inactive), last run, run count
- AND each automation MUST have actions: edit, activate/deactivate, delete, view history

#### Scenario: Automation execution history
- GIVEN an automation that has fired 25 times
- WHEN the user views the automation's history
- THEN each execution MUST show: timestamp, trigger entity, actions executed, result (success/failure)
- AND failed executions MUST show the error details

### Requirement: n8n backend integration
Automations MUST be stored and executed as n8n workflows.

#### Scenario: Automation creates n8n workflow
- GIVEN a user saves an automation in the Pipelinq UI
- WHEN the automation is saved
- THEN a corresponding n8n workflow MUST be created via the n8n MCP
- AND the workflow MUST be configured with the appropriate webhook trigger and action nodes
- AND the Pipelinq automation record MUST store the n8n workflow ID for reference

#### Scenario: CRM events trigger n8n
- GIVEN an active automation with trigger "Lead stage changed to Qualified"
- WHEN a lead is moved to the Qualified stage in Pipelinq
- THEN the system MUST fire a webhook to the corresponding n8n workflow
- AND the webhook payload MUST include the full lead object data

## Dependencies
- n8n MCP integration (workflow creation and execution)
- Pipelinq event system (for detecting CRM state changes)
- OpenRegister webhook infrastructure
- Nextcloud notification system

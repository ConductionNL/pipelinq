# CRM Workflow Automation UI - Delta Spec

## Status: planned

## ADDED Requirements

### Requirement: Visual Automation Builder

The system MUST provide a no-code automation builder for CRM-specific trigger-action workflows.

#### Scenario: Create a lead stage change automation
- GIVEN a user in the automation builder
- WHEN they select trigger "Lead stage changed", condition "stage = Offerte", action "Send notification to manager"
- THEN an n8n workflow MUST be created via MCP with the corresponding webhook trigger and action nodes

### Requirement: n8n Webhook Integration

The existing event detection pipeline MUST fire n8n webhooks for active automations.

#### Scenario: Trigger fires on lead stage change
- GIVEN an active automation with trigger "Lead stage changed to Offerte"
- WHEN a lead is moved to the "Offerte" stage
- THEN the ObjectEventHandlerService MUST fire the configured n8n webhook

### Requirement: Automation Management

Users MUST be able to list, enable/disable, and view execution history of automations.

#### Scenario: Disable an automation
- GIVEN an active automation
- WHEN the user toggles it to disabled
- THEN the n8n workflow MUST be deactivated and webhooks MUST stop firing

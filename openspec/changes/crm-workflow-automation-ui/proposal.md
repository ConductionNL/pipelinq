# CRM Workflow Automation UI

## Problem
The event detection infrastructure exists (ObjectEventListener, ObjectEventHandlerService, ObjectUpdateDiffService) and notification dispatching works, but there is no automation builder UI and no n8n workflow creation from within Pipelinq. Users cannot create trigger-action automations without directly accessing n8n.

## Current State (Implemented)
- ObjectEventListener listens to OpenRegister create/update events
- ObjectEventHandlerService identifies entity types and detects changes
- ObjectUpdateDiffService computes diffs (assignee, stage, status changes)
- NotificationService dispatches notifications on detected events

## Proposed Solution
Build a visual automation builder UI in Pipelinq that creates n8n workflows via the n8n MCP integration. Support CRM-specific triggers (lead stage changed, request created, etc.), conditions, and actions. Bridge the gap between n8n backend power and CRM user accessibility.

## Impact
- New automation builder Vue components
- n8n workflow creation via MCP
- Webhook firing from event handlers to n8n
- Automation list and management UI

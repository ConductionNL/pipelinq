# CRM Workflow Automation Specification

## Status: partial

## Purpose

Expose n8n workflow automation capabilities within the Pipelinq UI. Visual workflow builder for CRM automation: trigger-action workflows, conditional branching, and scheduled actions.

---

## Requirements

### Requirement: CRM Event Detection Infrastructure

**Status: implemented**

The system MUST detect CRM entity changes and dispatch events for downstream processing.

#### Scenario: Detect lead creation
- GIVEN a new lead is created as an OpenRegister object
- WHEN ObjectEventListener receives ObjectCreatedEvent
- THEN ObjectEventHandlerService MUST identify the entity type and dispatch appropriate events

#### Scenario: Detect field changes on update
- GIVEN a lead's assignee or stage changes
- WHEN ObjectEventListener receives ObjectUpdatedEvent
- THEN ObjectUpdateDiffService MUST compute the diff and identify changed fields (assignee, stage, status)

### Requirement: Notification Dispatch on Events

**Status: implemented**

The system MUST dispatch Nextcloud notifications on detected CRM events.

#### Scenario: Notify on lead assignment
- GIVEN a lead is assigned to user "petra"
- WHEN ObjectEventHandlerService detects the assignee change
- THEN NotificationService::notifyAssignment() MUST send a notification to "petra"

#### Scenario: Notify on stage change
- GIVEN a lead moves from "Prospectie" to "Offerte"
- WHEN the stage change is detected
- THEN NotificationService::notifyStageChange() MUST notify relevant users

---

## Unimplemented Requirements

The following requirements are tracked as a change proposal:

**Change:** `openspec/changes/crm-workflow-automation-ui/`

- Visual automation builder UI (trigger selector, condition builder, action configurator)
- n8n workflow creation from Pipelinq via MCP
- Webhook firing from event handlers to n8n
- Automation list and management with enable/disable
- Scheduled triggers (daily, weekly, custom cron)
- Automation execution history
- Automation template library

---

### Implementation References

- `lib/Listener/ObjectEventListener.php` -- listens to OpenRegister create/update events
- `lib/Service/ObjectEventHandlerService.php` -- entity type identification and change detection
- `lib/Service/ObjectUpdateDiffService.php` -- computes diffs between old and new objects
- `lib/Service/NotificationService.php` -- dispatches Nextcloud notifications

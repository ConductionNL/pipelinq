# Spec: crm-workflow-automation

**App:** Pipelinq  
**Change:** crm-workflow-automation  
**Entities:** automation, automationLog (existing schemas from ADR-000)

---

## Feature 1: Webhooks API

**Demand:** 602 (200 tender mentions) | Category: integration

First-class webhook subscription management. CRM administrators can create, list, test, and delete webhook endpoints that fire automatically on CRM entity events.

### REQ-WEB-001: Create Webhook Subscription

- GIVEN an administrator navigates to Webhooks in Pipelinq
- WHEN they fill in a webhook URL, select subscribed events, and click Save
- THEN the webhook subscription MUST be persisted via `WebhookService`
- AND the subscription MUST be listed immediately in the webhook list
- AND the webhook MUST be inactive until explicitly activated

### REQ-WEB-002: List Webhook Subscriptions

- GIVEN an administrator opens the Webhooks list
- WHEN the list loads
- THEN all webhook subscriptions MUST be displayed with URL, subscribed events, active status, and last fired timestamp
- AND the list MUST support filtering by status (active/inactive)
- AND the list MUST support search by URL

### REQ-WEB-003: Activate and Deactivate Webhook

- GIVEN an existing webhook subscription
- WHEN the administrator toggles the active status
- THEN the webhook MUST be enabled or disabled immediately
- AND inactive webhooks MUST NOT receive event payloads

### REQ-WEB-004: Test Webhook

- GIVEN an existing webhook subscription with a URL
- WHEN the administrator clicks "Test"
- THEN the system MUST fire a sample CloudEvents payload to the webhook URL
- AND the test result (HTTP status, response body) MUST be displayed to the administrator
- AND the test event MUST NOT be logged as a real automation execution

### REQ-WEB-005: Delete Webhook Subscription

- GIVEN an existing webhook subscription
- WHEN the administrator confirms deletion
- THEN the webhook subscription MUST be removed
- AND no further events MUST be delivered to that URL

### REQ-WEB-006: Webhook Event Payload Format

- GIVEN a CRM entity event fires and a matching active webhook exists
- WHEN the webhook is dispatched
- THEN the payload MUST follow CloudEvents specification (type, source, subject, time, data)
- AND the `data` field MUST contain the entity's current properties
- AND delivery MUST be attempted via HTTPS POST with Content-Type `application/cloudevents+json`

### REQ-WEB-007: Webhook Retry on Failure

- GIVEN a webhook delivery fails (non-2xx HTTP response or timeout)
- WHEN the first delivery attempt fails
- THEN the system MUST retry delivery with exponential backoff (1 min, 5 min, 15 min)
- AND after 3 failed attempts the webhook MUST be marked as erroring
- AND the administrator MUST be notified via Nextcloud notification

### REQ-WEB-008: Webhook API Endpoints

- GIVEN a developer queries the webhooks REST API
- WHEN they send `GET /api/webhooks`
- THEN they MUST receive a paginated list of webhook subscriptions with `total`, `page`, `pages` fields
- AND `POST /api/webhooks` MUST create a new subscription
- AND `DELETE /api/webhooks/{id}` MUST require admin authentication

---

## Feature 2: DMN Decision Service

**Demand:** 270 (85 tender mentions) | Category: core

Execute DMN (Decision Model and Notation) decision tables against CRM entity data at runtime. Used for automated lead scoring, SLA tier assignment, routing decisions, and eligibility rules.

### REQ-DMN-001: Execute Decision Table

- GIVEN a decision table is configured in the WorkflowEngineRegistry
- WHEN a POST request is sent to `/api/dmn/evaluate` with `decisionTableId` and `inputData`
- THEN the system MUST evaluate the decision table with the provided inputs
- AND return the output values (e.g., `{ "sla_tier": "high", "assignee_pool": "legal" }`)
- AND the response MUST include the decision table ID and evaluation timestamp

### REQ-DMN-002: List Available Decision Tables

- GIVEN an administrator opens the automation builder
- WHEN they select "DMN Decision" as an action type
- THEN the system MUST call `GET /api/dmn/tables` to populate the decision table dropdown
- AND the list MUST include table ID, name, and description
- AND only decision tables registered in WorkflowEngineRegistry MUST be returned

### REQ-DMN-003: Apply Decision Output to Entity

- GIVEN a DMN evaluation returns output values
- WHEN an automation action of type `apply_decision` executes
- THEN the output properties MUST be written back to the triggering entity via `ObjectService::saveObject()`
- AND the update MUST be recorded in the entity's audit trail

### REQ-DMN-004: Decision Evaluation Error Handling

- GIVEN a decision table evaluation fails (missing required input, invalid table ID)
- WHEN the evaluation error occurs
- THEN the API MUST return HTTP 400 with a descriptive `message` field (no stack traces)
- AND the automationLog MUST record `status: failure` with the error message
- AND the automation MUST not halt other pending automations in the queue

### REQ-DMN-005: Admin-Only Access

- GIVEN any request to `/api/dmn/evaluate` or `/api/dmn/tables`
- WHEN the request is not authenticated as an admin user
- THEN the API MUST return HTTP 403
- AND no decision evaluation MUST occur

---

## Feature 3: Runtime Variable Query API

**Demand:** 77 (25 tender mentions) | Category: integration

REST endpoints to query the runtime state of automations — which are active, their last trigger data, and variable bindings — enabling external dashboards and n8n workflows to inspect automation state.

### REQ-VAR-001: List Active Automations with State

- GIVEN an external system or Pipelinq dashboard queries runtime state
- WHEN a GET request is sent to `/api/automations/runtime`
- THEN the response MUST list all automations that have been executed at least once
- AND each entry MUST include: automationId, name, lastRun, runCount, lastTriggerEntity, lastStatus
- AND the response MUST be paginated with `total`, `page`, `pages`

### REQ-VAR-002: Get Variable Bindings for Automation

- GIVEN an automation has executed at least once
- WHEN `GET /api/automations/{id}/variables` is called
- THEN the response MUST return the variable bindings from the most recent `automationLog` execution
- AND the bindings MUST include: trigger data snapshot, actionsExecuted results, output variables
- AND if no executions exist, the response MUST return an empty `variables` array with HTTP 200

### REQ-VAR-003: Query by Java API Convention

- GIVEN a developer uses the variable query API
- WHEN they filter using query parameters `trigger`, `status`, `from`, `to`
- THEN the results MUST be filtered accordingly
- AND `from`/`to` filter on `triggeredAt` (ISO 8601 datetime strings)
- AND `status` filter accepts `success` or `failure`

### REQ-VAR-004: Auth Requirement

- GIVEN any request to the runtime variable API
- WHEN the caller is not authenticated
- THEN the API MUST return HTTP 401
- AND no variable data MUST be exposed in the error response

---

## Feature 4: Marketing Automation

**Demand:** 5–1 | Category: other

Trigger-based marketing automation: when a contact or lead matches a segment condition, execute an ordered sequence of marketing actions (notifications, tags, webhooks, tasks).

### REQ-MKT-001: Define Marketing Automation Trigger

- GIVEN an administrator creates an automation with trigger `marketing_segment_match`
- WHEN they define segment conditions (e.g., industry = "Gemeente", source = "website", tag = "nieuwsbrief")
- THEN the conditions MUST be stored in `triggerConditions` as a JSON object
- AND the automation MUST be evaluated against every new/updated contact or lead

### REQ-MKT-002: Segment Condition Evaluation

- GIVEN a contact or lead is created or updated
- WHEN `MarketingSequenceService::evaluateSegment()` is called
- THEN ALL defined conditions MUST be satisfied for the automation to trigger (AND logic)
- AND partial matches MUST NOT trigger the automation
- AND evaluation MUST be case-insensitive for string comparisons

### REQ-MKT-003: Execute Marketing Action Sequence

- GIVEN a contact matches a marketing automation's segment conditions
- WHEN the automation triggers
- THEN actions MUST be executed in the order defined in the `actions` array
- AND supported action types MUST include: `send_notification`, `update_tag`, `fire_webhook`, `add_note`
- AND each action result MUST be recorded in `automationLog.actionsExecuted`

### REQ-MKT-004: Avoid Duplicate Triggering

- GIVEN a contact already triggered a specific marketing automation
- WHEN that contact is updated again without segment conditions changing
- THEN the automation MUST NOT fire again for the same contact+automation pair within 24 hours
- AND the deduplication check MUST be based on `automationLog` entries

### REQ-MKT-005: Marketing Automation List View

- GIVEN an administrator opens the Automations list
- WHEN they filter by trigger type `marketing_segment_match`
- THEN only marketing automations MUST be shown
- AND each row MUST display segment summary, action count, last run, and run count

### REQ-MKT-006: Marketing Automation Activation

- GIVEN a marketing automation is saved as inactive
- WHEN the administrator activates it
- THEN `isActive` MUST be set to `true` via `PUT /api/automations/{id}/activate`
- AND from that point, all newly created/updated contacts and leads MUST be evaluated against it

---

## Non-Functional Requirements

### REQ-NFR-001: Async Execution

- GIVEN an automation is triggered by a CRM entity save
- WHEN the matching automations are identified
- THEN execution MUST be deferred to a background job queue
- AND the entity save response MUST NOT be delayed by automation execution

### REQ-NFR-002: Audit Trail

- GIVEN any automation executes
- WHEN actions complete (success or failure)
- THEN an `automationLog` object MUST be created with full execution detail
- AND log entries MUST be queryable via `GET /api/automations/{id}/history`

### REQ-NFR-003: WCAG AA Compliance

- GIVEN any Pipelinq UI component for automations or webhooks
- WHEN rendered at 768px or wider
- THEN all interactive elements MUST be keyboard-navigable
- AND status indicators MUST NOT rely on color alone (use text + icon)
- AND all form fields MUST have associated labels

### REQ-NFR-004: Translations

- GIVEN any user-visible string in automation or webhook UI
- WHEN rendered in any language context
- THEN strings MUST use `t(appName, 'english key')` (Vue) or `$this->l10n->t('english key')` (PHP)
- AND both `l10n/en.json` and `l10n/nl.json` MUST contain matching keys

### REQ-NFR-005: Admin Authentication on Mutations

- GIVEN any POST, PUT, or DELETE request to automation or webhook endpoints
- WHEN the authenticated user is not a Nextcloud admin
- THEN the API MUST return HTTP 403
- AND `IGroupManager::isAdmin()` MUST be called on the backend (NOT frontend-only check)

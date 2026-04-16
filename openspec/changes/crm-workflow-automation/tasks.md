# Tasks: crm-workflow-automation

## 0. Deduplication Check

- [ ] 0.1 Search `openspec/specs/` for existing automation, webhook, DMN, and variable query specs
  - **acceptance_criteria**:
    - GIVEN the codebase is searched for `AutomationService`, `WebhookService`, `DmnDecision`, `automationLog`
    - THEN document any existing implementations and reference them
    - AND confirm no duplicate controller or service is being created
    - AND if overlap exists, justify new code over extending existing logic
  - **findings**: `automation` and `automationLog` schemas exist in ADR-000 from `from-register` spec; `WebhookService` exists in OpenRegister and must be reused (NOT reimplemented); `WorkflowEngineRegistry` provides DMN evaluation. This change adds app-level service wiring and UI only.

## 1. Schema Verification

- [ ] 1.1 Verify `automation` and `automationLog` schemas are present in `lib/Settings/pipelinq_register.json`
  - **spec_ref**: `specs/crm-workflow-automation/spec.md#Feature 1`, `#Feature 4`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register JSON is read
    - THEN `automation` schema MUST be present with all ADR-000 properties
    - AND `automationLog` schema MUST be present with all ADR-000 properties
    - AND if either is missing, add it (non-breaking addition)

- [ ] 1.2 Add seed data for `automation` and `automationLog` schemas to `lib/Settings/pipelinq_register.json`
  - **spec_ref**: `design.md#Seed Data`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register JSON is imported
    - THEN 3 automation seed objects MUST be created with Dutch CRM context values
    - AND 3 automationLog seed objects MUST be created referencing the automation seeds
    - AND re-importing with `force: false` MUST NOT create duplicates (matched by slug)

## 2. Backend Services

- [ ] 2.1 Create `lib/Service/AutomationService.php`
  - **spec_ref**: `specs/crm-workflow-automation/spec.md#Feature 1`, `#Feature 4`
  - **files**: `lib/Service/AutomationService.php`
  - **acceptance_criteria**:
    - GIVEN an entity save event fires
    - WHEN `getMatchingAutomations($trigger, $entity)` is called
    - THEN ONLY active automations matching trigger type AND all conditions MUST be returned
    - AND `executeAutomation()` MUST execute actions in order and return result summary
    - AND `logExecution()` MUST create an `automationLog` object via `ObjectService::saveObject($register, $schema, $data)`
    - AND SPDX header `EUPL-1.2` MUST be present
    - AND `@spec` PHPDoc tag MUST reference `openspec/changes/crm-workflow-automation/tasks.md#task-2.1`

- [ ] 2.2 Create `lib/Service/DmnDecisionService.php`
  - **spec_ref**: `specs/crm-workflow-automation/spec.md#Feature 2`
  - **files**: `lib/Service/DmnDecisionService.php`
  - **acceptance_criteria**:
    - GIVEN a valid `decisionTableId` and `inputData`
    - WHEN `evaluateDecision()` is called
    - THEN it MUST delegate to `WorkflowEngineRegistry` and return output values
    - AND evaluation errors MUST throw exceptions (not return empty arrays)
    - AND `applyDecisionToEntity()` MUST call `ObjectService::saveObject($register, $schema, $object)` with 3 positional args

- [ ] 2.3 Create `lib/Service/AutomationVariableService.php`
  - **spec_ref**: `specs/crm-workflow-automation/spec.md#Feature 3`
  - **files**: `lib/Service/AutomationVariableService.php`
  - **acceptance_criteria**:
    - GIVEN automations exist with execution history
    - WHEN `getActiveAutomations()` is called
    - THEN ONLY automations with `runCount > 0` MUST be returned
    - AND `getRuntimeState($automationId)` MUST return the most recent automationLog entry for that automation
    - AND `getVariableBindings($automationId)` MUST return `actionsExecuted` from the most recent log

- [ ] 2.4 Create `lib/Service/MarketingSequenceService.php`
  - **spec_ref**: `specs/crm-workflow-automation/spec.md#Feature 4`
  - **files**: `lib/Service/MarketingSequenceService.php`
  - **acceptance_criteria**:
    - GIVEN a contact with `industry: "Gemeente"` and a marketing automation with condition `industry = "Gemeente"`
    - WHEN `evaluateSegment()` is called
    - THEN it MUST return `true`
    - AND GIVEN the same automation already fired for that contact within 24 hours
    - THEN `enqueueSequence()` MUST skip execution (deduplication via automationLog check)
    - AND segment evaluation MUST be case-insensitive

## 3. Backend Controllers and Routes

- [ ] 3.1 Create `lib/Controller/AutomationController.php`
  - **spec_ref**: `specs/crm-workflow-automation/spec.md#REQ-WEB-008`, `#REQ-NFR-005`
  - **files**: `lib/Controller/AutomationController.php`
  - **acceptance_criteria**:
    - GIVEN `GET /api/automations` is called
    - THEN a paginated list of automations MUST be returned with `total`, `page`, `pages`
    - AND `POST /api/automations`, `PUT /api/automations/{id}`, `DELETE /api/automations/{id}` MUST each call `IGroupManager::isAdmin()` and return HTTP 403 if not admin
    - AND `GET /api/automations/{id}/history` MUST return linked automationLog objects
    - AND error responses MUST use static messages (never `$e->getMessage()`)
    - AND controller methods MUST be ≤10 lines; delegate to `AutomationService`

- [ ] 3.2 Create `lib/Controller/WebhookController.php`
  - **spec_ref**: `specs/crm-workflow-automation/spec.md#REQ-WEB-001` through `#REQ-WEB-008`
  - **files**: `lib/Controller/WebhookController.php`
  - **acceptance_criteria**:
    - GIVEN `POST /api/webhooks/{id}/test` is called
    - THEN `WebhookService` MUST be called to fire a test event (NOT a custom implementation)
    - AND all 6 endpoints MUST delegate to `WebhookService`
    - AND mutations MUST check `IGroupManager::isAdmin()`

- [ ] 3.3 Create `lib/Controller/DmnController.php`
  - **spec_ref**: `specs/crm-workflow-automation/spec.md#REQ-DMN-001` through `#REQ-DMN-005`
  - **files**: `lib/Controller/DmnController.php`
  - **acceptance_criteria**:
    - GIVEN `POST /api/dmn/evaluate` is called with valid payload
    - THEN `DmnDecisionService::evaluateDecision()` MUST be called and result returned
    - AND invalid `decisionTableId` MUST return HTTP 400 with static `message` field
    - AND all endpoints MUST require admin authentication (HTTP 403 if not admin)

- [ ] 3.4 Create `lib/Controller/AutomationVariableController.php`
  - **spec_ref**: `specs/crm-workflow-automation/spec.md#Feature 3`
  - **files**: `lib/Controller/AutomationVariableController.php`
  - **acceptance_criteria**:
    - GIVEN `GET /api/automations/runtime` is called without authentication
    - THEN HTTP 401 MUST be returned
    - AND with valid auth, a paginated list of active automations with runtime state MUST be returned
    - AND `GET /api/automations/{id}/variables` MUST return an empty `variables` array (HTTP 200) if no executions exist

- [ ] 3.5 Register all routes in `appinfo/routes.php`
  - **spec_ref**: `design.md#Controllers`
  - **files**: `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN routes.php is loaded
    - THEN specific routes (`/api/automations/runtime`) MUST appear BEFORE wildcard `{id}` routes
    - AND all 7 AutomationController routes MUST be registered
    - AND 6 WebhookController routes MUST be registered
    - AND 2 DmnController routes MUST be registered
    - AND 2 AutomationVariableController routes MUST be registered

## 4. Event Integration

- [ ] 4.1 Modify `lib/Service/ObjectEventHandlerService.php` to dispatch automations
  - **spec_ref**: `specs/crm-workflow-automation/spec.md#REQ-NFR-001`
  - **files**: `lib/Service/ObjectEventHandlerService.php`
  - **acceptance_criteria**:
    - GIVEN an entity save event fires (lead, contact, request)
    - WHEN the event handler runs
    - THEN `AutomationService::getMatchingAutomations()` MUST be called with the trigger type and entity data
    - AND matched automations MUST be queued for background execution (NOT executed synchronously)
    - AND entity save response latency MUST NOT increase due to automation dispatch

## 5. Frontend Store

- [ ] 5.1 Register `automation` and `automation-log` object types in `src/store/store.js`
  - **spec_ref**: `design.md#Store`
  - **files**: `src/store/store.js`
  - **acceptance_criteria**:
    - GIVEN store.js is initialized
    - THEN `objectStore.registerObjectType('automation', 'automation', 'pipelinq')` MUST be called
    - AND `objectStore.registerObjectType('automation-log', 'automationLog', 'pipelinq')` MUST be called
    - AND type names MUST be kebab-case (NOT camelCase)
    - AND NEITHER type MUST be registered more than once

## 6. Frontend Views

- [ ] 6.1 Create `src/views/automations/AutomationList.vue`
  - **spec_ref**: `specs/crm-workflow-automation/spec.md#REQ-MKT-005`
  - **files**: `src/views/automations/AutomationList.vue`
  - **acceptance_criteria**:
    - GIVEN the Automations page loads
    - THEN `CnIndexPage` with `useListView` MUST render the automation list
    - AND each row MUST show: name, trigger type, status badge, last run, run count
    - AND clicking a row MUST navigate to `AutomationDetail` with the automation ID
    - AND "New Automation" button MUST navigate to `AutomationDetail` with `id=new`
    - AND SPDX header `<!-- SPDX-License-Identifier: EUPL-1.2 -->` MUST be first line
    - AND ALL imports used in `<template>` MUST be registered in `components: {}`
    - AND ALL user-visible strings MUST use `this.t(appName, 'english key')`

- [ ] 6.2 Create `src/views/automations/AutomationDetail.vue`
  - **spec_ref**: `specs/crm-workflow-automation/spec.md#Feature 1`, `#Feature 4`
  - **files**: `src/views/automations/AutomationDetail.vue`
  - **acceptance_criteria**:
    - GIVEN `id='new'` is passed as prop
    - THEN an empty `AutomationBuilder` form MUST be shown
    - AND GIVEN an existing automation ID
    - THEN `CnDetailPage` MUST show: Automation Information, Trigger Conditions, Actions, Webhook, Execution History sections
    - AND the Execution History section MUST load linked automationLog objects via reverse lookup (`fetchUsed`)
    - AND the Webhook section MUST only appear when `webhookUrl` is set
    - AND Edit/Delete header actions MUST be present

- [ ] 6.3 Create `src/views/automations/AutomationBuilder.vue`
  - **spec_ref**: `specs/crm-workflow-automation/spec.md#Feature 1`, `#Feature 4`
  - **files**: `src/views/automations/AutomationBuilder.vue`
  - **acceptance_criteria**:
    - GIVEN the builder form renders
    - THEN name, trigger dropdown, triggerConditions editor, actions list, and webhookUrl field MUST be present
    - AND trigger dropdown MUST include all 7 trigger types defined in the spec
    - AND action type dropdown MUST include: assign_lead, move_stage, send_notification, add_note, fire_webhook, update_tag, apply_decision
    - AND save MUST call `await automationStore.saveObject(data)` wrapped in `try/catch` with user-facing error feedback
    - AND NEVER use `window.confirm()` — use `NcDialog` for confirmations

- [ ] 6.4 Create `src/views/automations/AutomationHistory.vue`
  - **spec_ref**: `specs/crm-workflow-automation/spec.md#REQ-NFR-002`
  - **files**: `src/views/automations/AutomationHistory.vue`
  - **acceptance_criteria**:
    - GIVEN an automation has execution history
    - THEN a table MUST display: triggeredAt, triggerEntity (linked), status badge, actionsExecuted summary, error (if any)
    - AND status MUST use `CnStatusBadge` (not color alone — WCAG REQ-NFR-003)
    - AND empty state MUST show `CnEmptyState` if no logs exist

- [ ] 6.5 Create `src/views/webhooks/WebhookList.vue`
  - **spec_ref**: `specs/crm-workflow-automation/spec.md#REQ-WEB-001` through `#REQ-WEB-005`
  - **files**: `src/views/webhooks/WebhookList.vue`
  - **acceptance_criteria**:
    - GIVEN the Webhooks page loads
    - THEN `CnIndexPage` with `useListView` MUST render the webhook list
    - AND each row MUST show: URL (truncated), subscribed events, active status, last fired
    - AND row actions MUST include: Test, Edit, Delete
    - AND "Test" action MUST call `POST /api/webhooks/{id}/test` via `axios` from `@nextcloud/axios`
    - AND test result MUST be shown in an `NcDialog` (NOT `window.alert()`)

## 7. Navigation and Routing

- [ ] 7.1 Add automation and webhook routes to `src/router/index.js`
  - **spec_ref**: `design.md#Router`
  - **files**: `src/router/index.js`
  - **acceptance_criteria**:
    - GIVEN the router is loaded
    - THEN named route `AutomationList` MUST exist at path `/automations`
    - AND named route `AutomationDetail` MUST exist at path `/automations/:id` with props via arrow function
    - AND named route `WebhookList` MUST exist at path `/webhooks`
    - AND routes MUST use history mode (path format, NOT hash format)

- [ ] 7.2 Add "Automatiseringen" nav section to `src/navigation/MainMenu.vue`
  - **spec_ref**: `design.md#Navigation`
  - **files**: `src/navigation/MainMenu.vue`
  - **acceptance_criteria**:
    - GIVEN MainMenu renders
    - THEN a nav section or group labeled "Automatiseringen" MUST appear
    - AND it MUST contain two `NcAppNavigationItem` entries: "Automations" (→ AutomationList) and "Webhooks" (→ WebhookList)
    - AND nav item labels MUST use `this.t(appName, 'Automations')` and `this.t(appName, 'Webhooks')`

## 8. Translations

- [ ] 8.1 Add all new automation/webhook UI strings to `l10n/en.json` and `l10n/nl.json`
  - **spec_ref**: `specs/crm-workflow-automation/spec.md#REQ-NFR-004`
  - **files**: `l10n/en.json`, `l10n/nl.json`
  - **acceptance_criteria**:
    - GIVEN both translation files are compared
    - THEN EVERY key in `en.json` MUST have a matching key in `nl.json`
    - AND keys MUST be English (not Dutch)
    - AND Dutch translations go in `nl.json` values (e.g., key `"Automations"` → value `"Automatiseringen"`)

## 9. Tests

- [ ] 9.1 Write PHPUnit tests for `AutomationService`
  - **spec_ref**: company ADR-008 (testing)
  - **files**: `tests/Unit/Service/AutomationServiceTest.php`
  - **acceptance_criteria**:
    - GIVEN `AutomationServiceTest` runs
    - THEN at least 3 test methods MUST pass: condition matching (hit), condition matching (miss), action execution logging
    - AND tests MUST NOT use real database — mock `ObjectService`

- [ ] 9.2 Write PHPUnit tests for `DmnDecisionService`
  - **spec_ref**: company ADR-008, `specs/crm-workflow-automation/spec.md#REQ-DMN-004`
  - **files**: `tests/Unit/Service/DmnDecisionServiceTest.php`
  - **acceptance_criteria**:
    - GIVEN `DmnDecisionServiceTest` runs
    - THEN at least 3 test methods MUST pass: successful evaluation, invalid tableId → exception, apply output to entity
    - AND tests MUST pass in `composer check:strict`

- [ ] 9.3 Write integration tests (Newman/Postman) for automation and webhook API endpoints
  - **spec_ref**: company ADR-008, `specs/crm-workflow-automation/spec.md#REQ-WEB-008`, `#REQ-NFR-005`
  - **files**: `tests/integration/crm-workflow-automation.postman_collection.json`
  - **acceptance_criteria**:
    - GIVEN the Postman collection runs against a live Nextcloud instance
    - THEN `GET /api/automations` MUST return HTTP 200 with pagination fields
    - AND `POST /api/automations` without admin auth MUST return HTTP 403
    - AND `POST /api/webhooks/{id}/test` MUST return HTTP 200 with test result
    - AND `POST /api/dmn/evaluate` with invalid tableId MUST return HTTP 400
    - AND credentials MUST use env variable placeholders (NOT hardcoded)

## 10. Pre-Commit Verification

- [ ] 10.1 Run pre-commit checklist before opening PR
  - **files**: all new/modified files
  - **acceptance_criteria**:
    - SPDX headers present on ALL new PHP, Vue, JS files (grep -rL SPDX)
    - ALL `ObjectService` calls use 3 positional args (`$register, $schema, $id/params`)
    - NO `$e->getMessage()` in JSONResponse (static error strings only)
    - ALL mutation endpoints have `IGroupManager::isAdmin()` check
    - EACH entity type registered exactly once in store.js with kebab-case name
    - `npm run lint` passes without errors
    - ALL `await store.action()` calls wrapped in `try/catch` with user feedback
    - ZERO imports from `@nextcloud/vue` (use `@conduction/nextcloud-vue`)
    - EVERY `<NcFoo>` and `<CnFoo>` in templates has matching import AND `components: {}` entry
    - ALL `t()` keys are English (Dutch translations in `nl.json` only)
    - EVERY task marked `[x]` is fully implemented (no stubs or TODOs)

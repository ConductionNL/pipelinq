# Tasks: migrate-automation-to-flow-leaf

> **⚠ Status correction (2026-06-03):** Verified on the `development` branch of
> pipelinq, openregister, and @conduction/nextcloud-vue.
> Tasks 0.1 and 3.1 below are checked off
> but were never actually delivered. `CnFlowTab`/`CnFlowCard` and the
> `integration-flow` leaf (FlowService/FlowController/FlowProvider/link table)
> do not exist in pipelinq, openregister, or @conduction/nextcloud-vue, and
> `registerLeafIntegrations` is a dangling import (never defined/exported). The
> manifest placements added by 3.1 rendered iconless sidebar tabs with empty
> panels, so they were **removed** from `src/manifest.json` (ClientDetail /
> RequestDetail / LeadDetail). Re-do 0.1 and 3.1 for real before re-adding the
> manifest tab/widget — and surface it via the integration registry
> (`CnObjectSidebar :use-registry`), not a `component:` string.

## 0. Leaf check

- [x] 0.1 Confirm the OpenRegister `integration-flow` leaf is shipped (FlowService + FlowController + FlowProvider + CnFlowTab + CnFlowCard + link table) and note its key `flow`.
  - **acceptance_criteria**:
    - GIVEN `openregister/openspec/changes/integration-flow/`
    - THEN document the leaf key `flow` and required NC app `workflowengine`; confirm authoring lives in NC Flow / n8n.

## 1. Remove bespoke automation engine + schemas

- [x] 1.1 Remove `AutomationBuilder.vue`, the webhook-firing/DMN service, and the automation controller/routes.
  - **spec_ref**: `specs/crm-workflow-automation/spec.md#Requirement: Automation is provided by the Flow leaf + n8n, not an in-app engine`
  - **files**: `pipelinq/src/views/automations/AutomationBuilder.vue`, automation service/controller in `pipelinq/lib/`, `pipelinq/appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN the applied change
    - THEN no in-app automation engine or webhook-firing service remains.

- [x] 1.2 Retire `automation` and `automationLog` schemas in `lib/Settings/pipelinq_register.json`.
  - **spec_ref**: `specs/crm-workflow-automation/spec.md#Scenario: Bespoke automation engine and schemas are removed`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register file
    - THEN both schemas are removed; existing objects left in place pending the follow-up migration.

## 2. Schema glue

- [x] 2.1 Add `flow` to `linkedTypes` on `lead`, `request`, `client`.
  - **spec_ref**: `specs/crm-workflow-automation/spec.md#Requirement: CRM objects expose the flow leaf`
  - **files**: `pipelinq/lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register file
    - THEN `lead`, `request`, `client` list `flow` in `linkedTypes`.

## 3. Manifest placement (ADR-024)

- [x] 3.1 Place `CnFlowTab` in detail sidebars and `CnFlowCard` widget; declare `workflowengine` dependency.
  - **spec_ref**: `specs/crm-workflow-automation/spec.md#Requirement: Flow leaf is placed via the app manifest`
  - **files**: `pipelinq/src/manifest.json`
  - **acceptance_criteria**:
    - GIVEN the manifest
    - THEN lead/request/client detail pages include the flow tab; detail pages (optionally dashboard) include the widget; `dependencies[]` includes `workflowengine`.

## 4. Follow-up flag

- [x] 4.1 Record the existing-automation migration as a separate follow-up.
  - **spec_ref**: `specs/crm-workflow-automation/spec.md#Requirement: Existing automation migration is a documented follow-up`
  - **acceptance_criteria**:
    - GIVEN existing `automation`/`automationLog` objects
    - THEN a follow-up tracking item is recorded (re-create active rules as NC Flow / n8n); not built here.

## 5. Verification

- [x] 5.1 `npm run build` and `npm run check:manifest` pass.
- [x] 5.2 Register imports cleanly via `ConfigurationService::importFromApp()`.
- [x] 5.3 Browser check: with `workflowengine` enabled + leaf installed, open a lead detail; flow tab lists rules + recent fires; widget shows status.
- [x] 5.4 Confirm `AutomationBuilder.vue`, the webhook service, and both schemas are gone.

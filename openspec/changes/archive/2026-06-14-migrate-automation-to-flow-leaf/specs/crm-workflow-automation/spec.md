# Spec delta — migrate-automation-to-flow-leaf

## ADDED Requirements

### Requirement: Automation is provided by the Flow leaf + n8n, not an in-app engine

Pipelinq SHALL NOT ship a bespoke automation engine; automation rule authoring
and execution SHALL be provided by the NC workflowengine (Flow) and n8n,
surfaced for object-level visibility by the OpenRegister flow leaf
(`integration-flow`) (hydra ADR-022).

#### Scenario: Bespoke automation engine and schemas are removed

- **GIVEN** the migrate-automation-to-flow-leaf change is applied
- **THEN** `src/views/automations/AutomationBuilder.vue`, the webhook-firing/DMN
  automation service, and the automation controller/routes SHALL be removed
- **AND** the `automation` and `automationLog` schemas SHALL be retired
- **AND** rule authoring SHALL live in NC Flow admin / n8n.

#### Scenario: CRM triggers execute via Flow / n8n

- **GIVEN** a CRM event (lead stage change, new lead, etc.)
- **WHEN** an automation should fire
- **THEN** it SHALL be wired as an NC Flow rule, with rich orchestration
  delegated to **n8n** via the existing n8n integration
- **AND** Pipelinq SHALL fire no webhooks from a bespoke automation service.

### Requirement: CRM objects expose the flow leaf

The `lead`, `request`, and `client` schemas SHALL declare `flow` in
`linkedTypes` so the leaf's tab and widget appear on those objects.

#### Scenario: Flow tab and widget show wired rules + recent fires

- **GIVEN** NC `workflowengine` is enabled and the flow leaf is registered
- **WHEN** a user opens a `lead`, `request`, or `client` detail page
- **THEN** the leaf's `CnFlowTab` SHALL list flow rules scoped to that
  object/schema with last-fire timestamps and a recent-events panel
- **AND** the `CnFlowCard` widget SHALL show automation status.

### Requirement: Flow leaf is placed via the app manifest

The flow leaf's tab and widget SHALL be surfaced through `src/manifest.json`
(ADR-024), and `workflowengine` SHALL be declared as a dependency.

#### Scenario: Manifest places tab/widget and declares dependency

- **GIVEN** Pipelinq's `src/manifest.json`
- **THEN** the lead/request/client detail pages' `sidebar` config SHALL include
  the flow leaf tab
- **AND** detail pages (and optionally the dashboard) MAY include the
  `CnFlowCard` widget
- **AND** `dependencies[]` SHALL include `workflowengine`.

### Requirement: Existing automation migration is a documented follow-up

Migration of existing `automation` / `automationLog` objects SHALL NOT be
performed by this change and SHALL be documented as a separate follow-up
(ADR-032 bounded scope).

#### Scenario: Follow-up is recorded, not silently dropped

- **GIVEN** existing `automation` rules and `automationLog` history
- **WHEN** this migration is applied
- **THEN** those objects SHALL be left in place and a follow-up tracking item
  SHALL be recorded for re-creating active rules as NC Flow rules / n8n
  workflows.

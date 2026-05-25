# Proposal: migrate-automation-to-flow-leaf

## Why

Pipelinq ships a bespoke automation engine — `src/views/automations/AutomationBuilder.vue`
(365 LOC) plus the `automation` / `automationLog` schemas, a webhook-firing
service, and DMN evaluation. This re-implements automation orchestration that
already exists: the NC **workflowengine** (Flow), surfaced by OpenRegister as the
**flow leaf** (`integration-flow`), with **n8n** as the execution backend the
existing spec already names (`webhookUrl`, `n8nWorkflowId`, "fire_webhook").

Per hydra ADR-022, an app must consume the OR abstraction rather than build a
parallel engine. The flow leaf ships `FlowService` + `FlowController` +
`FlowProvider` + `CnFlowTab` (flow rules scoped to schema/object + recent fire
events) + `CnFlowCard` widget on all four surfaces. It gives object-level
visibility — "which automations are wired to this object/schema" and "what fired
recently" — which is exactly what the bespoke builder tried to provide. Rule
**authoring** lives in NC Flow's admin UI (and n8n for the actual workflow
execution); the leaf's out-of-scope is explicit: "Flow rule authoring (NC Flow
admin UI owns); custom flow operation types; replacing OR's workflow engine."

So Pipelinq stops owning an automation engine. CRM events fire NC Flow / n8n
workflows; the leaf surfaces wired rules + recent fires on the CRM object.

## What Changes

### Replace the in-app automation engine with the flow leaf

1. **Remove the bespoke automation engine** — `AutomationBuilder.vue`, the
   webhook-firing/DMN service, and the bespoke automation controller/routes.
2. **Retire the `automation` / `automationLog` schemas** — rule definitions live
   in NC Flow / n8n; fire history is read from NC Flow events via the leaf.
   (Existing-data migration is a documented follow-up, not in scope here.)
3. **Align execution to flow / n8n.** CRM triggers (lead stage change, new lead,
   etc.) are wired as NC Flow rules; rules that need rich orchestration call out
   to **n8n** (the backend the original spec already named). Pipelinq fires no
   webhooks from a bespoke service.
4. **Add `flow` to `linkedTypes`** on the CRM schemas that should show automation
   visibility (`lead`, `request`, `client`).
5. **Place the leaf via the manifest (ADR-024).** `CnFlowTab` mounts in the
   relevant detail sidebars (linked flow rules + recent fire events);
   `CnFlowCard` widget shows automation status on detail pages and optionally the
   dashboard.
6. **Declare the `workflowengine` dependency** in `src/manifest.json`
   `dependencies[]` (NC core app; may be disabled). Where n8n execution is used,
   the route is via the existing n8n integration, not a pipelinq webhook service.

## Out of Scope

- Flow rule authoring — NC Flow admin UI owns it.
- n8n workflow authoring — lives in n8n.
- Replacing OR's workflow engine or adding custom flow operation types.
- Migration of existing `automation` / `automationLog` objects — documented
  follow-up.

## Impact

- **Removed**: `src/views/automations/AutomationBuilder.vue`, the
  webhook-firing/DMN automation service, automation controller/routes.
- **Modified schemas**: `lead`, `request`, `client` gain `flow` in `linkedTypes`;
  `automation`/`automationLog` retired.
- **Modified files**: `src/manifest.json` (tab/widget placement +
  `workflowengine` dependency), `lib/Settings/pipelinq_register.json`.
- **Dependency**: OpenRegister `integration-flow` leaf shipped; NC
  `workflowengine` enabled; n8n available for execution-heavy workflows.
- **Risk**: Medium — automation authoring moves out of Pipelinq; existing
  automation rules need a follow-up re-creation in NC Flow / n8n.

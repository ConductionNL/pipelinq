# Design: migrate-automation-to-flow-leaf
<!-- status: pr-created -->

## Architecture

The bespoke automation engine is replaced by the OpenRegister **flow leaf**
(`integration-flow`) wrapping the NC **workflowengine** (Flow), with **n8n** as
the execution backend for rich orchestration.

```
NC Flow (workflowengine)   authors + fires rules on events
        │                                  │ rich orchestration
[ flow leaf ]                              ▼
   CnFlowTab / CnFlowCard               [ n8n ] (existing integration)
        │ link-table (rules ↔ schema/object) + read-time fire events
        ▼
lead / request / client    object-level visibility of wired rules + recent fires
```

The leaf provides:
- `FlowService` + `FlowController` — read NC Flow rules + events scoped to
  schema/object.
- `FlowProvider` (registered in the integration registry).
- `CnFlowTab` — linked flow rules with last-fire timestamp + a "recent events"
  panel.
- `CnFlowCard` widget — 4 surfaces, workflow-focused.
- Link table (flow rules linked to schema/object) + read-time aggregation from
  NC Flow events.

## Why flow + n8n (alignment)

The existing `crm-workflow-automation` design already references `webhookUrl`,
`n8nWorkflowId`, and `fire_webhook` actions — automation was always meant to
execute against n8n. The flow leaf supplies the **object-level visibility** layer
(which rules are wired, what fired) that the bespoke `AutomationBuilder` tried to
build; **n8n** remains the execution engine for orchestration-heavy workflows,
reached via the existing n8n integration rather than a pipelinq webhook service.

## What Pipelinq owns after migration

1. `linkedTypes: ["flow", ...]` on `lead`, `request`, `client`.
2. Manifest placement (ADR-024): `CnFlowTab` in detail sidebars; `CnFlowCard`
   widget on detail pages (+ optional dashboard).
3. `workflowengine` in manifest `dependencies[]`.

## Removed

| Bespoke artefact | Replaced by |
|---|---|
| `src/views/automations/AutomationBuilder.vue` | NC Flow admin UI / n8n authoring |
| webhook-firing / DMN automation service | NC Flow rules + n8n execution |
| automation controller / routes | leaf `FlowController` (read-only visibility) |
| `automation` / `automationLog` schemas | NC Flow rules + leaf-read fire events |

## Existing-data migration (follow-up, NOT in this change)

Existing `automation` rules and `automationLog` history do not move
automatically. Each active rule needs re-creating as an NC Flow rule (and/or n8n
workflow). This is a separate follow-up so the migration stays bounded
(ADR-032). The maintainer SHOULD open a tracking issue at apply time per the
team's "file issues for deferred work" convention.

## Risks

- Medium. Automation authoring leaves Pipelinq; admins author in NC Flow / n8n.
- Existing automation rules are inert until re-created; flagged above.
- The flow leaf shows rules only when `workflowengine` is enabled; the tab/widget
  degrade gracefully when it is disabled.

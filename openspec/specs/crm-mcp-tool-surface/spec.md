---
status: in-progress
---

# Spec: CRM MCP Tool Surface

**OpenSpec changes**: [crm-mcp-tool-surface](../../changes/crm-mcp-tool-surface/) _(in-progress)_

## Purpose

Defines the agent-addressable CRM tool surface exposed by Pipelinq's MCP provider
(`OCA\Pipelinq\Mcp\PipelinqToolProvider`) to the Nextcloud Hub Assistant / AI Chat
Companion (ADR-034, ADR-035). Beyond the original 2-tool MVP (list/get request), the
provider exposes RBAC-scoped read tools for clients (incl. a 360 summary), leads, and
the pipeline forecast, plus RBAC-guarded write tools to create a lead and log a
contactmoment. Every read is scoped through OpenRegister's `ObjectService` with RBAC
enabled; every write goes through the app's existing write path (`ObjectService`
/`TicketService`) with `create` authorization enforced. This is Pipelinq's sovereign
AI wedge: bring-your-own-LLM through Nextcloud's Assistant at no per-seat AI premium.

**Standards**: Model Context Protocol (MCP); OpenRegister `IMcpToolProvider`
**Primary feature tier**: V1

## Requirements

### Requirement: MCP provider exposes a CRM read tool surface

The Pipelinq MCP provider SHALL expose agent-addressable read tools for clients,
leads, and the sales pipeline in addition to the existing request tools. Every read
tool SHALL resolve its objects through OpenRegister's `ObjectService` with RBAC
enabled, so only objects the caller may read are returned; a permission denial SHALL
be surfaced as a `forbidden` error envelope and MUST NOT be swallowed into an empty
success result.

#### Scenario: Get client returns a 360 summary
- **WHEN** the assistant invokes `pipelinq.getClient` with a client id
- **THEN** the provider returns the client plus a `summary` (open-ticket count across all `ticketType`s, open-lead count + total pipeline value, recent contactmomenten), RBAC-scoped

#### Scenario: Pipeline forecast summary
- **WHEN** the assistant invokes `pipelinq.pipelineForecast`
- **THEN** the provider returns per-stage rows over RBAC-visible open leads with lead count, summed value, and summed weighted value, plus a grand total

### Requirement: MCP provider exposes RBAC-guarded CRM write tools

The provider SHALL expose write tools to create a lead and log a contactmoment, each
going through the app's existing OpenRegister write path so `create` authorization is
enforced. Argument validation SHALL run before authorization, which SHALL run before
the write.

#### Scenario: Create lead with required fields
- **WHEN** the assistant invokes `pipelinq.createLead` with at least a `title`
- **THEN** a new `lead` is written via `ObjectService->saveObject` with RBAC enforced and returned with its server-computed `qualificationScore`

#### Scenario: Write denied by RBAC
- **WHEN** a write tool is invoked by a caller lacking `create` permission
- **THEN** the provider returns a `forbidden` envelope rather than reporting success

The full requirements and scenarios are maintained in the change delta at
[`changes/crm-mcp-tool-surface/specs/crm-mcp-tool-surface/spec.md`](../../changes/crm-mcp-tool-surface/specs/crm-mcp-tool-surface/spec.md)
and are folded into this spec on archive.

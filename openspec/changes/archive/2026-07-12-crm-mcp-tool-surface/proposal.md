---
kind: code
depends_on: []
---

# Proposal: crm-mcp-tool-surface

## Why

Pipelinq already ships an MCP provider (`lib/Mcp/PipelinqToolProvider.php`) but it
exposes only **two** read-only tools (list requests, get a single request), so the
Nextcloud Hub Assistant / AI Chat Companion (ADR-034) cannot drive the CRM surface
that matters to a sales or KCC agent — clients, leads, pipeline, contactmomenten.
A native, agent-addressable CRM tool surface is 2026 table-stakes (Twenty 2.0 ships
a native MCP server; Zoho's Zia Agent Marketplace; the competitor sweep flagged "no
native AI copilot" as a critical gap) and it is the sovereign wedge for Pipelinq:
bring-your-own-LLM through Nextcloud's Assistant at **no per-seat AI premium**, where
Salesforce/HubSpot/Zoho charge USD 100–330 per seat for the equivalent. ADR-035 makes
per-app MCP coverage a fleet expectation, and the provider's own docblock names
`ConductionNL/pipelinq#342` as the tracked full-surface follow-up.

## What Changes

- Extend `PipelinqToolProvider` from 2 tools to a fuller CRM tool set, keeping the
  provider's existing shape (constant tool descriptors, `invokeTool` dispatch,
  structured error envelopes, RBAC-through-ObjectService authorization).
- **New read tools:**
  - `pipelinq.listClients` / `pipelinq.searchClients` — list/search clients (RBAC-scoped).
  - `pipelinq.getClient` — a single client with a **360 summary**: open tickets count,
    open leads/deals, recent contactmomenten (reuse `ActivityTimelineService`).
  - `pipelinq.listLeads` / `pipelinq.searchLeads` — list/search leads with stage/status filters.
  - `pipelinq.getLead` — a single lead including its computed `qualificationScore`,
    `weightedValue`, `winProbability` and activity timeline.
  - `pipelinq.pipelineForecast` — per-stage totals and probability-weighted value across open leads.
- **New write tools** (RBAC-through-ObjectService, `create` authorization enforced):
  - `pipelinq.createLead` — create a lead (via the `lead` schema).
  - `pipelinq.logContactmoment` — log an interaction as a `ticket` with
    `ticketType=contactmoment` (via `TicketService::save`).
- All writes reuse the same OpenRegister ObjectService / TicketService authorization
  path the existing tools use; no new bypass, no unconditional allow.

## Capabilities

### New Capabilities
- `crm-mcp-tool-surface`: the full agent-addressable CRM tool set exposed by Pipelinq's
  MCP provider (client/lead/pipeline/contactmoment read + write tools, RBAC-enforced).

### Modified Capabilities
<!-- none: the existing 2-tool MVP is not respecified; it is superseded within the new capability -->

## Impact

- **Code:** `lib/Mcp/PipelinqToolProvider.php` (extend), `tests/` (unit fixtures for the
  new tool catalogue + handlers). Reuses existing `TicketService`, `ActivityTimelineService`,
  and OpenRegister `ObjectService`; resolves the `client_schema` / `lead_schema` config keys
  the same way `TicketService` resolves `ticket_schema`.
- **Dependencies:** OpenRegister `IMcpToolProvider` + `ObjectService` (already consumed);
  no new external dependency.
- **Procest:** none directly; a future Procest MCP provider can mirror this shape.
- **Feature tier:** V1 (AI copilot enablement).

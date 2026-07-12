# CRM MCP Tool Surface

Exposes Pipelinq's CRM entities — clients, leads, the sales pipeline, and requests — as agent-addressable tools to the Nextcloud Hub Assistant / AI Chat Companion via OpenRegister's `IMcpToolProvider`. This is Pipelinq's sovereign AI wedge: bring-your-own-LLM through Nextcloud's Assistant, with no per-seat AI premium, where competitors (Salesforce, HubSpot, Zoho) charge USD 100–330 per seat for the equivalent AI copilot.

## Specs

- `openspec/specs/crm-mcp-tool-surface/spec.md`

## Features

### Request Tools (MVP)

The original two read-only tools: `pipelinq.listRequests` and `pipelinq.getRequest`, narrowing the unified `ticket` schema to the `request` subtype.

### Client Tools (V1)

- `pipelinq.listClients` — list clients, newest first, optionally filtered by type (person/organization)
- `pipelinq.searchClients` — free-text search over name/email, RBAC-scoped
- `pipelinq.getClient` — a single client plus a live 360 summary: open-ticket count (across all ticket types), open-lead count and total pipeline value, and the most recent contactmomenten (via `ActivityTimelineService`)

### Lead Tools (V1)

- `pipelinq.listLeads` — list leads, optionally filtered by status, pipeline stage, or client
- `pipelinq.searchLeads` — free-text search over title, contact name/email, and organisation
- `pipelinq.getLead` — a single lead including the backend-computed `qualificationScore` and `weightedValue`, a `winProbability` alias of the raw `probability` field, and its activity timeline

### Pipeline Forecast (V1)

`pipelinq.pipelineForecast` — per-stage totals over RBAC-visible open leads: lead count, summed value, summed probability-weighted value, plus a grand total, ordered by pipeline stage order.

### Write Tools (V1)

- `pipelinq.createLead` — create a lead (title required; client, value, source, assignee optional), RBAC `create` enforced, returns the created lead including its server-computed `qualificationScore`
- `pipelinq.logContactmoment` — log a client interaction as a `ticket` with `ticketType=contactmoment` via `TicketService::save`, returns the created ticket UUID

### RBAC and Error Handling (V1)

- Every read resolves through OpenRegister's `ObjectService` with RBAC left at its default (enabled); a permission denial surfaces as a `forbidden` error envelope, never an empty success
- Every write goes through the app's existing write path (`ObjectService`/`TicketService`), so `create` authorization is enforced identically to the UI
- Argument validation always runs before authorization, which always runs before the write
- Structured error envelopes: `invalid_arguments`, `not_configured`, `not_found`, `forbidden`, `unknown_tool`, `internal_error`
- The tool catalogue (`getTools()`) is always returned in full regardless of caller permissions; per-object authorization happens only at `invokeTool` time

### Planned (V2)

- `pipelinq.updateLead` / stage-transition tool (deferred from the MVP — create + read + log only)
- Win/loss folded into the pipeline forecast (currently open-pipeline only)
- Cross-app (Procest) MCP wiring

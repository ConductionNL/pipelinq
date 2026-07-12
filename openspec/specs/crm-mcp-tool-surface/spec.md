---
status: in-progress
---

# Spec: CRM MCP Tool Surface

**OpenSpec changes**: [crm-mcp-tool-surface](../../changes/archive/2026-07-12-crm-mcp-tool-surface/) _(archived 2026-07-12)_ · [mcp-provider-declarative-migration](../../changes/archive/2026-07-13-mcp-provider-declarative-migration/) _(archived 2026-07-13 — ADR-063 leaf migration; config-only, PHP provider surgery is a blocked follow-up)_

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

The Pipelinq CRM read tool surface (clients, leads, and the sales pipeline) SHALL
resolve its objects through OpenRegister's `ObjectService` with RBAC left at its
default (enabled), so only objects the calling user may read are returned; a
permission denial SHALL be surfaced as a `forbidden` error envelope and MUST NOT
be swallowed into an empty success result. Reads MAY be served by the hand-written
`PipelinqToolProvider` or, once OpenRegister's derived-tool engine ships, by the
OpenRegister-derived `pipelinq.{schema}.{search|get}` tools declared via
`x-openregister-mcp` (see the declarative-tool-surface requirement below) — the
RBAC guarantee is identical. For leads, `winProbability` SHALL be the schema's
**declarative** `x-openregister-calculations` field (the recency-decayed value
materialised by OpenRegister on read), NOT a tool-side alias of the raw
`probability` input; the provider SHALL NOT alias `probability` into
`winProbability` (pipelinq #381). `qualificationScore` and `weightedValue`
likewise SHALL be read as materialised, never recomputed.

#### Scenario: List clients returns only readable clients
- **WHEN** the assistant lists clients (`pipelinq.listClients` or, once declared, the derived `pipelinq.client.search`) with an optional `type` or `query` filter
- **THEN** the query runs through `ObjectService->findAll` with RBAC enabled
- **AND** returns at most the list cap of client summaries the caller may read, newest first, with a `count`

#### Scenario: Search clients by free text
- **WHEN** the assistant searches clients (`pipelinq.searchClients` or the derived `pipelinq.client.search`) with a `query` argument
- **THEN** the provider returns clients whose name/email/organisation match the query, RBAC-scoped, capped to the list cap

#### Scenario: Get client returns a 360 summary
- **WHEN** the assistant invokes `pipelinq.getClient` with a client `id`
- **THEN** the provider returns the client record plus a `summary` object containing: the count of open tickets for that client (across all `ticketType`s), the count of open leads and their total pipeline value, and the most recent contactmomenten (via `ActivityTimelineService`)
- **AND** a timeline aggregation failure degrades to an empty `recentContactmomenten` list without failing the whole read
- **AND** this enrichment is specific to the hand-written tool; a coarse derived `pipelinq.client.get` returns only the object plus its declarative calculated fields (re-exposing the 360 summary as its own tool is an open question tracked for the follow-up `plq-mcp-provider-surgery` code spec)

#### Scenario: Get client not found
- **WHEN** the requested client `id` does not resolve (or the caller may not read it)
- **THEN** the provider returns a `not_found` or `forbidden` error envelope, never a partial/empty client object presented as success

#### Scenario: List and get leads expose the declarative winProbability
- **WHEN** the assistant lists leads (`pipelinq.listLeads`, optionally filtered by `status`, `stage`, or `clientId`) or gets a lead by `id` (`pipelinq.getLead`) — or, once declared, invokes the derived `pipelinq.lead.search`/`pipelinq.lead.get`
- **THEN** the provider/derived read returns RBAC-scoped lead summaries (or the single lead) including the backend-computed `qualificationScore`, `weightedValue`, and the declarative recency-decayed `winProbability` calculated field, and — for the hand-written `getLead` — its activity timeline
- **AND** the provider does NOT overwrite `winProbability` with the raw `probability` input (the retired `decorateLead` alias behaviour), per pipelinq #381

#### Scenario: Pipeline forecast summary
- **WHEN** the assistant invokes `pipelinq.pipelineForecast`
- **THEN** the provider returns per-stage rows over open leads the caller may read, each with the stage name, lead count, summed `value`, and summed probability-weighted value, plus a grand total
- **AND** the aggregation reads only RBAC-visible leads

### Requirement: CRM CRUD tools are declared on the schema, not hand-coded

The Pipelinq CRM CRUD tool surface SHALL be **declared** via the `x-openregister-mcp`
dialect (ADR-063) on the `client`, `lead`, and `ticket` schemas rather than hand-coded in
`PipelinqToolProvider`. Each opted-in schema SHALL carry an `x-openregister-mcp` block
with `enabled: true` and a `tools` map declaring the coarse verbs OpenRegister derives
(`search`, `get`), each with a `scope` (`read`), a per-verb `description`, and optional
`filters`; write verbs (`create`/`update`/`delete`) SHALL be omitted or disabled where no
corresponding hand-written write existed. The derived tool ids SHALL follow
`pipelinq.{schema}.{verb}` and reuse the schema itself as inputSchema/outputSchema
(`structuredContent`). MCP annotation hints (`readOnlyHint`/`destructiveHint`/
`idempotentHint`) are advisory UX only; the authoritative access gate SHALL remain
OpenRegister RBAC enforced at invoke time.

#### Scenario: client schema declares a read-only derived surface
- **WHEN** the `client` schema's `x-openregister-mcp` is imported
- **THEN** it declares `enabled: true` with `search` and `get` tools scoped `read`
- **AND** declares no `create`/`update`/`delete` verb, because Pipelinq exposed no hand-written client write

#### Scenario: lead and ticket schemas declare derived reads while writes stay curated
- **WHEN** the `lead` and `ticket` schemas' `x-openregister-mcp` are imported
- **THEN** each declares `enabled: true` with `search` and `get` tools scoped `read`
- **AND** the derived `create` verb is left disabled, because the curated `createLead`/`logContactmoment` service tools remain the write path
- **AND** the `ticket` search filters include the `ticketType` discriminator so the `request`/`contactmoment`/`complaint` subtypes stay addressable

#### Scenario: derived reads carry the declarative lead calculations
- **WHEN** a derived lead read (`pipelinq.lead.get`/`pipelinq.lead.search`) resolves a lead
- **THEN** the returned object includes the schema's declarative `qualificationScore`, `weightedValue`, and `winProbability` calculated fields as materialised by OpenRegister
- **AND** the provider does not recompute or alias any of them

### Requirement: Hand-written service tools take precedence over derived tools

During and after migration, a retained hand-written tool SHALL take precedence over a
derived tool on tool-id collision (`hand-written > derived`, an explicit form of the
registry's first-wins). This SHALL permit a zero-downtime, schema-by-schema cutover: a
schema's `x-openregister-mcp` MAY be enabled while its hand-written CRUD tools still exist,
and the hand-written tools SHALL keep serving until they are removed in the follow-up code
change. Only `createLead`, `logContactmoment`, and `pipelineForecast` SHALL remain
Pipelinq-owned tools after migration.

#### Scenario: enabling a schema dialect does not break existing tools
- **WHEN** `x-openregister-mcp` is enabled on a schema whose hand-written CRUD tools are still present
- **THEN** both surfaces are available and no hand-written tool is shadowed or removed by the derivation
- **AND** the hand-written tool wins for any colliding tool id

#### Scenario: retained service tools survive the migration
- **WHEN** the migration completes and the derived-equivalent CRUD tools are removed
- **THEN** `createLead`, `logContactmoment`, and `pipelineForecast` remain as the only Pipelinq-owned tools (to be re-annotated `#[McpTool]` once OpenRegister's attribute support ships)

### Requirement: MCP provider exposes RBAC-guarded CRM write tools

The provider SHALL expose write tools to create a lead and log a contactmoment.
Each write SHALL go through the same OpenRegister write path used elsewhere in the
app (`ObjectService->saveObject` for leads, `TicketService::save` with
`ticketType=contactmoment` for contactmomenten), so OpenRegister's `create`
authorization is enforced. The provider SHALL NOT introduce any write path that
bypasses that authorization, and a denied write SHALL return a `forbidden`
envelope. Argument validation SHALL run before authorization, which SHALL run
before the write (cheap-before-expensive, then authorize-before-act).

#### Scenario: Create lead with required fields
- **WHEN** the assistant invokes `pipelinq.createLead` with at least a `title` and optionally `client`, `value`, `source`, `assignee`
- **THEN** the provider writes a new `lead` object through `ObjectService->saveObject` on the configured lead schema with RBAC enforced
- **AND** returns the created lead including its server-computed `qualificationScore`

#### Scenario: Create lead missing required title
- **WHEN** `pipelinq.createLead` is invoked without a `title`
- **THEN** the provider returns an `invalid_arguments` error envelope and writes nothing

#### Scenario: Log contactmoment as a ticket
- **WHEN** the assistant invokes `pipelinq.logContactmoment` with a `client`, `channel`, `title`, and optional `outcome`/`notes`
- **THEN** the provider writes a `ticket` with `ticketType=contactmoment` via `TicketService::save`, so date-time fields are normalised and the discriminator is forced
- **AND** returns the created ticket UUID

#### Scenario: Write denied by RBAC
- **WHEN** a write tool is invoked by a caller lacking `create` permission on the target schema
- **THEN** OpenRegister raises, and the provider maps it to a `forbidden` error envelope rather than reporting success

### Requirement: The tool catalogue is self-describing and stable

`getTools()` SHALL return the full catalogue of tool descriptors (id, name,
description, JSON input schema) regardless of caller permissions; per-object
authorization happens only at `invokeTool` time. Each new tool id SHALL be
namespaced under `pipelinq.` and MUST be assertable as a fixture by unit tests.

#### Scenario: Unknown tool id
- **WHEN** `invokeTool` is called with an id not in the catalogue
- **THEN** the provider returns an `unknown_tool` error envelope listing the available tool ids, and throws no exception

#### Scenario: Catalogue advertises every CRM tool
- **WHEN** `getTools()` is called
- **THEN** the returned catalogue includes the client, lead, pipeline-forecast, create-lead, and log-contactmoment tools alongside the pre-existing request tools, each with a valid `inputSchema`

## Notes

- **`winProbability`** is the lead schema's **declarative** `x-openregister-calculations`
  field — a recency-decayed calc (`materialise:false`) that OpenRegister materialises on
  read. It is NOT a tool-side alias of the raw `probability` input. _(Superseded by
  `mcp-provider-declarative-migration` / ADR-063, resolving pipelinq #381: the earlier
  `decorateLead` alias shadowed the declarative calc and is removed.)_ `qualificationScore`
  and `weightedValue` are likewise genuine backend calculations, read as materialised by
  OpenRegister rather than recomputed by the provider.
- **Follow-up (blocked):** `plq-mcp-provider-surgery` (kind: code, not yet created — no
  actionable OpenSpec change scaffold exists for it yet) will delete the 8
  derived-equivalent descriptors + handlers from `PipelinqToolProvider`
  (`listClients`/`searchClients`/`getClient`/`listLeads`/`searchLeads`/`getLead`/
  `listRequests`/`getRequest`), drop the `decorateLead()` `winProbability` alias, and
  annotate `createLead`/`logContactmoment`/`pipelineForecast` with `#[McpTool]`. It is
  gated on OpenRegister's `or-mcp-derived-tool-provider` and `or-mcp-tool-attribute`
  shipping (cross-repo predecessors the Hydra supervisor cannot resolve against Pipelinq
  issues — `depends_on` on this change is intentionally empty). Whether to re-expose the
  `getClient` 360 summary / `getLead` activity timeline as dedicated `#[McpTool]` tools is
  an open question tracked for that follow-up (see `mcp-provider-declarative-migration/design.md`
  Open Questions OQ1–OQ3).

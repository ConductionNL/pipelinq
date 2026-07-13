---
status: done
---

# Spec: CRM MCP Tool Surface

**OpenSpec changes**: [crm-mcp-tool-surface](../../changes/archive/2026-07-12-crm-mcp-tool-surface/) _(archived 2026-07-12)_ · [mcp-provider-declarative-migration](../../changes/archive/2026-07-13-mcp-provider-declarative-migration/) _(archived 2026-07-13 — ADR-063 leaf migration steps 1–2, config-only)_ · [plq-mcp-provider-surgery](../../changes/archive/2026-07-13-plq-mcp-provider-surgery/) _(archived 2026-07-13 — ADR-063 leaf migration steps 3–6, completes the migration)_

## Purpose

Defines the agent-addressable CRM tool surface Pipelinq exposes to the Nextcloud Hub
Assistant / AI Chat Companion (ADR-034, ADR-035) via OpenRegister's MCP tool registry
(ADR-063). Reads for clients, leads, and tickets (incl. the `request` subtype) are served
entirely by OpenRegister's schema-derived `pipelinq.{schema}.{search|get}` tools, declared
via `x-openregister-mcp` on the respective schemas. Three curated, non-CRUD operations —
create a lead, log a contactmoment, and the pipeline forecast — are `#[McpTool]`-attributed
methods on `LeadService`/`TicketService`, discovered by OpenRegister's `AttributeToolScanner`
via Pipelinq's `IMcpScannableServices::pipelinq` opt-in (`PipelinqScannableServices`).
Pipelinq ships no hand-written `IMcpToolProvider` of its own any more. Every read is scoped
through OpenRegister's `ObjectService` with RBAC enabled; every write goes through the app's
existing write path (`ObjectService`/`TicketService`) with `create` authorization enforced.
This is Pipelinq's sovereign AI wedge: bring-your-own-LLM through Nextcloud's Assistant at
no per-seat AI premium.

**Standards**: Model Context Protocol (MCP); OpenRegister `x-openregister-mcp` dialect, `SchemaDerivedToolProvider`, `#[McpTool]` attribute + `IMcpScannableServices` (ADR-063)
**Primary feature tier**: V1
## Requirements
### Requirement: MCP provider exposes a CRM read tool surface

The Pipelinq CRM read tool surface (clients, leads, and the sales pipeline) SHALL
resolve its objects through OpenRegister's `ObjectService` with RBAC left at its
default (enabled), so only objects the calling user may read are returned; a
permission denial SHALL be surfaced as a `forbidden` error envelope and MUST NOT
be swallowed into an empty success result. Reads SHALL be served exclusively by the
OpenRegister-derived `pipelinq.{schema}.{search|get}` tools declared via
`x-openregister-mcp` (see the declarative-tool-surface requirement below) — Pipelinq
ships no hand-written read tool of its own. For leads, `winProbability` SHALL be the
schema's **declarative** `x-openregister-calculations` field (the recency-decayed value
materialised by OpenRegister on read), NOT a tool-side alias of the raw
`probability` input; no Pipelinq code path SHALL alias `probability` into
`winProbability` (pipelinq #381, now fully resolved — the `decorateLead()` alias and
every hand-written read tool that called it are deleted). `qualificationScore` and
`weightedValue` likewise SHALL be read as materialised, never recomputed.

#### Scenario: List clients returns only readable clients
- **WHEN** the assistant lists clients via the derived `pipelinq.client.search` tool with
  an optional `type` or `query` filter
- **THEN** the query runs through OpenRegister's schema-derived read with RBAC enabled
- **AND** returns at most the derived tool's list cap of client summaries the caller may
  read

#### Scenario: Get client returns the object plus its declarative calculated fields
- **WHEN** the assistant invokes the derived `pipelinq.client.get` tool with a client `id`
- **THEN** the tool returns the client record plus any declarative
  `x-openregister-calculations` fields the `client` schema declares
- **AND** a client-360-summary style enrichment (open-ticket count, open-lead count/value,
  recent contactmomenten) is NOT part of this MCP surface (see Non-Goals in the
  `plq-mcp-provider-surgery` design — an open question, not silently dropped)

#### Scenario: Get client not found or denied
- **WHEN** the requested client `id` does not resolve, or the caller may not read it
- **THEN** the derived tool returns a `not_found` or `forbidden` error envelope, never a
  partial/empty client object presented as success

#### Scenario: List and get leads expose the declarative winProbability
- **WHEN** the assistant invokes the derived `pipelinq.lead.search` (optionally filtered by
  `status`, `stage`, or `client`) or `pipelinq.lead.get` tool
- **THEN** the tool returns RBAC-scoped lead data including the backend-computed
  `qualificationScore`, `weightedValue`, and the declarative recency-decayed
  `winProbability` calculated field
- **AND** no Pipelinq code overwrites `winProbability` with the raw `probability` input —
  there is no Pipelinq-owned lead-read code path left to do so (pipelinq #381)

#### Scenario: Pipeline forecast summary
- **WHEN** the assistant invokes `pipelinq.pipelineForecast`
- **THEN** `LeadService::pipelineForecast()` (`#[McpTool]`-attributed) returns per-stage
  rows over open leads the caller may read, each with the stage name, lead count, summed
  `value`, and summed probability-weighted `weightedValue`, plus a grand total
- **AND** the aggregation reads only RBAC-visible leads via `ObjectService`

### Requirement: CRM CRUD tools are declared on the schema, not hand-coded

The Pipelinq CRM CRUD tool surface SHALL be **declared** via the `x-openregister-mcp`
dialect (ADR-063) on the `client`, `lead`, and `ticket` schemas rather than hand-coded in
a per-app `IMcpToolProvider` implementation. Each opted-in schema SHALL carry an `x-openregister-mcp` block
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

`#[McpTool]`-attributed service methods (OpenRegister's `AttributeToolProvider`, ADR-063 chain 3) SHALL take precedence over a schema-derived tool on tool-id collision, and
SHALL be self-suppressed (not registered) on collision with any other provider's id — an explicit
form of the registry's first-wins, applied by OpenRegister at discovery time. Pipelinq's
three retained ids (`pipelinq.createLead`, `pipelinq.logContactmoment`,
`pipelinq.pipelineForecast`) SHALL be the only Pipelinq-owned MCP tools; each SHALL be
declared via `#[McpTool]` on a public method of a class Pipelinq lists in its
`IMcpScannableServices::pipelinq` DI-aliased opt-in (`PipelinqScannableServices`), not via a
hand-written `IMcpToolProvider` implementation — Pipelinq ships no `IMcpToolProvider`
implementation of its own any more.

#### Scenario: Retained tools are attribute-scanned, not hand-dispatched
- **WHEN** OpenRegister enumerates MCP tool providers
- **THEN** it resolves `IMcpScannableServices::pipelinq` to `PipelinqScannableServices`,
  which declares `LeadService` and `TicketService` as scannable
- **AND** reflection finds `#[McpTool]` on `LeadService::createLead()`,
  `LeadService::pipelineForecast()`, and `TicketService::logContactmoment()`, registering
  `pipelinq.createLead`, `pipelinq.pipelineForecast`, and `pipelinq.logContactmoment`
- **AND** none of these three ids collides with a derived `pipelinq.{client,lead,ticket}.
  {search,get}` id, so the collision/precedence policy is a discovery-time safety net here,
  not an active behaviour

#### Scenario: No hand-written IMcpToolProvider remains
- **WHEN** OpenRegister looks up the `IMcpToolProvider::pipelinq` DI alias
- **THEN** it resolves nothing (the alias is not registered) — `PipelinqToolProvider` and
  its registration were deleted once every tool it served was either superseded by a
  derived read or migrated to an attributed service method

### Requirement: MCP provider exposes RBAC-guarded CRM write tools

Pipelinq SHALL expose `#[McpTool]`-attributed methods to create a lead and log a
contactmoment. Each write SHALL go through the same OpenRegister write path used
elsewhere in the app (`LeadService::createLead()` calling `ObjectService->saveObject`
for leads, `TicketService::logContactmoment()` calling `TicketService::save()` with
`ticketType=contactmoment` for contactmomenten), so OpenRegister's `create`
authorization is enforced. Neither method SHALL introduce a write path that bypasses that
authorization, and a denied write SHALL return a `forbidden` envelope. Argument validation
SHALL run before authorization, which SHALL run before the write (cheap-before-expensive,
then authorize-before-act) — unchanged from the pre-migration hand-written tools, just
invoked in-process by OpenRegister's `AttributeToolProvider` instead of Pipelinq's own
`invokeTool()` dispatch.

#### Scenario: Create lead with required fields
- **WHEN** the assistant invokes `pipelinq.createLead` with at least a `title` and
  optionally `client`, `value`, `source`, `assignee`
- **THEN** `LeadService::createLead()` writes a new `lead` object through
  `ObjectService->saveObject` on the configured lead schema with RBAC enforced
- **AND** returns the created lead including its server-computed `qualificationScore` and
  the declarative `winProbability`, unmodified by any Pipelinq-side alias

#### Scenario: Create lead missing required title
- **WHEN** `pipelinq.createLead` is invoked with a blank/missing `title`
- **THEN** `LeadService::createLead()` returns an `invalid_arguments` error envelope and
  writes nothing

#### Scenario: Log contactmoment as a ticket
- **WHEN** the assistant invokes `pipelinq.logContactmoment` with a `client`, `channel`,
  and `title`, and optional `outcome`/`notes`
- **THEN** `TicketService::logContactmoment()` writes a `ticket` with
  `ticketType=contactmoment` via `TicketService::save()`, so date-time fields are
  normalised and the discriminator is forced
- **AND** returns the created ticket UUID

#### Scenario: Write denied by RBAC
- **WHEN** `pipelinq.createLead` or `pipelinq.logContactmoment` is invoked by a caller
  lacking `create` permission on the target schema
- **THEN** OpenRegister raises, and the attributed method maps it to a `forbidden` error
  envelope rather than reporting success

## Notes

- **`winProbability`** is the lead schema's **declarative** `x-openregister-calculations`
  field — a recency-decayed calc (`materialise:false`) that OpenRegister materialises on
  read. It is NOT a tool-side alias of the raw `probability` input. _(Superseded by
  `mcp-provider-declarative-migration` / ADR-063, resolving pipelinq #381: the earlier
  `decorateLead` alias shadowed the declarative calc and is removed.)_ `qualificationScore`
  and `weightedValue` are likewise genuine backend calculations, read as materialised by
  OpenRegister rather than recomputed by the provider.
- **Migration complete (2026-07-13):** `plq-mcp-provider-surgery` executed Migration Plan
  steps 3–6 of `mcp-provider-declarative-migration` — the 8 derived-equivalent descriptors
  + handlers (`listClients`/`searchClients`/`getClient`/`listLeads`/`searchLeads`/`getLead`/
  `listRequests`/`getRequest`) and the `decorateLead()` `winProbability` alias are deleted;
  `createLead`/`logContactmoment`/`pipelineForecast` are `#[McpTool]`-attributed; and
  `PipelinqToolProvider` itself is deleted (it carried zero tools once the CRUD reads were
  gone) — Pipelinq's sole MCP-tool-provider seam is now the `IMcpScannableServices::pipelinq`
  opt-in. **Open (unchanged, not blocking):** whether to re-expose the `getClient` 360
  summary / `getRequest`/`getLead` activity timeline as dedicated `#[McpTool]` tools remains
  an open question (`mcp-provider-declarative-migration/design.md` OQ2) — deliberately out of
  scope for both migration changes; those summaries stay available via the app UI /
  `ActivityTimelineService`/`Customer360SummaryService`, not MCP.

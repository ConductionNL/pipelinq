## ADDED Requirements

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

## MODIFIED Requirements

### Requirement: MCP provider exposes a CRM read tool surface

The Pipelinq CRM read tool surface (clients, leads, and the sales pipeline) SHALL resolve
its objects through OpenRegister's `ObjectService` with RBAC left at its default (enabled),
so only objects the calling user may read are returned; a permission denial SHALL be
surfaced as a `forbidden` error envelope and MUST NOT be swallowed into an empty success
result. Reads MAY be served by the hand-written provider or, once declared, by the
OpenRegister-derived tools — the RBAC guarantee is identical. For leads, `winProbability`
SHALL be the schema's **declarative** `x-openregister-calculations` field (the
recency-decayed value materialised by OpenRegister on read), NOT a tool-side alias of the
raw `probability` input; the provider SHALL NOT alias `probability` into `winProbability`.
`qualificationScore` and `weightedValue` likewise SHALL be read as materialised, never
recomputed.

#### Scenario: List clients returns only readable clients
- **WHEN** the assistant lists clients (`pipelinq.listClients` or derived `pipelinq.client.search`) with an optional `type` or `query` filter
- **THEN** the query runs through `ObjectService->findAll` with RBAC enabled
- **AND** returns at most the list cap of client summaries the caller may read, newest first, with a `count`

#### Scenario: Search clients by free text
- **WHEN** the assistant searches clients with a `query` argument
- **THEN** the provider returns clients whose name/email match the query, RBAC-scoped, capped to the list cap

#### Scenario: List and get leads expose the declarative winProbability
- **WHEN** the assistant lists leads (optionally filtered by `status`, `stage`, or `clientId`) or gets a lead by `id`
- **THEN** the provider returns RBAC-scoped lead summaries (or the single lead) including the backend-computed `qualificationScore`, `weightedValue`, and the declarative recency-decayed `winProbability` calculated field
- **AND** the provider does NOT overwrite `winProbability` with the raw `probability` input

#### Scenario: Pipeline forecast summary
- **WHEN** the assistant invokes `pipelinq.pipelineForecast`
- **THEN** the provider returns per-stage rows over open leads the caller may read, each with the stage name, lead count, summed `value`, and summed probability-weighted value, plus a grand total
- **AND** the aggregation reads only RBAC-visible leads

## ADDED Requirements

### Requirement: MCP provider exposes a CRM read tool surface

The Pipelinq MCP provider (`OCA\Pipelinq\Mcp\PipelinqToolProvider`) SHALL expose
agent-addressable read tools for the core CRM entities — clients, leads, and the
sales pipeline — in addition to the existing request tools. Every read tool SHALL
resolve its objects through OpenRegister's `ObjectService` with RBAC left at its
default (enabled), so only objects the calling user may read are returned. A
permission denial SHALL be surfaced as a `forbidden` error envelope and MUST NOT
be swallowed into an empty success result.

#### Scenario: List clients returns only readable clients
- **WHEN** the assistant invokes `pipelinq.listClients` with an optional `type` or `query` filter
- **THEN** the provider queries the configured `client` schema through `ObjectService->findAll` with RBAC enabled
- **AND** returns at most the MVP list cap of client summaries the caller may read, newest first, with a `count`

#### Scenario: Search clients by free text
- **WHEN** the assistant invokes `pipelinq.searchClients` with a `query` argument
- **THEN** the provider returns clients whose name/email/organisation match the query, RBAC-scoped, capped to the list cap

#### Scenario: Get client returns a 360 summary
- **WHEN** the assistant invokes `pipelinq.getClient` with a client `id`
- **THEN** the provider returns the client record plus a `summary` object containing: the count of open tickets for that client (across all `ticketType`s), the count of open leads and their total pipeline value, and the most recent contactmomenten (via `ActivityTimelineService`)
- **AND** a timeline aggregation failure degrades to an empty `recentContactmomenten` list without failing the whole read

#### Scenario: Get client not found
- **WHEN** the requested client `id` does not resolve (or the caller may not read it)
- **THEN** the provider returns a `not_found` or `forbidden` error envelope, never a partial/empty client object presented as success

#### Scenario: List and get leads
- **WHEN** the assistant invokes `pipelinq.listLeads` (optionally filtered by `status`, `stage`, or `clientId`) or `pipelinq.getLead` with an `id`
- **THEN** the provider returns RBAC-scoped lead summaries (or the single lead) including the backend-computed `qualificationScore`, `weightedValue`, and `winProbability` calculated fields, and — for `getLead` — its activity timeline

#### Scenario: Pipeline forecast summary
- **WHEN** the assistant invokes `pipelinq.pipelineForecast`
- **THEN** the provider returns per-stage rows over open leads the caller may read, each with the stage name, lead count, summed `value`, and summed probability-weighted value, plus a grand total
- **AND** the aggregation reads only RBAC-visible leads

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

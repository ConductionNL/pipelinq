# CRM MCP Tool Surface — Full Action Coverage Delta

**Spec refs**: `crm-mcp-tool-surface` (base spec, status done), ADR-063 (OpenRegister MCP registry: `x-openregister-mcp` dialect + `#[McpTool]` / `IMcpScannableServices`), hermiq `agent-capability-reach` (scope×reach grant model, `ToolReachResolver`), decidesk `lib/Mcp/` (fleet write-tool reference: gate → validate → act), companion change `mcp-reach-annotation` (openregister — reach pass-through)
**Standards**: Model Context Protocol (MCP) tool annotations; OpenRegister `x-openregister-mcp` dialect; OpenRegister `x-openregister-lifecycle` dialect

## MODIFIED Requirements

### Requirement: CRM CRUD tools are declared on the schema, not hand-coded

The Pipelinq CRM CRUD tool surface SHALL be **declared** via the `x-openregister-mcp`
dialect (ADR-063) on the `client`, `contact`, `lead`, `ticket`, `task`, `product`, and
`leadProduct` schemas rather than hand-coded in a per-app `IMcpToolProvider`
implementation. Each opted-in schema SHALL carry an `x-openregister-mcp` block with
`enabled: true` and a `tools` map declaring the verbs OpenRegister derives, each with a
`scope`, a per-verb `description`, a `reach` (see the scope-and-reach requirement), and
optional `filters`. The derived verb sets SHALL be:

- `client`: `search`, `get` (scope `read`) and `create`, `update` (scope `create`/`update`) — client writes are plain object saves with no app-side invariant
- `contact`: `search`, `get`, `create`, `update` (new block; filter `client` on `search`)
- `task`: `search`, `get`, `create`, `update` (new block; filters `type`, `status`, `clientId` on `search`)
- `lead`: `search`, `get` only — `create`/`update` SHALL stay disabled because the curated `pipelinq.createLead`/`pipelinq.updateLead` service tools are the write path (`stageEnteredAt` maintenance)
- `ticket`: `search`, `get` only — `create`/`update` SHALL stay disabled because ticket writes MUST pass `TicketService::save()` (date normalisation + `ticketType` discriminator) via the curated tools
- `product`: `search`, `get` only — the product master is not agent-writable
- `leadProduct`: `search`, `get`, `create` and additionally `update`, `delete` (the UI's `lead-lines` object-list already declares `allowEdit: true`; `delete` SHALL carry `destructiveHint: true`)

The derived tool ids SHALL follow `pipelinq.{schema}.{verb}` and reuse the schema itself
as inputSchema/outputSchema. MCP annotation hints remain advisory UX only; the
authoritative access gate SHALL remain OpenRegister RBAC enforced at invoke time. All
blocks SHALL survive the `register.d` fragment merge (the pipelinq #396 failure mode),
asserted by the existing merge-preservation unit tests extended to the new blocks and
verbs.

**Feature tier**: V1

#### Scenario: contact and task schemas gain full derived CRUD

`@e2e exclude` static assertion about imported register JSON plus tools derived and served over MCP JSON-RPC by OpenRegister — no app route, no browser surface; declaration + fragment-merge survival asserted by unit tests over `lib/Settings/pipelinq_register.json`.

- **WHEN** the `contact` and `task` schemas' `x-openregister-mcp` blocks are imported
- **THEN** OpenRegister derives `pipelinq.contact.{search,get,create,update}` and `pipelinq.task.{search,get,create,update}` with the declared scopes, reaches, and filters
- **AND** each write verb enforces OpenRegister validation (`required`, enums, formats) and RBAC exactly as the UI's own saves do

#### Scenario: leadProduct lines become correctable and removable

`@e2e exclude` MCP JSON-RPC derived write tools with no browser view — the UI edits lines through its own object-list path; the derived declaration is asserted by parsing the register JSON.

- **WHEN** the `leadProduct` block is imported with `update` and `delete` verbs added
- **THEN** `pipelinq.leadProduct.update` allows correcting `quantity`, `unitPrice`, `discount`, `discountType`, or `notes` on a line, with `total` still materialised by OpenRegister and never writable
- **AND** `pipelinq.leadProduct.delete` removes a line and carries `destructiveHint: true`

#### Scenario: lead and ticket derived writes stay disabled

`@e2e exclude` static register-JSON assertion (absence of `create`/`update` verbs on two blocks) — no runtime surface.

- **WHEN** the `lead` and `ticket` schemas' blocks are imported
- **THEN** each declares `search` and `get` only, and the curated service tools remain the sole write path for those types

---

#### Scenario: client schema declares a read-only derived surface
- **WHEN** the `client` schema's `x-openregister-mcp` is imported
- **THEN** it declares `enabled: true` with `search` and `get` tools scoped `read`
- **AND** it declares `create` and `update` scoped `create`/`update`, because client writes are plain object saves with no app-side invariant
- **AND** it declares no `delete` verb

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

`#[McpTool]`-attributed service methods (OpenRegister's `AttributeToolProvider`, ADR-063
chain 3) SHALL take precedence over a schema-derived tool on tool-id collision, and SHALL
be self-suppressed (not registered) on collision with any other provider's id. Pipelinq's
curated ids SHALL be exactly the following fourteen, each declared via `#[McpTool]` on a
public method of a class listed in the `IMcpScannableServices::pipelinq` opt-in
(`PipelinqScannableServices`, which SHALL continue to list only `LeadService` and
`TicketService`), never via a hand-written `IMcpToolProvider` implementation:

- On `LeadService`: `pipelinq.createLead`, `pipelinq.updateLead`, `pipelinq.winLead`, `pipelinq.loseLead`, `pipelinq.pipelineForecast`
- On `TicketService`: `pipelinq.logContactmoment`, `pipelinq.createTicket`, `pipelinq.updateTicket`, `pipelinq.startTicket`, `pipelinq.completeTicket`, `pipelinq.convertTicket`, `pipelinq.resolveTicket`, `pipelinq.rejectTicket`, `pipelinq.closeTicket`

Pipelinq SHALL ship no `IMcpToolProvider` implementation of its own.

**Feature tier**: V1

#### Scenario: The expanded curated set is attribute-scanned

`@e2e exclude` DI-wiring + PHP-reflection invariant at OpenRegister tool-discovery time; observable output is a tool registry, not a page. Asserted by reflection unit tests on the fourteen attributed methods and by the existing `PipelinqScannableServicesTest`.

- **WHEN** OpenRegister enumerates MCP tool providers
- **THEN** reflection over `LeadService` and `TicketService` registers exactly the fourteen curated ids listed above
- **AND** none collides with a derived `pipelinq.{schema}.{verb}` id, so the precedence rule remains a discovery-time safety net

---

#### Scenario: Retained tools are attribute-scanned, not hand-dispatched

`@e2e exclude` a DI-wiring + PHP-reflection invariant that runs at OpenRegister's tool-discovery time, entirely outside any HTTP request a browser makes — the observable output is an MCP tool registry, not a page. Asserted by tests/Unit/Mcp/PipelinqScannableServicesTest.php (testGetScannableServiceClassesDeclaresLeadAndTicketServices) for the `IMcpScannableServices::pipelinq` opt-in, and by the reflection assertions that the three ids exist on the scanned methods: tests/Unit/Service/LeadServiceTest.php (testCreateLeadHasMcpToolAttribute, testPipelineForecastHasMcpToolAttribute) and tests/Unit/Service/TicketServiceTest.php (testLogContactmomentHasMcpToolAttribute).

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

`@e2e exclude` a code-absence + DI-registration assertion, verified by inspection rather than by a browser: no `PipelinqToolProvider` class exists under `lib/` or `src/` (the name survives only in migration comments in `lib/Service/LeadService.php` and `lib/Service/TicketService.php`), and `lib/AppInfo/Application.php` contains no `IMcpToolProvider::pipelinq` registration at all — the string appears there only inside the block comment recording the retirement. The interface itself is referenced nowhere in `lib/` except that comment and a doc comment in `lib/Mcp/PipelinqScannableServices.php`; the remaining occurrences are the standalone-unit-test stub `tests/Stubs/Mcp/IMcpToolProvider.php` and the three test bootstraps that load it. Resolving (or failing to resolve) a DI alias inside another app produces no HTTP response and no rendered surface.

- **WHEN** OpenRegister looks up the `IMcpToolProvider::pipelinq` DI alias
- **THEN** it resolves nothing (the alias is not registered) — `PipelinqToolProvider` and
  its registration were deleted once every tool it served was either superseded by a
  derived read or migrated to an attributed service method

### Requirement: MCP provider exposes RBAC-guarded CRM write tools

Pipelinq SHALL expose `#[McpTool]`-attributed methods for every curated CRM write. Each
write SHALL go through the same OpenRegister write path used elsewhere in the app —
`LeadService::createLead()`/`updateLead()` calling `ObjectService->saveObject` (with
`updateLead` setting `stageEnteredAt` whenever `stage` changes, so the register's
declarative `daysInStage` calculation stays truthful), and
`TicketService::createTicket()`/`updateTicket()`/`logContactmoment()` calling
`TicketService::save()` so date-time fields are normalised and the `ticketType`
discriminator is forced (`createTicket` SHALL accept subtypes `request` and `complaint`
only; contactmomenten remain `pipelinq.logContactmoment`'s job). Every curated write
SHALL follow the fleet write-tool discipline (decidesk `lib/Mcp/` reference:
`McpArgumentValidator`-style cheap argument validation first, then authorization, then
the act): argument validation SHALL run before authorization, authorization before the
write, and a denied write SHALL return a `forbidden` envelope, an invalid call an
`invalid_arguments` envelope, a missing object a `not_found` envelope — never a partial
or empty success.

**Feature tier**: V1

#### Scenario: Update lead maintains the stage-entry invariant

`@e2e exclude` MCP JSON-RPC write tool invoked in-process by OpenRegister's `AttributeToolProvider`; no route targets `LeadService::updateLead()` and no view calls it. Asserted by unit tests on `LeadService` (stage change sets `stageEnteredAt`; non-stage update leaves it untouched; RBAC denial maps to `forbidden`).

- **WHEN** the assistant invokes `pipelinq.updateLead` with a lead `id` and a changed `stage`
- **THEN** `LeadService::updateLead()` writes through `ObjectService->saveObject` with RBAC enforced and sets `stageEnteredAt` to the write time
- **AND** an update that does not change `stage` SHALL NOT touch `stageEnteredAt`

#### Scenario: Create a request or complaint ticket

`@e2e exclude` MCP JSON-RPC write tool with no browser view; asserted by unit tests on `TicketService::createTicket()` (discriminator forced, dates normalised, `contactmoment` subtype rejected as `invalid_arguments`).

- **WHEN** the assistant invokes `pipelinq.createTicket` with `ticketType` `request` or `complaint`, a `title`, and optional `client`, `description`, `priority`, `queue`
- **THEN** `TicketService::createTicket()` writes the ticket via `TicketService::save()` with the discriminator forced and returns the created ticket UUID
- **AND** `ticketType` `contactmoment` SHALL be rejected with `invalid_arguments` pointing the caller at `pipelinq.logContactmoment`

#### Scenario: Create lead with required fields

`@e2e exclude` MCP JSON-RPC write tool — `pipelinq.createLead` is invoked in-process by OpenRegister's `AttributeToolProvider`; no entry in `appinfo/routes.php` targets `LeadService::createLead()` (the similarly named `prospect#createLead` route is `ProspectController::createLead`, a different method on the prospect-discovery path) and no control in any view calls it, so a browser cannot reach this tool (creating a lead THROUGH THE UI is a different code path, covered by the lead-management e2e specs). Asserted by tests/Unit/Service/LeadServiceTest.php (testCreateLeadWritesLeadAndReturnsMaterialisedCalculations — the write goes through `ObjectService->saveObject` on the configured lead schema and the returned lead carries the materialised `qualificationScore` / `winProbability` unaliased).

- **WHEN** the assistant invokes `pipelinq.createLead` with at least a `title` and
  optionally `client`, `value`, `source`, `assignee`
- **THEN** `LeadService::createLead()` writes a new `lead` object through
  `ObjectService->saveObject` on the configured lead schema with RBAC enforced
- **AND** returns the created lead including its server-computed `qualificationScore` and
  the declarative `winProbability`, unmodified by any Pipelinq-side alias

#### Scenario: Create lead missing required title

`@e2e exclude` argument-validation invariant on an MCP tool with no browser view — the assertion is the `invalid_arguments` envelope returned to the MCP caller plus the absence of a write, and no page can submit a blank title to this method (the UI's own lead form is a separate path with its own validation). Asserted by tests/Unit/Service/LeadServiceTest.php (testCreateLeadWithoutTitleReturnsInvalidArguments).

- **WHEN** `pipelinq.createLead` is invoked with a blank/missing `title`
- **THEN** `LeadService::createLead()` returns an `invalid_arguments` error envelope and
  writes nothing

#### Scenario: Log contactmoment as a ticket

`@e2e exclude` MCP JSON-RPC write tool — `pipelinq.logContactmoment` is invoked in-process by OpenRegister's `AttributeToolProvider`; no entry in `appinfo/routes.php` targets `TicketService::logContactmoment()` and no control in any view calls it. Asserted by tests/Unit/Service/TicketServiceTest.php (testLogContactmomentWritesTicketAndReturnsUuid — written through `TicketService::save()` with the `ticketType=contactmoment` discriminator forced and the created UUID returned; testLogContactmomentMissingClientReturnsInvalidArguments, testLogContactmomentMissingChannelReturnsInvalidArguments, testLogContactmomentMissingTitleReturnsInvalidArguments cover the three required arguments).

- **WHEN** the assistant invokes `pipelinq.logContactmoment` with a `client`, `channel`,
  and `title`, and optional `outcome`/`notes`
- **THEN** `TicketService::logContactmoment()` writes a `ticket` with
  `ticketType=contactmoment` via `TicketService::save()`, so date-time fields are
  normalised and the discriminator is forced
- **AND** returns the created ticket UUID

#### Scenario: Write denied by RBAC

`@e2e exclude` a denial path on an MCP tool with no browser view, and one that additionally needs a second Nextcloud account holding no `create` grant on the target schema — the CI instance provisions exactly one user (tests/e2e/ci-seed.sh creates none beyond the admin the workflow installs with), so the denial cannot be provoked from a session at all. Asserted by tests/Unit/Service/LeadServiceTest.php (testCreateLeadDeniedByRbacReturnsForbidden) and tests/Unit/Service/TicketServiceTest.php (testLogContactmomentDeniedByRbacReturnsForbidden).

- **WHEN** `pipelinq.createLead` or `pipelinq.logContactmoment` is invoked by a caller
  lacking `create` permission on the target schema
- **THEN** OpenRegister raises, and the attributed method maps it to a `forbidden` error
  envelope rather than reporting success

## ADDED Requirements

### Requirement: Every tool declares scope and reach for the grant matrix

Every Pipelinq MCP tool — derived and curated — SHALL declare both grant axes hermiq's
per-agent grant model consumes: a CRUD `scope` (`read`/`create`/`update`/`delete`) and a
`reach` (`self`/`user`/`instance`/`external`, per hermiq's `ToolReachResolver` ordering).
Pipelinq's declarations SHALL be: `reach: user` on every read tool (a read changes
nothing and discloses to nobody beyond what the caller may already read) and
`reach: instance` on every write tool (the written object is visible to other users of
the instance); no Pipelinq tool SHALL declare `self` or `external`. Derived tools SHALL
carry `reach` in their `x-openregister-mcp` tool entries and curated tools in their
`#[McpTool]` attributes; pass-through into the served descriptors is the companion
change `mcp-reach-annotation` (openregister), and pipelinq SHALL NOT build its own
pass-through. Until the companion lands, the declarations SHALL be present but inert:
hermiq infers `user`/`instance` for the three-segment derived ids and resolves the
undeclared two-segment curated ids to `external` — fail-closed, which SHALL be accepted
as the interim classification rather than worked around. Read tools SHALL be free of
side effects so that granting `scope: read` alone can never mutate CRM state.

**Feature tier**: V1

#### Scenario: Declarations match the read/write split

`@e2e exclude` static assertion over register JSON + PHP attributes (a conformance unit test), no runtime surface.

- **WHEN** the conformance test enumerates every `x-openregister-mcp` tool entry and every `#[McpTool]` attribute
- **THEN** every `scope: read` tool declares `reach: user` and `readOnlyHint: true`, and every `create`/`update`/`delete` tool declares `reach: instance`
- **AND** no tool declares `reach: self` or `reach: external`

#### Scenario: Curated tools stop classifying as external once pass-through lands

`@e2e exclude` hermiq-side descriptor classification of an MCP registry — no pipelinq surface; verified against hermiq's `ToolReachResolver::resolve()` with the post-companion descriptor.

- **GIVEN** the companion `mcp-reach-annotation` is deployed
- **WHEN** hermiq resolves the reach of `pipelinq.createLead` or `pipelinq.winLead`
- **THEN** the declared `instance` reach is read from the descriptor, replacing the fail-closed `external` inference for two-segment ids
- **AND** an agent granted `create`-scope at `instance` reach can be offered the tool without an `external`-tier grant

---

### Requirement: Lifecycle transitions are curated tools, one per action

Every lifecycle transition the register declares SHALL be invocable as exactly one
curated tool, and lifecycle state SHALL NOT be writable any other way on the MCP surface
(`status` SHALL be rejected as an argument by `pipelinq.updateLead` and
`pipelinq.updateTicket` with `invalid_arguments`). The tools and their register-declared
authorizations (`x-openregister-lifecycle` in `lib/Settings/pipelinq_register.json` and
`lib/Settings/register.d/99-unify-ticket-supertype.json`) are:

- `pipelinq.winLead` (`open → won`) and `pipelinq.loseLead` (`open → lost`) — assignee or `sales` group
- `pipelinq.startTicket` (`new → in_progress`) and `pipelinq.rejectTicket` (`new|in_progress → rejected`) — assignee or `sales` group
- `pipelinq.completeTicket` (`in_progress → completed`), `pipelinq.convertTicket` (`in_progress → converted`), `pipelinq.resolveTicket` (`in_progress → resolved`), `pipelinq.closeTicket` (`new|in_progress → closed`) — assignee (per-object RBAC)

Each tool SHALL be scope `update`, reach `instance`, `idempotentHint: true`, and
`destructiveHint: true` where the target state is in the lifecycle's `final` set. Each
SHALL drive the same OpenRegister lifecycle path the manifest's `lifecycleActions` uses,
so `from`-state guards and per-transition authorization are enforced by the machinery
that owns them. A transition attempted from a disallowed state SHALL return a `conflict`
envelope (distinguishable from `forbidden`), and an authorization failure a `forbidden`
envelope.

**Feature tier**: V1

#### Scenario: Close the deal as won from chat

`@e2e exclude` MCP JSON-RPC write tool invoked in-process; no route or view reaches the transition methods (the UI's `lifecycleActions` buttons are a different, e2e-covered path). Asserted by unit tests: allowed transition writes `won`; wrong-state returns `conflict`; non-sales non-assignee returns `forbidden`.

- **GIVEN** an open lead and a caller who is the assignee or in the `sales` group, with an agent write grant covering `pipelinq.winLead`
- **WHEN** the user says "close the Acme deal as won" and the agent invokes `pipelinq.winLead` with the lead `id` (behind hermiq's approval gate)
- **THEN** the lead transitions `open → won` through the register's lifecycle path
- **AND** invoking `pipelinq.winLead` on the now-won lead returns `conflict`, not a second transition

#### Scenario: Ticket transition authorization comes from the register

`@e2e exclude` same in-process MCP path; per-transition authorization asserted by `TicketService` unit tests against the lifecycle declarations.

- **WHEN** a caller who is neither the ticket's assignee nor in `sales` invokes `pipelinq.rejectTicket`
- **THEN** the tool returns `forbidden` without writing
- **AND** the same caller as assignee invoking `pipelinq.completeTicket` on an `in_progress` request-ticket succeeds

#### Scenario: Status is not writable through update tools

`@e2e exclude` argument-validation invariant on MCP tools with no browser surface; asserted by unit tests (`updateLead`/`updateTicket` reject a `status` argument).

- **WHEN** `pipelinq.updateLead` or `pipelinq.updateTicket` is invoked with a `status` argument
- **THEN** the tool returns `invalid_arguments` naming the matching transition tools, and writes nothing

---

### Requirement: The CRM core action surface is chat-commandable

The tool surface SHALL cover the CRM core's user actions end to end, so that a user can
command the app from chat — with automation optional and every write behind hermiq's
default-deny grant and approval gate. Read flows SHALL be completable with `read`-scope
grants alone; write flows SHALL compose the read tools (to resolve names to ids) with
exactly one write tool per performed action. The derived lead reads SHALL continue to
expose the register's declarative calculations (`daysSinceActivity`, `daysInStage`,
`weightedValue`, `winProbability`, `qualificationScore`) as materialised by OpenRegister,
which is what makes staleness and forecast questions answerable without any new
aggregation tool.

**Feature tier**: V1

#### Scenario: Which of my leads went stale this month

`@e2e exclude` MCP JSON-RPC read flow through OpenRegister's registry — no pipelinq route or view; the calculation is materialised by OpenRegister (`lead.configuration.x-openregister-calculations.daysSinceActivity`), covered by the register declaration and OR's own suite.

- **GIVEN** an agent holding only `read`-scope grants for pipelinq
- **WHEN** the user asks "which of my leads went stale this month?" and the agent invokes `pipelinq.lead.search` filtered by `status: open`
- **THEN** the RBAC-scoped results carry the materialised `daysSinceActivity` field, letting the agent report the stale leads without any write grant or approval prompt

#### Scenario: Log a call and create a follow-up task

`@e2e exclude` MCP JSON-RPC write flow (two in-process tools plus hermiq's approval UX, which is hermiq's surface, not pipelinq's). Pipelinq's halves are asserted by the `logContactmoment` unit tests and the derived `task.create` declaration.

- **GIVEN** an agent granted `create` scope at `instance` reach for `pipelinq.logContactmoment` and `pipelinq.task.create`
- **WHEN** the user says "log my call with Jansen BV and create a follow-up task for Friday" and the agent resolves the client via `pipelinq.client.search`, then invokes `pipelinq.logContactmoment` (channel `telefoon`) and `pipelinq.task.create` (`type: followUpTask`, `deadline` set, `clientId` linked)
- **THEN** each write passes hermiq's approval gate individually and lands through the app's standard write paths
- **AND** an agent without those write grants can complete no part of the flow beyond the read

#### Scenario: Build a quote line from chat

`@e2e exclude` MCP JSON-RPC flow over derived tools (`product.search` guides the agent to a real product id and list `unitPrice`; `leadProduct.create` has `total` materialised by OpenRegister) — no pipelinq code on either path; declarations asserted by register parsing.

- **WHEN** the user says "put 10 seats of Product X on the Acme deal" and the agent invokes `pipelinq.product.search`, then `pipelinq.leadProduct.create` with the resolved `lead`, `product`, `quantity`, and the product's `unitPrice`
- **THEN** the line is created with `total` calculated by OpenRegister, and a supplied `total` would be overwritten per the existing block's description

---

### Requirement: Excluded surfaces are never agent-invocable

The following Pipelinq surfaces SHALL NOT be exposed as MCP tools, derived or curated:
the BRP/BSN schemas (`bsnValidatie`, `brpLookupVerzoek`, `brpPersoon`, `bsnAuditRecord`,
`optOutVlag` — statutory personal-data processing under BRP audit obligations), the POS
schemas (`posTransaction`, `posTransactionLine`, `posRefund`, `posRefundLine`, `posRole`,
`posStaff`, `receiptTemplate`, `receiptPrintLog`, `refundReason`, `paymentProvider` —
cash handling and fiscal records), marketing-blast dispatch (bulk external sends are
`reach: external` by definition and keep their own consent gating), and portal
administration. Conformance SHALL be asserted as an allow-list, not a deny-list: a unit
test SHALL fail if any schema outside `client`, `contact`, `lead`, `ticket`, `task`,
`product`, `leadProduct` carries an `x-openregister-mcp` block, or if any `#[McpTool]`
attribute exists outside `LeadService` and `TicketService`.

**Feature tier**: V1

#### Scenario: A new schema cannot silently join the tool surface

`@e2e exclude` static allow-list conformance over register JSON + PHP attributes — a unit test with no runtime surface; the test must first be shown able to fail (temporarily opt in an excluded schema locally).

- **WHEN** a future change adds an `x-openregister-mcp` block to a schema outside the allow-list, or a `#[McpTool]` attribute outside the two scannable services
- **THEN** the conformance test SHALL fail naming the schema or class
- **AND** widening the tool surface SHALL require editing the allow-list in the same reviewed diff

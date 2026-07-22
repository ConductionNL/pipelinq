# Design: crm-mcp-tool-surface

## Context

`lib/Mcp/PipelinqToolProvider.php` implements OpenRegister's `IMcpToolProvider`
(from `ai-chat-companion-orchestrator`) and today ships exactly two read-only
tools (`pipelinq.listRequests`, `pipelinq.getRequest`), each narrowing the unified
`ticket` schema to `ticketType=request`. The provider already injects
`TicketService` (register + ticket schema resolver, RBAC-safe read/write),
`ContainerInterface` (for OpenRegister `ObjectService`), `ActivityTimelineService`,
and a PSR-3 logger. Its authorization model is: argument validation first, then
per-object RBAC through `ObjectService` (default enabled), with a
`mapServiceException` seam turning a permission error into a `forbidden` envelope.
The provider docblock names `ConductionNL/pipelinq#342` as the tracked full-surface
follow-up. This change delivers that surface.

## Goals / Non-Goals

**Goals:**
- Give the Nextcloud Assistant a useful CRM tool set: clients (list/search/get-360),
  leads (list/search/get), pipeline forecast, create-lead, log-contactmoment.
- Preserve the provider's existing shape and authorization guarantees exactly —
  no new RBAC bypass, no unconditional allow, structured error envelopes throughout.
- Reuse existing collaborators (`TicketService`, `ActivityTimelineService`,
  `ObjectService`) and resolve the `client_schema` / `lead_schema` config keys the
  same way `TicketService` resolves `ticket_schema` (via `IAppConfig`).

**Non-Goals:**
- No new OpenRegister schemas and no schema field changes (the tools operate on the
  existing `client`, `lead`, and `ticket` schemas).
- No write tools beyond create-lead and log-contactmoment (no destructive/update
  tools in this MVP — update/delete via MCP is deferred).
- No cross-app (Procest) MCP wiring.

## Decisions

- **Extend, don't replace.** New tool descriptors are appended to the
  `TOOL_DESCRIPTORS` constant; `invokeTool` gains one dispatch branch per tool,
  each delegating to a private `handle*` method mirroring `handleListRequests` /
  `handleGetRequest`. This keeps the catalogue assertable as a unit-test fixture.
- **Client/lead schema resolution.** Add small resolver helpers that read the
  `client_schema` and `lead_schema` app-config keys (present in
  `SettingsService::SCHEMA_SLUGS`) exactly like `TicketService::getSchemaId()`
  reads `ticket_schema`. An unconfigured schema returns a `not_configured`
  envelope, matching the existing `resolveRequestContext` behaviour.
- **360 summary is composed, not stored.** `getClient` computes its `summary`
  live from RBAC-visible reads: open-ticket count via
  `ObjectService->findAll` over `ticket` filtered by `client` (no `ticketType`
  narrowing, open statuses), open-lead count + summed `value` over `lead` filtered
  by `client` + `status=open`, and recent contactmomenten via
  `ActivityTimelineService`. A timeline failure degrades to an empty list (same
  best-effort pattern the existing `fetchTimeline` uses).
- **Pipeline forecast reads leads, groups by stage.** `pipelineForecast` reads
  RBAC-visible open leads, buckets by `stage`, and sums `value` and the
  probability-weighted value (the lead schema already materialises `weightedValue`
  = `value * probability / 100` via `x-openregister-calculations`, so the tool
  reads the computed field rather than recomputing). Result is capped and ordered
  by the pipeline stage order.
- **Writes reuse the app's write path.** `createLead` calls
  `ObjectService->saveObject` on the lead schema (RBAC `create` enforced,
  server-side `qualificationScore` materialises). `logContactmoment` calls
  `TicketService::save(TicketService::TYPE_CONTACTMOMENT, …)` so the discriminator
  is forced and date-time fields are normalised by `sanitizeForSave`.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| The MCP tool surface itself (list/get/create/log/forecast) | **Imperative** (PHP provider) | MCP is an **external API-surface adapter** — an integration boundary, exactly the class ADR-003/ADR-031 keep in PHP. There is no `x-openregister-*` extension that emits an MCP tool catalogue. |
| Lead qualification score / weighted value | **Declarative (already)** | The tools **read** the existing `x-openregister-calculations` on the lead schema (`qualificationScore`, `weightedValue`); they do not recompute them. |
| 360 summary counts + pipeline forecast per-stage totals | **Imperative (read-side aggregation in the tool)** | These are ad-hoc, RBAC-scoped, cross-object aggregations shaped for one LLM response. They are computed by reading RBAC-visible objects and reducing — not persisted. ADR-031 exception (2): a per-request aggregation shaped by the caller is a legitimate service concern, not a stored `x-openregister-aggregations` value. No new service class is introduced — the reduction lives inside the provider's private handler. |

No new `x-openregister-*` annotations are added; no new `*Service.php` is created.

## Seed Data

This change adds **no new schema**, so no `_registers.json` seed rows are required.
The unit tests instead assert the tool catalogue as a fixture and drive the handlers
against representative in-memory client/lead objects spanning the standard
organisation archetypes:

- **Municipality** — client `{ "name": "Gemeente Voorbeeld", "type": "organization",
  "industry": "public-sector" }`; lead `{ "title": "KCC self-service portal",
  "value": 45000, "source": "referral", "stage": "qualification", "status": "open" }`.
- **Consultancy** — client `{ "name": "Meridiaan Advies B.V.", "type": "organization",
  "industry": "consulting" }`; lead `{ "title": "Data-governance retainer",
  "value": 18000, "source": "partner", "stage": "proposal", "status": "open" }`.
- **Travel agency** — client `{ "name": "Zonnereizen", "type": "organization",
  "industry": "travel" }`; contactmoment ticket `{ "ticketType": "contactmoment",
  "channel": "telefoon", "title": "Booking change request", "outcome": "afgehandeld" }`.

Client UUIDs in test fixtures use the nil UUID
`00000000-0000-0000-0000-000000000000` rather than realistic-looking values.

## Risks / Trade-offs

- **RBAC correctness on aggregations** → Every read that feeds a count/summary goes
  through `ObjectService->findAll`/`find` with RBAC enabled; the aggregation reduces
  only what the caller may already read, so no count leaks the existence of hidden
  objects. Covered by a scenario.
- **Write tools broaden the attack surface** → Both writes go through the same
  `ObjectService`/`TicketService` path the UI uses; a denied `create` maps to a
  `forbidden` envelope. No update/delete tools are added in this MVP.
- **List-cap truncation could mislead an LLM** → Each list tool returns an explicit
  `count` and honours the existing MVP `LIST_CAP`; the descriptions state the cap so
  the model can page or narrow.

## Migration Plan

Purely additive — new tools appear in the catalogue on deploy; the two existing
tools are unchanged. No data migration. Rollback = revert the provider file.

## Open Questions

- Should `pipelineForecast` also fold in `won`/`lost` leads for a win-rate figure,
  or stay open-pipeline only? (Provisional: open-pipeline only for the MVP.)
- Should a `pipelinq.updateLead` / stage-transition tool be added now or deferred?
  (Provisional: deferred — this MVP is create + read + log only.)

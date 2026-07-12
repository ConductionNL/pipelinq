---
kind: config
depends_on: []
---

## Why

ADR-063 ("MCP as Platform Abstraction", 2026-07-12) rules that apps MUST NOT ship
their own MCP tool code for behaviour OpenRegister can derive. Pipelinq's
`PipelinqToolProvider` (shipped by `crm-mcp-tool-surface`, #342) hand-codes 11 tools;
8 of them are plain OpenRegister CRUD (`list*/search*/get*`) that OR can derive from
the schema itself once the new `x-openregister-mcp` dialect exists. Only three are
genuine service behaviour (`createLead`, `logContactmoment`, `pipelineForecast`).

This change is Pipelinq's **leaf migration** onto ADR-063: it declares the
`x-openregister-mcp` dialect on the `client`, `lead`, and `ticket` schemas so
OpenRegister derives the coarse CRUD tool surface, and it defines the schema-by-schema
plan to retire the hand-written CRUD tools while keeping the three service tools. It
also resolves pipelinq **issue #381**: `getLead` today aliases the raw `probability`
input into `winProbability`, shadowing the schema's **already-declarative**
recency-decayed `winProbability` calculation (`configuration.x-openregister-calculations`,
`materialise:false`). The alias must go; the declarative calculated field is the truth.

**Cross-repo dependency (blocking, state prominently):** the derived-CRUD tools only
exist once OpenRegister's `or-mcp-schema-dialect` + `or-mcp-derived-tool-provider` ship,
and the `#[McpTool]` attribute for the three retained service tools only exists once
`or-mcp-tool-attribute` ships. `depends_on` is left **empty** because those are
cross-repo (openregister) slugs Hydra's supervisor cannot resolve against this app's
issues. This change therefore lands the **config** deliverable (the dialect declarations
+ the #381 spec correction) now; the PHP provider surgery (delete the 8 derived-equivalent
handlers, add `#[McpTool]` to the 3 exceptions, drop the `decorateLead` alias) is captured
as a downstream `code` follow-up (`plq-mcp-provider-surgery`) gated on those OR changes.

## What Changes

- **Declare `x-openregister-mcp` on `client`** (base register) — `enabled:true`, read
  verbs `search` + `get` only (client had no hand-written write), read scopes + per-verb
  descriptions per the ADR-063 dialect shape.
- **Declare `x-openregister-mcp` on `lead`** (base register) — `enabled:true`, `search`
  + `get` (read); the derived `create` verb is **left disabled** because the curated
  `createLead` service tool is retained (avoids two competing create tools). Derived
  reads surface the existing declarative `qualificationScore`, `weightedValue`, and
  `winProbability` calculations natively.
- **Declare `x-openregister-mcp` on `ticket`** (`register.d/99-unify-ticket-supertype.json`)
  — `enabled:true`, `search` + `get` (read); derived `create` disabled (the curated
  `logContactmoment` service tool is retained). Search filters include the `ticketType`
  discriminator so `request`/`contactmoment`/`complaint` subtypes remain addressable.
- **Resolve #381**: spec now REQUIRES `getLead` (and lead reads generally) to expose the
  schema's declarative `winProbability` calculated field and **removes** the tool-side
  `decorateLead` alias of raw `probability`. Corrects the stale "tool-response alias" note
  in `crm-mcp-tool-surface/spec.md`.
- **Migration plan** (spec + design): precedence rule made explicit — a retained
  hand-written service tool **wins** over any derived tool on id collision, enabling a
  zero-downtime, schema-by-schema cutover (client → lead → ticket).
- **BREAKING (deferred to the follow-up `code` spec, not executed here):** delete the 8
  derived-equivalent descriptors + handlers from `PipelinqToolProvider`; annotate
  `createLead`/`logContactmoment`/`pipelineForecast` with `#[McpTool]`.

## Capabilities

### New Capabilities
<!-- none -->

### Modified Capabilities
- `crm-mcp-tool-surface`: the tool surface becomes **schema-declared** (derived) for the
  8 CRUD tools via `x-openregister-mcp`, retaining only `createLead`/`logContactmoment`/
  `pipelineForecast` as service tools; `winProbability` is redefined as the schema's
  declarative calculated field (not a tool-side alias), resolving #381.

## Impact

- **Config (this change):** `lib/Settings/pipelinq_register.json` (`client`, `lead`
  schemas — `configuration.x-openregister-mcp`); `lib/Settings/register.d/99-unify-ticket-supertype.json`
  (`ticket` schema). No new service class, no new PHP.
- **Spec:** `openspec/specs/crm-mcp-tool-surface/spec.md` (evolved, not contradicted).
- **Code (follow-up, blocked on OR chain):** `lib/Mcp/PipelinqToolProvider.php`.
- **Consumers:** Hermiq agent tool whitelists must be re-pointed from `pipelinq.listClients`
  (etc.) to the derived `pipelinq.client.search` (etc.) during cutover.
- **Dialect precedent:** consistent with `x-openregister-{lifecycle,calculations,
  aggregations,notifications,relations}` (ADR-031, parent of ADR-063).

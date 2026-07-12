## Context

`crm-mcp-tool-surface` (#342, archived 2026-07-12) shipped
`OCA\Pipelinq\Mcp\PipelinqToolProvider` — 11 hand-written tools behind a hard-coded
`TOOL_DESCRIPTORS` constant and an if/else dispatch in `invokeTool()`. ADR-063
("MCP as Platform Abstraction") supersedes ADR-035 D1 ("every app ships a provider
class"): apps declare `x-openregister-mcp` per schema and OpenRegister derives a coarse
CRUD tool template `{appId}.{schema}.{search|get|create|update|delete}`, using the schema
as inputSchema/outputSchema (MCP 2025-06-18 `structuredContent`). Only non-CRUD behaviour
stays as app code, re-annotated with a net-new `#[McpTool]` attribute.

Load-bearing current-state (verified at HEAD 2026-07-12):
- `IMcpToolProvider` ABI (`getAppId/getTools/invokeTool`) is KEPT — derived and annotated
  tools are served through it. Provider runs in the caller's NC session and owns IDOR;
  every read already delegates to OR `ObjectService` with RBAC enabled.
- Three schemas back the surface: `client` + `lead` (base `pipelinq_register.json`),
  `ticket` (`register.d/99-unify-ticket-supertype.json`; `request`/`contactmoment`/
  `complaint` are `ticketType` subtypes of the unified ticket).
- The `lead` schema **already** declares `winProbability` in
  `configuration.x-openregister-calculations` — a recency-decayed calc
  (`materialise:false`; full ≤14d, 80% ≤30d, 50% ≤60d, else 25% of `probability`).
  This is the crux of #381 (below).

## Goals / Non-Goals

**Goals:**
- Declare `x-openregister-mcp` on `client`, `lead`, `ticket` so OR derives their CRUD
  read tools (ADR-063 dialect shape, modelled on `x-speakeasy-mcp`).
- Retain `createLead`, `logContactmoment`, `pipelineForecast` as the only Pipelinq-owned
  tools; define their `#[McpTool]` re-annotation as gated follow-up work.
- Resolve #381: `winProbability` is the schema's declarative calc, not a tool-side alias.
- Spell out a zero-downtime, schema-by-schema migration + explicit precedence rule.

**Non-Goals:**
- Executing the PHP provider surgery (deleting the 8 handlers, adding `#[McpTool]`,
  removing `decorateLead`) — blocked on OR's `or-mcp-derived-tool-provider` +
  `or-mcp-tool-attribute`; captured as a downstream `code` spec, not done here.
- Building the derived-tool engine, the attribute scanner, or the invocation audit log
  (all OpenRegister-owned, ADR-063 chain).
- Progressive disclosure / per-agent scoping UX (Hermiq-owned,
  `agent-tool-governance-and-disclosure`).
- Changing the `winProbability` calc expression itself (it already exists and is correct).

## Decisions

**D1 — Dialect scope: READ verbs only; writes stay curated service tools.**
`x-openregister-mcp` enables `search` + `get` on all three schemas. The derived `create`
verb is **left disabled** on `lead` and `ticket` even though hand-written writes exist
there, because those writes are retained as curated `#[McpTool]` service tools
(`createLead` shapes an LLM-friendly `title`-only signature + returns the computed
`qualificationScore`; `logContactmoment` forces `ticketType=contactmoment` and normalises
`occurredAt` via `TicketService`). Enabling a second, schema-shaped derived `create`
would give the LLM two competing create tools for the same entity. `client` exposes no
write at all (read-only). _Alternative considered:_ enable derived `create` on lead/ticket
and retire the service tools — rejected because the curated signatures + side-effects
(discriminator, timestamp normalisation, score return) are real behaviour a coarse
schema-create loses. (See Open Questions — this is the primary deferred decision.)

**D2 — Precedence: hand-written service tool wins over derived on id collision.**
ADR-063 makes first-wins explicit as `hand-written > derived`. Pipelinq's retained ids
(`pipelinq.createLead`, `pipelinq.logContactmoment`, `pipelinq.pipelineForecast`) do not
share the derived `{appId}.{schema}.{verb}` shape, so no literal collision occurs; the
rule nonetheless guarantees that if a future derived id ever coincides, the curated tool
is never shadowed. This underwrites the schema-by-schema cutover.

**D3 — #381 resolution: read the declarative calc, drop the alias.**
`getLead`/`listLeads`/`searchLeads`/`createLead` currently call `decorateLead()`, which
sets `winProbability = probability` (raw 0–100 input) only when `winProbability` is
absent. But the `lead` schema already materialises a recency-decayed `winProbability` on
read, so the "correct" value is the calc, and the alias is at best a no-op and at worst
(if the calc is momentarily absent) a wrong, un-decayed number presented as
`winProbability`. Resolution: the spec REQUIRES lead reads to expose the schema's
declarative `winProbability`; the follow-up code spec deletes `decorateLead()`. The stale
`crm-mcp-tool-surface/spec.md` Note ("winProbability is a tool-response alias … the lead
schema was intentionally left unchanged") is corrected by the MODIFIED requirement here.

**D4 — kind: config, unblocked head of a chain.**
The shippable-now deliverable is pure config (dialect JSON on 3 schemas) + a spec
correction. All PHP is either blocked on the OR chain (handler deletion, `#[McpTool]`) or
trivially coupled (the 1-call `decorateLead` removal). Keeping this change `config` avoids
a `mixed` anti-pattern and lets it land before OR ships. The chain:
`mcp-provider-declarative-migration` (config, here) → `plq-mcp-provider-surgery` (code,
follow-up) which lists this slug **and** OR's `or-mcp-derived-tool-provider` +
`or-mcp-tool-attribute` as predecessors. `depends_on` is empty here because those are
cross-repo slugs the supervisor cannot map to Pipelinq issues (state prominently, per brief).

**D5 — Dialect shape.** Per schema, folded into `configuration` on import:
```json
"x-openregister-mcp": {
  "enabled": true,
  "tools": {
    "search": { "description": "<per-verb>", "scope": "read", "filters": ["<field>", ...],
                "annotations": { "readOnlyHint": true } },
    "get":    { "description": "<per-verb>", "scope": "read",
                "annotations": { "readOnlyHint": true } }
  }
}
```
Hints (`readOnlyHint`/`destructiveHint`/`idempotentHint`) are UNTRUSTED UX; the
authoritative gate stays OR RBAC. `outputSchema` = the schema itself
(`structuredContent`). Coarse-per-schema, never per-REST-endpoint (naive OpenAPI→MCP
degrades LLM accuracy ~9.5% / burns 30k+ tokens — Specter research 2026-07-12).

## Declarative-vs-imperative decision (ADR-031)

This change **is** the declarative migration — it moves an imperative tool surface onto
declared schema dialect. Per behaviour:

| Behaviour | Path | Rationale |
|---|---|---|
| CRUD read tools (list/search/get for client, lead, ticket/request) | **Declarative** — `x-openregister-mcp` on the schema; OR derives them | ADR-063; the schema is inputSchema/outputSchema; no app code needed |
| `winProbability` field | **Declarative** — already `x-openregister-calculations` (recency-decayed, `materialise:false`) | #381: the calc is the truth; the imperative `decorateLead` alias is removed |
| `qualificationScore`, `weightedValue` | **Declarative** — already `x-openregister-calculations` | flow through the derived read's outputSchema natively |
| `createLead` | **Imperative** `#[McpTool]` (ADR-031 exception: domain-rule/LLM-shaped write) | curated `title`-only signature + returns server-computed score; a coarse schema-create loses this |
| `logContactmoment` | **Imperative** `#[McpTool]` (ADR-031 exception: lifecycle/discriminator write) | forces `ticketType=contactmoment`, normalises `occurredAt` via `TicketService` |
| `pipelineForecast` | **Imperative** `#[McpTool]` (ADR-031 exception (2): per-request, caller-shaped read-side aggregation) | not a CRUD verb; buckets open leads by stage and sums the materialised `weightedValue`; not a stored `x-openregister-aggregations` value |

The three imperative exceptions keep their existing `IMcpToolProvider` descriptors/handlers
**unchanged** in this config change; the `#[McpTool]` re-annotation lands only after OR's
`or-mcp-tool-attribute` ships (cross-repo, follow-up).

## Seed Data (ADR-001)

**N/A** — this change adds no new schemas and no new object types; it declares a metadata
dialect (`x-openregister-mcp`) on three schemas that already have seed rows (clients,
leads, tickets) in `lib/Settings/pipelinq_register.json` and `demo_seed_data.json`. The
existing lead seeds already exercise the declarative `winProbability` decay bands (hot/warm/
cold), which is exactly what #381 needs to verify. No `_registers.json` additions required.

## Risks / Trade-offs

- **Derived reads drop enrichments the hand-written tools added.** `getRequest`/`getLead`
  inlined an activity timeline; `getClient` returned a 360 summary. A coarse derived `get`
  returns only the object (+ its declarative calcs). → Mitigation: timelines / the
  klantbeeld-360 summary are separate concerns (`ActivityTimelineService` /
  `klantbeeld-360-activation`, already declarative); the derived get carries the calc
  fields. Whether to re-expose timeline/360 as their own `#[McpTool]` tools is an Open
  Question, tracked for the follow-up code spec — not silently lost.
- **Dual surfaces during cutover.** Until the hand-written CRUD handlers are deleted, both
  `pipelinq.listClients` and derived `pipelinq.client.search` exist (different ids, no
  collision) → the LLM sees duplicates. → Mitigation: keep `x-openregister-mcp` default-off
  until derived tools are verified, cut over one schema at a time, re-point Hermiq
  whitelists, then delete the hand-written descriptors in the follow-up code spec.
- **Blocked on OR.** Landing the dialect with no derived engine yet means the declarations
  are inert until OR ships. → Mitigation: acceptable — config is forward-declared and inert
  (default-off) is safe; the spec + tasks make the ordering explicit.
- **#381 alias removal is the one code touch that is NOT blocked.** Dropping the
  `decorateLead` call is safe today (the calc already exists). → It may ship with this
  config change as thin glue, or as the first step of the follow-up — see Open Questions.

## Migration Plan (zero-downtime, schema-by-schema)

1. **Land dialect, default-off-safe.** Add `x-openregister-mcp` (`enabled:true`) to
   `client`, `lead`, `ticket`. Inert until OR's derived provider ships; hand-written tools
   keep serving. (This change.)
2. **OR ships** `or-mcp-schema-dialect` + `or-mcp-derived-tool-provider` → derived
   `pipelinq.{client,lead,ticket}.{search,get}` come online alongside the hand-written tools.
3. **Cut over `client`:** re-point Hermiq agent whitelists to `pipelinq.client.{search,get}`;
   verify; then delete `listClients`/`searchClients`/`getClient` descriptors+handlers.
4. **Cut over `lead`:** repeat; delete `listLeads`/`searchLeads`/`getLead` — and in the
   same step remove `decorateLead()` (#381), since the derived read exposes the declarative
   `winProbability`.
5. **Cut over `ticket`/`request`:** repeat; delete `listRequests`/`getRequest`.
6. **OR ships** `or-mcp-tool-attribute` → annotate the retained `createLead`,
   `logContactmoment`, `pipelineForecast` with `#[McpTool]`; drop the now-empty
   `TOOL_DESCRIPTORS`/`if-else` scaffolding.
Steps 3–6 are the follow-up `code` spec. **Rollback:** set `enabled:false` on a schema's
dialect (derived tools vanish); the hand-written tools (still present until their delete
step) resume — so never delete a hand-written tool before its derived replacement is
verified.

## Open Questions

- **OQ1 (primary):** Should the derived `create` verb be enabled on `lead`/`ticket` (and the
  curated `createLead`/`logContactmoment` service tools retired), or kept disabled with the
  service tools as the sole write path? Provisional: **disabled + keep service tools** (D1).
- **OQ2:** Should `getRequest`/`getLead` timelines and the `getClient` 360 summary be
  re-exposed as dedicated `#[McpTool]` tools, or dropped from the MCP surface (available via
  the declarative klantbeeld-360 / timeline features elsewhere)? Provisional: **drop from
  MCP**, revisit in the follow-up code spec.
- **OQ3:** Ship the `decorateLead` removal (#381) coupled with this config change as thin
  glue, or as step 4 of the follow-up? Provisional: **step 4 of the follow-up** (keeps this
  change config-pure).

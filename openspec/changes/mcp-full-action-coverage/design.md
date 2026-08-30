# Design — mcp-full-action-coverage

## Context

Pipelinq's MCP architecture is post-migration ADR-063: reads and plain CRUD are **derived** from `x-openregister-mcp` blocks on the register schemas (`SchemaDerivedToolProvider`, tool ids `pipelinq.{schema}.{verb}`, the schema itself as input/output shape); curated operations are **`#[McpTool]`-attributed service methods** discovered through the `IMcpScannableServices::pipelinq` opt-in (`lib/Mcp/PipelinqScannableServices.php`, listing `LeadService` + `TicketService`); there is deliberately no hand-written `IMcpToolProvider` (retired by `plq-mcp-provider-surgery`; the `crm-mcp-tool-surface` spec forbids its return). OpenRegister RBAC is the authoritative invoke-time gate on every path.

Consumer side, hermiq grants per agent on two axes: CRUD **scope** (`read`/`create`/`update`/`delete`, default-deny for writes, human approval gates, audit trail) × **reach** (`self` < `user` < `instance` < `external`, `ToolReachResolver::ORDER`). Reach measures blast radius of effect and disclosure, not provenance of bytes read — reading data the caller may already read is `user`; writing an object other users can see is `instance`. Two verified resolver behaviours anchor this design: three-segment derived ids infer `user` (search/get) or `instance` (write verbs); **two-segment curated ids with no declared reach fail closed to `external`** — which is where `pipelinq.createLead`, `pipelinq.logContactmoment`, and `pipelinq.pipelineForecast` sit today, because the `#[McpTool]` attribute (openregister `lib/Mcp/Attribute/McpTool.php`) has no `reach` parameter.

Current tool inventory (verified): derived `client.{search,get}`, `lead.{search,get}`, `ticket.{search,get}`, `product.{search,get}`, `leadProduct.{create,search,get}`; curated `createLead`, `logContactmoment`, `pipelineForecast`. Dark schemas: `contact`, `task`. Unreachable actions: every update, every delete, every lifecycle transition (`lead`: win/lose; `ticket`: start/complete/convert/resolve/reject/close), client/contact/task creation, request/complaint ticket creation.

## Goals / Non-Goals

**Goals:**
- One tool per CRM-core user action; reads cleanly separated from writes.
- `scope` + `reach` declared on every tool, so hermiq's grant matrix classifies pipelinq correctly without inference.
- Preserve every standing rule of `crm-mcp-tool-surface`: derived reads, curated writes on lead/ticket, no `IMcpToolProvider`, no calculated-field aliasing, error envelopes never swallowed.
- An explicit, tested exclusion boundary for surfaces that must never be agent-invocable.

**Non-Goals:**
- No POS, BRP/BSN, marketing-blast, or portal tools (D3).
- No `product` writes (master data, admin surface) and no schema-management or settings tools.
- No hermiq-side work: grants, approval gates, and audit are hermiq's; pipelinq only declares honestly.
- No re-exposure of the 360-summary/timeline reads — `mcp-provider-declarative-migration` OQ2 stays open, unchanged.
- No new dispatch seam: `PipelinqScannableServices` already lists both services; no third class becomes scannable.

## Decisions

### D1 — Derived writes where a save is plain; curated writes where a service enforces invariants

The split is decided per schema by one question: does anything beyond OpenRegister validation + RBAC have to happen on write?

- **`contact`, `task`, `client`, `leadProduct` — derived.** Their writes are plain object saves (the declarative UI writes them through `useObjectStore` → OR with no app service in between). Deriving `create`/`update` (and `delete` for `leadProduct` only) from the schema keeps zero pipelinq code on the path and inherits OR validation (`required`, enums, formats) and RBAC.
- **`lead` writes — curated.** `createLead` already exists (title-required validation, error envelopes). `updateLead` must maintain `stageEnteredAt` when `stage` changes (the register's `daysInStage` calculation reads it, falling back to `@self.created` only when the writer hasn't populated it) — an invariant a derived update would silently skip. Derived `lead.create`/`lead.update` stay disabled.
- **`ticket` writes — curated.** `TicketService::save()` normalises date-time fields and forces the `ticketType` discriminator; a derived `ticket.create` would bypass both. `createTicket` (subtypes `request`/`complaint`; contactmomenten keep `logContactmoment`) and `updateTicket` wrap `save()`. Derived `ticket.create`/`ticket.update` stay disabled.
- **Lifecycle transitions — curated, one tool per action.** Transitions are not field updates: the register declares per-transition `from`/`to` guards and authorization (`lead.win`/`lead.lose`: `sales`; `ticket.start`/`reject`: assignee-or-`sales`; `ticket.complete`/`convert`/`resolve`/`close`: assignee per-object). Eight thin attributed methods (`winLead`, `loseLead`, `startTicket`, `completeTicket`, `convertTicket`, `resolveTicket`, `rejectTicket`, `closeTicket`) each drive the same OR lifecycle path the manifest's `lifecycleActions` uses, so the transition guard and its authorization are enforced by the machinery that owns them. One-tool-per-action (rather than a `transition(action)` multiplexer) keeps the grant surface inspectable: an operator can grant "may close tickets" without granting "may reject them", and hermiq's approval prompt names the exact action.

### D2 — Reach is declared at the source, passed through by the companion

Declared values follow hermiq's doctrine (blast radius of effect/disclosure): every read tool `reach: user`; every write tool `reach: instance`; nothing `self` (no agent-private state) or `external` (nothing leaves the instance — no mail, no webhooks, no third-party disclosure). Declarations live where the tool lives: the `x-openregister-mcp` tool entry for derived tools, the `#[McpTool]` attribute for curated ones. The OpenRegister companion `mcp-reach-annotation` adds the attribute parameter and the two pass-throughs (descriptor-level `reach` is already established OR practice — `FlowMcpToolProvider` emits `'reach' => 'instance'|'user'`). Degradation without it: derived tools — hermiq inference already yields exactly the declared values; curated tools — fail closed to `external`, over-gated but never under-gated. Pipelinq therefore does not wait: declarations are forward-compatible no-ops until pass-through lands.

### D3 — The exclusion boundary is a requirement, not an accident

Four surfaces are declared non-tools, each with a distinct reason:

- **BRP/BSN** (`bsnValidatie`, `brpLookupVerzoek`, `brpPersoon`, `bsnAuditRecord`, `optOutVlag`): statutory personal-data lookups under BRP audit obligations (`BsnAuditService`); a language model must not be able to trigger or read them. No `x-openregister-mcp` block, ever.
- **POS** (`posTransaction`, `posTransactionLine`, `posRefund`, `posRefundLine`, `posRole`, `posStaff`, `receiptTemplate`, `receiptPrintLog`, `refundReason`, `paymentProvider`): cash handling and fiscal records (kassakoppeling audit); agent-driven mutation is a fiscal-compliance hazard.
- **Marketing blast**: bulk sends to external recipients are `reach: external` by definition; the blast pipeline keeps its own consent/opt-out gating and stays off the MCP surface entirely.
- **Portal administration**: cross-tenant/account administration is not a per-user CRM action.

A unit test asserts the boundary mechanically: no schema outside the allow-list (`client`, `contact`, `lead`, `ticket`, `task`, `product`, `leadProduct`) carries an `x-openregister-mcp` block, and no `#[McpTool]` attribute exists outside `LeadService`/`TicketService`. Checking for the expected allow-list, not the absence of a deny-list, is deliberate — deny-lists rot when schemas are added or renamed.

### D4 — Write-tool discipline: the decidesk pattern, applied inside attributed methods

The fleet reference implementation is decidesk's `lib/Mcp/`: `DecideskToolProvider` (dispatcher owning the catalogue), `McpMeetingGate` (`authorise(uuid, requirement)` resolving chair/participant/admin before any act), `McpArgumentValidator` (UUID/date/enum checks, cheap-first), `McpMeetingScopeResolver` (caller-visible object scoping). Pipelinq keeps its declarative/attributed architecture instead of the provider class, but every new curated method follows the same ordering decidesk's gate encodes and the existing `crm-mcp-tool-surface` spec already mandates for `createLead`/`logContactmoment`: **validate arguments (cheap, before any I/O) → authorize (OR RBAC / lifecycle authorization) → act → return the created/updated object or a typed error envelope** (`invalid_arguments`, `not_found`, `forbidden`, `conflict` for an illegal transition), never a partial success. Transition tools additionally verify the `from` state and return `conflict` — not `forbidden` — when the object is in the wrong state, so the agent can distinguish "not allowed" from "not now".

### D5 — Naming and hints

Curated ids stay two-segment verb-first like the existing three (`pipelinq.createLead` ⇒ `pipelinq.updateLead`, `pipelinq.winLead`, `pipelinq.startTicket`, …). Hints follow MCP semantics and stay advisory (ADR-063: RBAC is the gate): `readOnlyHint: true` on reads; `destructiveHint: true` on `leadProduct.delete` and on the four transitions into final states that the register marks irreversible from the UI's perspective (`win`, `lose`, `complete`, `convert` — plus `resolve`/`reject`/`close`, all of which land in `final` states); `idempotentHint: true` on transition tools (re-invoking in the target state is a no-op `conflict`, not a second effect).

## Risks / Trade-offs

- **R1 — Grant-surface size**: 8 transition tools + 4 curated writes + derived writes ≈ 30 tools. Mitigated by the manifest `mcp` advisory grouping for hermiq's picker and by uniform naming; the alternative (multiplexed action tools) hides actions from the grant matrix, which is the very defect this change exists to fix.
- **R2 — Companion slip**: if `mcp-reach-annotation` stalls, curated tools stay `external`-classed in hermiq and operators must over-grant to use them. Accepted: fail-closed is the right failure mode, and derived tools (the majority) are unaffected.
- **R3 — Derived writes bypass app conveniences**: e.g. `task.create` does not auto-place work items the way UI flows might. The split test in D1 is "invariant" vs. "convenience"; anything later discovered to be an invariant moves that schema's write to a curated tool in a follow-up (the collision/precedence rule already guarantees the curated id wins).
- **R4 — Fragment-merge regression**: new blocks must survive the `register.d` fragment merge — the failure mode that silently kills derived surfaces (pipelinq #396). The two existing merge-preservation tests are extended to cover the `contact`/`task` blocks and the new verbs.
- **R5 — Approval fatigue**: eleven new write tools all defaulting to deny + approval could train users to click through. Hermiq-side concern (its gates, its UX); pipelinq's contribution is honest `scope`/`reach`/hints so hermiq can rank prompts sensibly.

## Seed Data

None. Tools operate on existing seeded CRM objects; unit fixtures use the nil-pattern UUID convention.

## Migration Plan

- Register blocks: additive; patch-bump `contact`, `task`, `client`, `leadProduct`, `lead`, `product` schema versions (and the `ticket` fragment) so the import applies (version unchanged ⇒ no-op import).
- Service methods: additive `#[McpTool]` methods; no signature or id changes to the existing three; `PipelinqScannableServices` untouched.
- Rollback: remove the blocks/verbs and methods — derived tools disappear at the next import, attributed tools at the next scan; no data to migrate either way.
- Ordering: independent of `mcp-reach-annotation` for everything except reach pass-through (D2 degradation applies until it lands); `depends_on` marks the claim boundary, not a merge blocker.

## Open Questions

- OQ1 — Should `client.delete`/`contact.delete`/`task.delete` exist as grant-gated tools? Left out: client deletion cascades across FK'd children and is a deliberate UI act; tasks expire via status. Revisit on a concrete automation need.
- OQ2 (inherited, unchanged) — Re-exposing the 360 summary / activity timeline as read tools remains open from `mcp-provider-declarative-migration`.

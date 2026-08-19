---
kind: mixed
depends_on: [mcp-reach-annotation]
---

## Why

**Product intent:** every Conduction app should expose MCP tooling for *all* of its user actions, so that any action can in principle be automated by an AI agent; the user grants rights per agent, granularly, on hermiq's two-axis grant model (CRUD `scope` × blast-radius `reach`, default-deny for writes, human approval gates, audit trail); and even without automation, a user can command the app from chat — chat away while your apps execute your commands. A CRM whose agent can *read* the pipeline but cannot log the call, create the follow-up, or close the deal is a read-only demo, not an assistant.

Pipelinq's current MCP surface (verified in-repo) is read-heavy and stops short of that intent:

- **Derived tools** (`x-openregister-mcp` blocks, ADR-063): `pipelinq.client.{search,get}`, `pipelinq.lead.{search,get}` (filters `status`/`stage`/`client`), `pipelinq.ticket.{search,get}` (filters `ticketType`/`status`/`client`), `pipelinq.product.{search,get}`, and `pipelinq.leadProduct.{create,search,get}` — the only derived write is the quote line.
- **Curated tools** (`#[McpTool]`-attributed methods discovered via `PipelinqScannableServices` / `IMcpScannableServices::pipelinq`, per the `crm-mcp-tool-surface` spec): `pipelinq.createLead`, `pipelinq.logContactmoment`, `pipelinq.pipelineForecast`. Pipelinq ships no hand-written `IMcpToolProvider` any more (retired by `plq-mcp-provider-surgery`).

The gaps, action by action:

1. **Whole record types are dark.** `contact` and `task` carry no `x-openregister-mcp` block at all — an agent can neither look up a contact person nor create the follow-up task that "remind me to call them back" requires.
2. **Most writes are impossible.** No update on any schema; no client or contact creation; no ticket creation for the `request`/`complaint` subtypes (only the contactmoment path exists); no quote-line correction or removal despite the UI's `allowEdit: true` on `lead-lines`.
3. **No lifecycle action is reachable.** The register declares full lifecycle machines — `lead`: `win`/`lose` (authorization `sales`); `ticket`: `start`/`complete`/`convert`/`resolve`/`reject`/`close` (assignee/sales authorization per transition in `register.d/99-unify-ticket-supertype.json`) — and the UI drives them via `lifecycleActions`, but no tool exposes any transition. "Close the deal as won" cannot be said in chat.
4. **The curated tools mis-classify in hermiq's grant matrix.** Hermiq's `ToolReachResolver` (hermiq `lib/Service/Engine/ToolReachResolver.php`) resolves a declared `reach` from the descriptor, infers `user` (reads) / `instance` (writes) for three-segment derived ids — and **fails closed to `external` for any two-segment curated id with no declared reach**. `pipelinq.createLead`, `pipelinq.logContactmoment`, and `pipelinq.pipelineForecast` declare none (the `#[McpTool]` attribute has no `reach` parameter today), so pipelinq's plainest CRM actions sit in the same grant tier as "send an email to a third party". Operators over-grant or the tools go unused; both outcomes are wrong.

## What Changes

Full action coverage for the CRM core, one tool per user action, reads separated from writes, every tool annotated with `scope` and `reach`:

1. **New derived blocks** — `contact` and `task` gain `x-openregister-mcp` blocks: `search`/`get` (scope `read`) plus `create`/`update` (scope `create`/`update`) — their writes are plain object saves with no app-side invariant, so the derived path is correct (design D1).
2. **New derived write verbs on existing blocks** — `client.create`/`client.update` and `leadProduct.update`/`leadProduct.delete` (the UI already allows line editing; `delete` carries `destructiveHint: true`). `product` deliberately stays read-only (master-data writes are an admin surface).
3. **New curated tools** (`#[McpTool]` on `LeadService`/`TicketService`, scanned via the existing `PipelinqScannableServices` opt-in) where an app service enforces invariants: `pipelinq.updateLead` (stage changes maintain `stageEnteredAt`), `pipelinq.winLead` / `pipelinq.loseLead` (lead lifecycle), `pipelinq.createTicket` / `pipelinq.updateTicket` (writes must pass `TicketService::save()` — date normalisation + `ticketType` discriminator), and one tool per ticket transition: `pipelinq.startTicket`, `pipelinq.completeTicket`, `pipelinq.convertTicket`, `pipelinq.resolveTicket`, `pipelinq.rejectTicket`, `pipelinq.closeTicket`. Derived `create`/`update` verbs on `lead` and `ticket` stay disabled, preserving the existing spec's curated-write rule.
4. **Reach annotations everywhere** — every derived tool declares `reach` in its `x-openregister-mcp` entry and every curated tool declares `reach` in its `#[McpTool]` attribute: `user` for all reads (reading only what the caller may already read), `instance` for all writes (other users can see the object). No pipelinq CRM tool is `self` or `external`. Pass-through of these declarations to the tool descriptor is the OpenRegister companion change `mcp-reach-annotation` (see Dependencies); until it lands, derived tools keep hermiq's correct inference and curated tools keep failing closed to `external` — safe, merely over-restrictive.
5. **Deliberate exclusions, on record** — BRP/BSN schemas (`bsnValidatie`, `brpLookupVerzoek`, `brpPersoon`, `bsnAuditRecord`, `optOutVlag`), the POS surface, marketing-blast sends, and portal administration SHALL NOT be MCP tools (statutory/privacy, cash-handling, bulk-external-send, and cross-tenant-admin surfaces respectively — design D3).
6. **Advisory manifest hints** — the manifest-v2 `mcp` block (advisory visibility hints for hermiq's tool picker; grants nothing per ADR-063) groups the read and write catalogues for the picker UI.

The fleet reference for the write-tool discipline is decidesk's `lib/Mcp/` (`McpMeetingGate` authorization gate, `McpArgumentValidator`, `McpMeetingScopeResolver`): validate-cheap-first → authorize → act, per-object authorization resolved before any write, error envelopes never swallowed into success. Decidesk still ships a hand-written `DecideskToolProvider implements IMcpToolProvider`; pipelinq applies the same gate → validate → act pattern *inside its attributed service methods*, keeping the ADR-063 declarative/attributed architecture it already migrated to.

**Chat, concretely** (scenarios specced in the delta): *"Which of my leads went stale this month?"* → `pipelinq.lead.search` surfacing the declarative `daysSinceActivity` calculation, read grant only. *"Log my call with Jansen BV and create a follow-up task for Friday"* → `pipelinq.logContactmoment` + `pipelinq.task.create`, each requiring an explicit write grant and passing hermiq's approval gate. *"Put 10 seats of Product X on the Acme deal"* → `pipelinq.product.search` → `pipelinq.leadProduct.create`.

## Capabilities

### Modified Capabilities

- `crm-mcp-tool-surface` — from a read-surface-plus-three-writes to full CRM action coverage: two new derived blocks, write verbs on existing blocks, eleven new curated tools, scope×reach annotations on every tool, and an explicit exclusion boundary.

## Dependencies on OpenRegister (not specced here)

Proposed as the companion change **`openregister/openspec/changes/mcp-reach-annotation`**; pipelinq consumes it and MUST NOT reimplement it:

- A `reach` parameter on the `#[McpTool]` attribute (`lib/Mcp/Attribute/McpTool.php` — today it carries `name`/`description`/hints/`scope` only), passed through by `AttributeToolScanner` into the tool descriptor.
- Pass-through of a per-tool `reach` key from `x-openregister-mcp` blocks into derived-tool descriptors (`SchemaDerivedToolProvider`), accepted by `McpAnnotationValidator`. OpenRegister's own hand-written providers already emit descriptor-level `reach` (`FlowMcpToolProvider`: `'reach' => 'instance'|'user'`), so the descriptor key is established — only the two declarative paths lack it.

Degradation without the companion is safe and known: hermiq's `ToolReachResolver` infers `user`/`instance` for three-segment derived ids (matching what pipelinq would declare) and resolves undeclared two-segment curated ids to `external` — fail-closed, so the new curated tools are over-gated, never under-gated.

## Impact

- `lib/Settings/pipelinq_register.json` — `x-openregister-mcp` blocks added on `contact` and `task`; write verbs + `reach` keys added on `client`, `leadProduct`; `reach` keys on existing `lead`/`product` read tools (schema versions patch-bumped so the import applies).
- `lib/Settings/register.d/99-unify-ticket-supertype.json` — `reach` keys on the `ticket` read tools.
- `lib/Service/LeadService.php` — new `#[McpTool]` methods `updateLead`, `winLead`, `loseLead`; `lib/Service/TicketService.php` — new `#[McpTool]` methods `createTicket`, `updateTicket`, and the six transition tools. `lib/Mcp/PipelinqScannableServices.php` unchanged (both classes already listed).
- `src/manifest.json` — advisory `mcp` visibility block (presentational only).
- Existing tool ids, argument shapes, and behaviour unchanged — additive throughout; the `crm-mcp-tool-surface` spec's curated-write and no-`IMcpToolProvider` rules stay intact.
- Depends on `mcp-reach-annotation` (openregister) only for reach *pass-through*; every tool works before it lands.

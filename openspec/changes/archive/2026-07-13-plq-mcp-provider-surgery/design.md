## Context

This is Migration Plan steps 3–6 of `mcp-provider-declarative-migration` (archived
2026-07-13), executed now that all three OpenRegister ADR-063 predecessors are merged to
`origin/development`:

- **#355 `or-mcp-schema-dialect`** — `x-openregister-mcp` dialect + validator (already
  consumed; pipelinq's `client`/`lead`/`ticket` schemas already declare it).
- **#360 `or-mcp-derived-tool-provider`** — `SchemaDerivedToolProvider` derives
  `pipelinq.{client,lead,ticket}.{search,get}` from the dialect.
- **#363 `or-mcp-tool-attribute`** — `OCA\OpenRegister\Mcp\Attribute\McpTool`,
  `AttributeToolScanner`, `BuiltIn\AttributeToolProvider`, and the
  `IMcpScannableServices` per-app opt-in interface (verified read at HEAD via
  `git show 669162eb3:lib/Mcp/...` on the openregister checkout, whose local branch was
  behind `origin/development` — the files exist on `origin/development`, not on every
  local checkout).

Verified OR contract (read-only, from #363's shipped files):
- An app opts in to attribute scanning by registering a DI alias
  `OCA\OpenRegister\Mcp\IMcpScannableServices::<appId>` (mirrors the existing
  `IMcpToolProvider::<appId>` convention) resolving to an `IMcpScannableServices`
  implementation whose `getScannableServiceClasses()` lists the app's own service FQCNs.
- OpenRegister's `Application::collectAttributeMcpProviders()` reflects every PUBLIC,
  non-static, non-abstract `#[McpTool]`-attributed method on those classes, builds one tool
  descriptor per method (id `{appId}.{name-or-methodName}`, `inputSchema` inferred from
  parameter type hints + `@param` docblock tags — non-optional params are `required`), and
  wraps the whole set in one `AttributeToolProvider` instance per app.
- Collision policy runs LAST, after derived + hand-written providers: an attributed id
  colliding with a schema-**derived** id is rejected (logged, dropped — ambiguous, a
  developer error); an attributed id colliding with any **other** provider's id is
  self-suppressed (hand-written-wins, silent). Pipelinq's three retained ids
  (`pipelinq.createLead`, `pipelinq.logContactmoment`, `pipelinq.pipelineForecast`) do not
  literally collide with the derived `{schema}.{search|get}` shape, so neither path is
  exercised today — the precedence rule exists as a discovery-time safety net, not because
  we currently need it.
- Invocation is in-process (ADR-041): `AttributeToolProvider::invokeTool()` calls
  `$instance->{$method}(...$namedArguments)` on the already-DI-resolved service instance.
  **The method owns its own authorization** — OpenRegister does not establish, switch, or
  elevate a session. This is unchanged from the hand-written provider's contract (both
  already delegate to `ObjectService`/`TicketService` with RBAC left enabled).

## Goals / Non-Goals

**Goals:**
- Delete the 8 CRUD tools now permanently duplicated (and shadowed, per
  `hand-written > derived`) by OpenRegister's schema-derived reads.
- Re-home `createLead`, `logContactmoment`, `pipelineForecast` as `#[McpTool]`-attributed
  service methods, preserving their exact existing behaviour, argument validation order
  (cheap-before-expensive, then authorize-before-act), and RBAC-denial → `forbidden`
  envelope mapping.
- Resolve pipelinq#381 completely: no code path aliases `winProbability` any more.
- Decide `PipelinqToolProvider`'s fate honestly instead of leaving a shell.

**Non-Goals:**
- Re-exposing the `getClient` 360 summary or `getRequest`/`getLead` activity timelines as
  dedicated tools (OQ2 in the archived design.md — still open, still deferred; those
  summaries remain available via the app UI / `ActivityTimelineService` directly, not MCP).
- Changing the schema-derived read behaviour itself (OR-owned, out of this repo).
- Any frontend change — this is a pure backend/MCP-surface change.

## Decisions

**D1 — Delete `PipelinqToolProvider` outright rather than keep it as an empty seam.**
After removing the 8 derivable handlers, the class would carry zero tools: `getTools()`
returns `[]`, `invokeTool()` always hits the `unknown_tool` branch. A registered
`IMcpToolProvider` with an empty catalogue is dead weight — it still gets enumerated on
every MCP discovery pass for no benefit. The OR contract's actual "opt-in seam" for
attribute-only apps is `IMcpScannableServices`, which needs no provider class at all
(`AttributeToolProvider` is instantiated entirely by OpenRegister). Deleting
`PipelinqToolProvider` and its `IMcpToolProvider::pipelinq` DI alias is the honest
reading of "follow the OR contract" from the brief. *Consequence:* the pre-existing
`tests/Unit/Mcp/PipelinqToolProviderTest.php` has nothing left to test against and is
deleted; its still-relevant assertions (RBAC-denial mapping, argument validation, payload
shape) are ported into per-service test files plus a new
`PipelinqScannableServicesTest` for the opt-in class itself.

**D2 — New `LeadService` rather than bolting lead logic onto an existing service.**
No pre-existing service owns lead read/write logic — every other pipelinq service
(`AnalyticsService`, `NaviService`, `RenewalEngineService`, …) reads `lead_schema` directly
via `ObjectService` for its own narrow purpose (analytics rollups, routing, renewal). None
of them is a plausible home for `createLead`/`pipelineForecast` without misnaming their
existing responsibility. `ReportingService` (contactmoment KPI/SLA) and `ForecastService`
(forecast_snapshot roll-ups with manager overrides) are both lead-adjacent but solve
different problems — neither owns raw lead CRUD or the open-pipeline aggregation. A new,
narrowly-scoped `LeadService` (mirrors `TicketService`'s existing resolver + write-facade
shape) is the smallest correct home; `logContactmoment` stays on `TicketService` because it
already owns `save()`/`sanitizeForSave()`/the `TYPE_CONTACTMOMENT` discriminator — no new
class needed there.

**D3 — Preserve the exact error-envelope contract, don't switch to exceptions.**
`AttributeToolProvider::invokeTool()` does not catch/translate a method's return value; it
only catches *thrown* exceptions (for audit + rethrow). The existing tools already return
structured `{'error': {code, message}}` arrays for validation/RBAC/not-found failures
rather than throwing, and `AttributeToolProvider` treats any array return as a plain
success payload — so switching to exceptions would (a) change the audit log's error
classification and (b) require the MCP host to interpret an uncaught PHP exception instead
of a structured envelope. The migrated methods keep returning the same envelopes for the
same conditions (`invalid_arguments`, `not_configured`, `forbidden`, `internal_error`) —
byte-for-byte the same codes and trigger conditions as today, just called directly instead
of via `PipelinqToolProvider::invokeTool()`'s if/else dispatch.

**D4 — #381: delete the alias, don't replace it with anything.**
`decorateLead()` set `winProbability = probability` only when `winProbability` was absent.
Since the `lead` schema's `x-openregister-calculations` already materialises the
recency-decayed `winProbability` on every read (including the read-back after
`saveObject`), the alias was redundant-at-best/wrong-at-worst. `createLead` now returns
`ObjectService::saveObject()`'s result unmodified — the materialised calc flows through
exactly like `qualificationScore`/`weightedValue` already did.

## Risks / Trade-offs

- **Deleting `PipelinqToolProviderTest.php` loses its git history as a single file.** →
  Mitigation: the port is 1:1 traceable (each ported test cites which original test it
  replaces in its docblock); `git log --follow` on the deleted path still recovers history
  if ever needed.
- **`LeadService` is new surface area, reviewed nowhere else yet.** → Mitigation: it is a
  narrow read/write facade in the same shape as the already-reviewed `TicketService`
  (config resolution → `ObjectService` call → envelope mapping), not novel architecture.
- **If OpenRegister's `origin/development` and this worktree's OR checkout ever drift on
  the attribute contract**, the stubs in `tests/Stubs/Mcp/` could silently diverge from the
  real interface. → Mitigation: same accepted risk as the pre-existing
  `tests/Stubs/Mcp/IMcpToolProvider.php` stub; both are read-verified against the actual
  merged OR source at HEAD (`669162eb3`), not guessed.

## Migration Plan

This change **is** Migration Plan steps 3–6 from `mcp-provider-declarative-migration`:

3. ~~Cut over `client`~~ / 4. ~~Cut over `lead`~~ / 5. ~~Cut over `ticket`~~ — done together
   in one PR rather than schema-by-schema, because all three predecessor OR PRs are already
   merged (no partial-availability window to stage through) and pipelinq's own test suite
   is the safety net (BASELINE vs AFTER, zero new failures).
6. **OR ships `or-mcp-tool-attribute`** (done, #363) → annotate `createLead`,
   `logContactmoment`, `pipelineForecast` with `#[McpTool]`; drop the now-empty
   `TOOL_DESCRIPTORS`/if-else scaffolding (done by deleting `PipelinqToolProvider`
   entirely, per D1).

**Rollback:** revert this change's commit. `x-openregister-mcp` stays enabled on the three
schemas (from the prior config change) regardless, so a revert only re-adds the
hand-written CRUD tools — it does not touch the dialect declarations or OpenRegister's
derivation engine.

## Open Questions

Carried over, unchanged, from the archived predecessor (not resolved by this change):
- **OQ2:** re-expose `getClient`'s 360 summary / `getRequest`/`getLead` timelines as
  dedicated `#[McpTool]` tools? Still provisional **no** — out of scope here (see
  Non-Goals).

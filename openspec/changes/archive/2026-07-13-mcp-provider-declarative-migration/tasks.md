# Tasks — mcp-provider-declarative-migration (kind: config)

Scope note: this change lands **config only** (the `x-openregister-mcp` dialect + the
spec correction). The PHP provider surgery is a **blocked follow-up** (`plq-mcp-provider-surgery`,
kind: code) gated on OpenRegister's `or-mcp-derived-tool-provider` + `or-mcp-tool-attribute`;
do NOT edit `lib/Mcp/PipelinqToolProvider.php` in this change.

## 1. Declare the dialect on the CRM schemas

- [ ] 1.1 Add `x-openregister-mcp` (`enabled:true`; `search` + `get` verbs, `scope:read`, per-verb descriptions, `readOnlyHint:true`) to the `client` schema in `lib/Settings/pipelinq_register.json` — no write verbs.
- [ ] 1.2 Add `x-openregister-mcp` (`enabled:true`; `search` + `get`, `scope:read`; `create` disabled/omitted) to the `lead` schema in `lib/Settings/pipelinq_register.json`, with `search.filters` covering `status`, `stage`, `client`.
- [ ] 1.3 Add `x-openregister-mcp` (`enabled:true`; `search` + `get`, `scope:read`; `create` disabled/omitted) to the `ticket` schema in `lib/Settings/register.d/99-unify-ticket-supertype.json`, with `search.filters` including the `ticketType` discriminator plus `status`, `client`.

## 2. Verify the declarations

- [ ] 2.1 Confirm `pipelinq_register.json` and `99-unify-ticket-supertype.json` still parse as valid JSON and that `x-openregister-mcp` folds into each schema's `configuration` on import (occ register import dry-run / re-import).
- [ ] 2.2 Confirm the `lead` schema's existing declarative `winProbability` (`x-openregister-calculations`, `materialise:false`, recency-decayed) is untouched and is materialised on lead reads (verify against a hot/warm/cold seed lead).

## 3. Spec + follow-up bookkeeping

- [ ] 3.1 Update `openspec/specs/crm-mcp-tool-surface/spec.md`: set `status: in-progress`, add this change to the OpenSpec-changes list, and correct the stale Note that calls `winProbability` a tool-side alias (it is the declarative calc — #381).
- [ ] 3.2 Record the blocked follow-up `code` spec `plq-mcp-provider-surgery` (delete the 8 derived-equivalent descriptors + handlers, drop `decorateLead`'s `winProbability` alias per #381, annotate `createLead`/`logContactmoment`/`pipelineForecast` with `#[McpTool]`), listing OR's `or-mcp-derived-tool-provider` + `or-mcp-tool-attribute` as predecessors.

## Acceptance criteria

- The `client`, `lead`, and `ticket` schemas each carry a valid `x-openregister-mcp` block with read verbs declared and no unintended write verb enabled.
- The dialect is inert and safe while OpenRegister's derived-tool engine is not yet deployed (default behaviour unchanged; hand-written tools keep serving).
- `winProbability` is documented and specified as the declarative calculated field; no spec text remains that describes it as a tool-side alias.
- The migration order and precedence rule (`hand-written > derived`) are captured in design.md; the follow-up code spec is recorded with its cross-repo predecessors.

## Quality reminders (plain-text, not checkboxes)

- No new PHP, no new service class, no seed-data changes (N/A — see design.md Seed Data).
- Re-validate JSON after each edit; a single malformed schema blocks the whole register import.
- i18n keys (if any tool descriptions are user-facing) stay English.
- Reference ADR-063 (parent ADR-031) in commit + PR body.

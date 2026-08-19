# Tasks: mcp-full-action-coverage

## 1. Derived tool declarations (register)

- [ ] 1.1 Add `x-openregister-mcp` blocks to `contact` and `task`
  - **spec_ref**: `specs/crm-mcp-tool-surface/spec.md#requirement-crm-crud-tools-are-declared-on-the-schema-not-hand-coded`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - `contact`: `enabled: true`; tools `search` (scope `read`, filter `client`, `readOnlyHint: true`), `get` (read), `create` (create), `update` (update); per-verb descriptions; `reach: user` on reads, `reach: instance` on writes
    - `task`: same shape; `search` filters `type`, `status`, `clientId`; descriptions name the `type` enum (`callbackRequest`, `followUpTask`, `informationRequest`)
    - Schema `version` patch-bumped on both so the register import applies

- [ ] 1.2 Add write verbs + reach to existing blocks
  - **spec_ref**: `specs/crm-mcp-tool-surface/spec.md#requirement-crm-crud-tools-are-declared-on-the-schema-not-hand-coded`, `specs/crm-mcp-tool-surface/spec.md#requirement-every-tool-declares-scope-and-reach-for-the-grant-matrix`
  - **files**: `lib/Settings/pipelinq_register.json`, `lib/Settings/register.d/99-unify-ticket-supertype.json`
  - **acceptance_criteria**:
    - `client` gains `create` + `update` verbs; `leadProduct` gains `update` + `delete` (`delete` with `destructiveHint: true`; descriptions state `total` stays OpenRegister-materialised and non-writable)
    - `reach` key added to every existing tool entry (`client`/`lead`/`ticket`/`product`/`leadProduct` reads → `user`; `leadProduct.create` and all new writes → `instance`)
    - `lead` and `ticket` blocks still declare NO `create`/`update` verbs (curated write path preserved)
    - Affected schema/fragment versions patch-bumped

- [ ] 1.3 Extend fragment-merge preservation tests
  - **spec_ref**: `specs/crm-mcp-tool-surface/spec.md#requirement-crm-crud-tools-are-declared-on-the-schema-not-hand-coded`
  - **files**: `tests/Unit/Service/ConfigFileLoaderServiceTest.php`, `tests/Unit/Service/RegisterFragmentMergeTest.php`
  - **acceptance_criteria**:
    - Merge tests assert the `contact`/`task` blocks and the new `client`/`leadProduct` verbs survive the `register.d` fragment merge (pipelinq #396 failure mode)

## 2. Curated lead tools (LeadService)

- [ ] 2.1 `pipelinq.updateLead`
  - **spec_ref**: `specs/crm-mcp-tool-surface/spec.md#requirement-mcp-provider-exposes-rbac-guarded-crm-write-tools`
  - **files**: `lib/Service/LeadService.php`, `tests/Unit/Service/LeadServiceTest.php`
  - **acceptance_criteria**:
    - `#[McpTool(name: 'updateLead', scope: 'update', reach: 'instance', readOnlyHint: false, destructiveHint: false, idempotentHint: true, …)]` on a new public method; writes via `ObjectService->saveObject` (RBAC enforced)
    - A changed `stage` sets `stageEnteredAt`; an update without `stage` leaves it untouched; a `status` argument returns `invalid_arguments` naming `winLead`/`loseLead`
    - Envelope order: validate → authorize → act; tests cover `invalid_arguments`, `not_found`, `forbidden`, and the stage invariant
    - NOTE: `reach:` on the attribute compiles only after the openregister companion `mcp-reach-annotation`; until it merges, keep the value in the block's docblock and add the parameter in the same PR that bumps the openregister dependency (design D2)

- [ ] 2.2 `pipelinq.winLead` / `pipelinq.loseLead`
  - **spec_ref**: `specs/crm-mcp-tool-surface/spec.md#requirement-lifecycle-transitions-are-curated-tools-one-per-action`
  - **files**: `lib/Service/LeadService.php`, `tests/Unit/Service/LeadServiceTest.php`
  - **acceptance_criteria**:
    - One `#[McpTool]` method per transition (scope `update`, `idempotentHint: true`, `destructiveHint: true` — both targets are `final`), driving the register's `x-openregister-lifecycle` `win`/`lose` path (authorization: assignee or `sales`)
    - Wrong `from` state → `conflict`; unauthorized caller → `forbidden`; success returns the transitioned lead

## 3. Curated ticket tools (TicketService)

- [ ] 3.1 `pipelinq.createTicket` / `pipelinq.updateTicket`
  - **spec_ref**: `specs/crm-mcp-tool-surface/spec.md#requirement-mcp-provider-exposes-rbac-guarded-crm-write-tools`
  - **files**: `lib/Service/TicketService.php`, `tests/Unit/Service/TicketServiceTest.php`
  - **acceptance_criteria**:
    - Both write via `TicketService::save()` (dates normalised, discriminator forced); `createTicket` accepts `ticketType` `request`|`complaint` only — `contactmoment` returns `invalid_arguments` pointing at `logContactmoment`
    - `updateTicket` rejects a `status` argument (`invalid_arguments` naming the transition tools)
    - Envelope coverage: `invalid_arguments`, `not_found`, `forbidden`

- [ ] 3.2 Six ticket transition tools
  - **spec_ref**: `specs/crm-mcp-tool-surface/spec.md#requirement-lifecycle-transitions-are-curated-tools-one-per-action`
  - **files**: `lib/Service/TicketService.php`, `tests/Unit/Service/TicketServiceTest.php`
  - **acceptance_criteria**:
    - `startTicket`, `completeTicket`, `convertTicket`, `resolveTicket`, `rejectTicket`, `closeTicket` — one `#[McpTool]` each, scope `update`, `idempotentHint: true`, `destructiveHint: true` on final-state targets, driving the `ticket` fragment's lifecycle path with its per-transition authorization (`start`/`reject`: assignee or `sales`; `complete`/`convert`/`resolve`/`close`: assignee)
    - Wrong-state → `conflict`, unauthorized → `forbidden`; tests cover one allowed, one wrong-state, one unauthorized case per authorization class

- [ ] 3.3 Reflection + registry assertions for the expanded curated set
  - **spec_ref**: `specs/crm-mcp-tool-surface/spec.md#requirement-hand-written-service-tools-take-precedence-over-derived-tools`
  - **files**: `tests/Unit/Service/LeadServiceTest.php`, `tests/Unit/Service/TicketServiceTest.php`, `tests/Unit/Mcp/PipelinqScannableServicesTest.php`
  - **acceptance_criteria**:
    - Reflection asserts exactly fourteen `#[McpTool]` ids across the two services (the list in the spec); `PipelinqScannableServices` still lists only `LeadService` + `TicketService`
    - No `IMcpToolProvider` implementation exists under `lib/` (existing absence assertion still green)

## 4. Scope×reach conformance + exclusion boundary

- [ ] 4.1 Scope/reach conformance test
  - **spec_ref**: `specs/crm-mcp-tool-surface/spec.md#requirement-every-tool-declares-scope-and-reach-for-the-grant-matrix`
  - **files**: `tests/Unit/Mcp/McpSurfaceConformanceTest.php`
  - **acceptance_criteria**:
    - Enumerates every `x-openregister-mcp` tool entry (base + fragments) and every `#[McpTool]` attribute; asserts read⇒`reach: user`+`readOnlyHint: true`, write⇒`reach: instance`; no `self`/`external` anywhere
    - Prove the test can fail (temporarily mislabel one entry locally) before finalising

- [ ] 4.2 Allow-list exclusion test
  - **spec_ref**: `specs/crm-mcp-tool-surface/spec.md#requirement-excluded-surfaces-are-never-agent-invocable`
  - **files**: `tests/Unit/Mcp/McpSurfaceConformanceTest.php`
  - **acceptance_criteria**:
    - Fails if any schema outside `client`/`contact`/`lead`/`ticket`/`task`/`product`/`leadProduct` carries an `x-openregister-mcp` block (BRP/BSN + POS covered by construction), or any `#[McpTool]` exists outside the two services

## 5. Companion + advisory surface

- [ ] 5.1 File the openregister companion change `mcp-reach-annotation`
  - **spec_ref**: `specs/crm-mcp-tool-surface/spec.md#requirement-every-tool-declares-scope-and-reach-for-the-grant-matrix`
  - **files**: `../openregister/openspec/changes/mcp-reach-annotation/` (separate repo — proposal only from this task)
  - **acceptance_criteria**:
    - Proposes: `reach` parameter on `#[McpTool]` + `AttributeToolScanner` pass-through; `reach` key accepted in `x-openregister-mcp` tool entries and passed through by `SchemaDerivedToolProvider`/`McpAnnotationValidator` (descriptor-level `reach` already exists in `FlowMcpToolProvider`)
    - This change's claims about declared reach being served remain conditional until it lands (design D2)

- [ ] 5.2 Manifest advisory `mcp` block
  - **spec_ref**: `specs/crm-mcp-tool-surface/spec.md#requirement-the-crm-core-action-surface-is-chat-commandable`
  - **files**: `src/manifest.json`
  - **acceptance_criteria**:
    - Top-level `mcp` block (manifest-v2 schema key `properties/mcp` — advisory only, grants nothing per ADR-063) groups read vs. write tools for hermiq's picker; `npm run check:manifest` passes

## 6. Verification

- [ ] 6.1 Live chat-flow verification against hermiq on :8080
  - **spec_ref**: `specs/crm-mcp-tool-surface/spec.md#requirement-the-crm-core-action-surface-is-chat-commandable`
  - **files**: —
  - **acceptance_criteria**:
    - Read-only agent answers the stale-leads question via `pipelinq.lead.search` (`daysSinceActivity` present) with zero approval prompts
    - Write-granted agent completes "log call + follow-up task" with one approval per write; ungranted agent is refused at the grant layer (default-deny observed, not assumed)
    - `pipelinq.winLead` on an open seeded lead transitions it; second invocation returns `conflict`

- [ ] 6.2 Gates + spec hygiene
  - **spec_ref**: all
  - **files**: `tests/`, `openspec/`
  - **acceptance_criteria**:
    - `composer check:strict` green; hydra gates pass; `openspec validate mcp-full-action-coverage --strict` clean
    - Base-spec sync on archive updates the three MODIFIED requirements in `openspec/specs/crm-mcp-tool-surface/spec.md` without disturbing its `@e2e exclude` traceability notes

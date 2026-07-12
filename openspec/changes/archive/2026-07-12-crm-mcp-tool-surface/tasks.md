# Tasks: crm-mcp-tool-surface

## 1. Schema resolution

- [ ] 1.1 Add `client_schema` / `lead_schema` resolver helpers to `PipelinqToolProvider` (read via `IAppConfig` like `TicketService::getSchemaId`), returning a `not_configured` envelope when unset
  - files: `lib/Mcp/PipelinqToolProvider.php`
  - Acceptance criteria:
    - Helpers resolve the configured register + client/lead schema ids
    - An unconfigured schema yields a `not_configured` error envelope, never a silent empty result

## 2. Client tools

- [ ] 2.1 Add `pipelinq.listClients` + `pipelinq.searchClients` descriptors and handlers (RBAC-scoped `ObjectService->findAll`, list cap, `count`)
- [ ] 2.2 Add `pipelinq.getClient` handler returning the client plus a live `summary` (open-ticket count across all `ticketType`s, open-lead count + summed value, recent contactmomenten via `ActivityTimelineService`)
  - files: `lib/Mcp/PipelinqToolProvider.php`
  - Acceptance criteria:
    - `getClient` on an unreadable/absent id returns `forbidden`/`not_found`, never a partial object as success
    - A timeline aggregation failure degrades `recentContactmomenten` to `[]` without failing the read

## 3. Lead + pipeline tools

- [ ] 3.1 Add `pipelinq.listLeads` + `pipelinq.searchLeads` + `pipelinq.getLead` (include computed `qualificationScore`, `weightedValue`, `winProbability`; `getLead` inlines the timeline)
- [ ] 3.2 Add `pipelinq.pipelineForecast` — per-stage rows over RBAC-visible open leads with lead count, summed `value`, summed weighted value, plus a grand total, ordered by stage order
  - files: `lib/Mcp/PipelinqToolProvider.php`
  - Acceptance criteria:
    - Forecast reads only RBAC-visible leads; hidden leads never contribute to a total
    - Weighted value is read from the materialised `weightedValue` calculation, not recomputed

## 4. Write tools

- [ ] 4.1 Add `pipelinq.createLead` (validate required `title`; `ObjectService->saveObject` on the lead schema, RBAC `create` enforced; return created lead incl. `qualificationScore`)
- [ ] 4.2 Add `pipelinq.logContactmoment` (validate `client`/`channel`/`title`; write via `TicketService::save(TYPE_CONTACTMOMENT, …)`; return created ticket UUID)
  - files: `lib/Mcp/PipelinqToolProvider.php`
  - Acceptance criteria:
    - Missing required argument → `invalid_arguments` envelope, nothing written
    - A denied write maps to a `forbidden` envelope (no success on RBAC failure)

## 5. Tests + docs

- [ ] 5.1 Unit-test the extended catalogue as a fixture (every new tool id present with a valid `inputSchema`) and each handler's success + error envelopes, using municipality/consultancy/travel-agency fixtures with nil-UUID references
  - files: `tests/Unit/Mcp/PipelinqToolProviderTest.php`
  - Acceptance criteria:
    - `getTools()` includes all client/lead/forecast/create/log tools plus the pre-existing request tools
    - Handler tests cover: RBAC-denied read → `forbidden`; unknown tool id → `unknown_tool`; missing arg → `invalid_arguments`
    - `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan)
- [ ] 5.2 Update the provider docblock + `docs/FEATURES.md` MCP row to reflect the fuller surface (issue #342)

## Acceptance criteria (change-level)

- The MCP provider exposes the client, lead, pipeline-forecast, create-lead, and log-contactmoment tools alongside the existing request tools.
- Every read is RBAC-scoped through `ObjectService`; every write goes through `ObjectService`/`TicketService` with `create` authorization enforced.
- No new OpenRegister schema, no new `*Service.php`, no RBAC bypass introduced.

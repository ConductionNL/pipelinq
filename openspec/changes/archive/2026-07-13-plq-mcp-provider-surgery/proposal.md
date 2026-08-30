## Why

`mcp-provider-declarative-migration` (archived 2026-07-13) landed `x-openregister-mcp` on
the `client`/`lead`/`ticket` schemas as config-only, inert-until-OR-ships groundwork.
OpenRegister has since shipped the whole ADR-063 chain: `or-mcp-schema-dialect` (#355,
the dialect + validator), `or-mcp-derived-tool-provider` (#360, `SchemaDerivedToolProvider`
deriving `pipelinq.{client,lead,ticket}.{search,get}`), and `or-mcp-tool-attribute` (#363,
the `#[McpTool]` attribute + `AttributeToolScanner`/`AttributeToolProvider` +
`IMcpScannableServices` opt-in). The blocking predecessors named in that change's Migration
Plan steps 3–6 are now all merged to `origin/development`, so this change executes the PHP
provider surgery it deferred: delete the 8 hand-written CRUD tools that the derived reads
now duplicate (and permanently shadow, per `hand-written > derived` precedence), and
re-home the 3 curated tools as `#[McpTool]`-attributed service methods so
`PipelinqToolProvider`'s hard-coded `TOOL_DESCRIPTORS`/if-else scaffolding can retire.

## What Changes

- **BREAKING** (internal, no external contract change): delete 8 hand-written tool
  descriptors + handlers from `PipelinqToolProvider` — `listRequests`, `getRequest`,
  `listClients`, `searchClients`, `getClient`, `listLeads`, `searchLeads`, `getLead` — now
  served by OpenRegister's schema-derived `pipelinq.{client,lead,ticket}.{search,get}`
  tools. Remove now-orphaned private helpers (`resolveRequestContext`,
  `resolveClientContext`, `fetchTimeline`, `buildClientSummary`, `countOpenTickets`,
  `summarizeOpenLeads`, `matchesQuery`, `decorateLead`, etc.) that only those 8 handlers used.
- Move `createLead` and `pipelineForecast` business logic into a new `LeadService`
  (`lib/Service/LeadService.php`), and `logContactmoment` into the existing `TicketService`,
  each as a public method annotated `#[McpTool]` (OpenRegister's ADR-063 chain-3 attribute).
- Add `PipelinqScannableServices` (`lib/Mcp/PipelinqScannableServices.php`) implementing
  OpenRegister's `IMcpScannableServices`, declaring `LeadService`/`TicketService` as
  scannable, and register it under the `IMcpScannableServices::pipelinq` DI alias in
  `Application.php` (mirrors the existing per-app `IMcpToolProvider::<appId>` convention).
- Delete `PipelinqToolProvider` and its `IMcpToolProvider::pipelinq` DI alias — after the
  surgery it would carry zero tools (a dead shim), so the scannable-services opt-in becomes
  Pipelinq's sole MCP-tool-provider seam. Delete the now-superseded
  `tests/Unit/Mcp/PipelinqToolProviderTest.php`; port its still-relevant cases (pipeline
  forecast, createLead, logContactmoment behaviour + RBAC-denial mapping) into
  `tests/Unit/Service/LeadServiceTest.php` (new) and `tests/Unit/Service/TicketServiceTest.php`
  (extended), plus a new `tests/Unit/Mcp/PipelinqScannableServicesTest.php` and attribute-
  presence assertions for all three `#[McpTool]` methods.
- Add standalone-PHPUnit stubs for `OCA\OpenRegister\Mcp\IMcpScannableServices` and
  `OCA\OpenRegister\Mcp\Attribute\McpTool` under `tests/Stubs/Mcp/`, mirroring the existing
  `IMcpToolProvider` stub pattern (pipelinq's composer.json has no real openregister
  dependency; the real classes are only present at NC-app runtime).
- Resolve pipelinq#381: `decorateLead()`'s `winProbability = probability` alias is deleted
  outright along with `getLead`/`listLeads`/`searchLeads`; `createLead`'s decoration is also
  dropped so nothing shadows the lead schema's declarative `x-openregister-calculations`
  `winProbability` (recency-decayed, materialised by OpenRegister on save/read).

## Capabilities

### New Capabilities

(none — this change implements a capability the prior config change already scaffolded)

### Modified Capabilities

- `crm-mcp-tool-surface`: the "Hand-written service tools take precedence over derived
  tools" requirement's retained-tools scenario becomes concrete (the 3 curated tools are now
  literally `#[McpTool]`-attributed, not merely "to be re-annotated"); the read-tool-surface
  requirement's `winProbability` note is fully realised (no code path aliases it any more).
  No requirement text changes meaning — this syncs the spec's already-written contract to
  the now-completed implementation and flips the capability status.

## Impact

- `lib/Mcp/PipelinqToolProvider.php` — deleted.
- `lib/Mcp/PipelinqScannableServices.php` — new.
- `lib/Service/LeadService.php` — new (`createLead`, `pipelineForecast`).
- `lib/Service/TicketService.php` — adds `logContactmoment`.
- `lib/AppInfo/Application.php` — swaps the `IMcpToolProvider::pipelinq` alias for
  `IMcpScannableServices::pipelinq`.
- `tests/Unit/Mcp/PipelinqToolProviderTest.php` — deleted.
- `tests/Unit/Service/LeadServiceTest.php` — new.
- `tests/Unit/Service/TicketServiceTest.php` — extended.
- `tests/Unit/Mcp/PipelinqScannableServicesTest.php` — new.
- `tests/Stubs/Mcp/IMcpScannableServices.php`, `tests/Stubs/Mcp/Attribute/McpTool.php` — new.
- No database/schema changes, no route changes, no frontend changes. No change to the
  RBAC/authorization contract (still OR `ObjectService`/`TicketService` with RBAC enabled).

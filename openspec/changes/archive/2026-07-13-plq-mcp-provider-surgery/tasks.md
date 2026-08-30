## 1. Stubs + scannable-services seam

- [x] 1.1 Add `tests/Stubs/Mcp/Attribute/McpTool.php` (guarded `class_exists`) and `tests/Stubs/Mcp/IMcpScannableServices.php` (guarded `interface_exists`), mirroring the existing `tests/Stubs/Mcp/IMcpToolProvider.php` pattern, verified against OR `669162eb3`.
- [x] 1.2 Add `lib/Mcp/PipelinqScannableServices.php` implementing `IMcpScannableServices::getScannableServiceClasses()` returning `[LeadService::class, TicketService::class]`.

## 2. Migrate the 3 curated tools to attributed service methods

- [x] 2.1 Add `lib/Service/LeadService.php` with `#[McpTool]`-attributed `createLead(...)` and `pipelineForecast()`, porting `handleCreateLead`/`buildCreateLeadPayload`/`handlePipelineForecast`/`buildForecastFromLeads` behaviour verbatim (minus the `decorateLead()` call — #381).
- [x] 2.2 Add `#[McpTool]`-attributed `TicketService::logContactmoment(...)`, porting `handleLogContactmoment` behaviour verbatim.

## 3. Delete the derived-superseded surface

- [x] 3.1 Delete `lib/Mcp/PipelinqToolProvider.php` and its `IMcpToolProvider::pipelinq` DI alias in `lib/AppInfo/Application.php`; register `IMcpScannableServices::pipelinq` → `PipelinqScannableServices::class` in its place.
- [x] 3.2 Delete `tests/Unit/Mcp/PipelinqToolProviderTest.php`.

## 4. Tests

- [x] 4.1 Add `tests/Unit/Service/LeadServiceTest.php`: attribute-presence assertions for both `#[McpTool]` methods, plus behaviour tests ported from the deleted provider test (missing-title, RBAC-denied, successful create incl. no `winProbability` alias, forecast grouping/summing, not-configured).
- [x] 4.2 Add `tests/Unit/Service/TicketServiceTest.php` (new file — no `TicketServiceTest` existed at HEAD to extend, contrary to the proposal's assumption; noted as a deviation): attribute-presence assertion for `logContactmoment`, plus behaviour tests ported from the deleted provider test (missing client/channel/title, not-configured, successful write incl. UUID return, RBAC-denied).
- [x] 4.3 Add `tests/Unit/Mcp/PipelinqScannableServicesTest.php` asserting the returned class list.

## 5. Verify + spec sync

- [x] 5.1 Run scoped `phpcs` on every touched/added `lib/` file; run full `phpunit-unit.xml` — zero new failures vs. the 1601-test baseline. Result: 0 phpcs errors on all 4 touched `lib/` files (3 pre-existing warnings on unrelated `TicketService` resolver methods, out of this change's scope, given a best-effort `@spec` tag anyway per house rule); BASELINE 1601/0 failures → AFTER 1579/0 failures (-37 deleted-file tests, +15 ported/new; 3 warnings and 11 skipped unchanged).
- [x] 5.2 Confirm every `@spec` tag on touched/new files points at `openspec/specs/crm-mcp-tool-surface/spec.md` (canonical), not an archived change path. Result: every new/modified `#[McpTool]` method and the new `PipelinqScannableServices` class tag the canonical path; the three untouched-behaviour `TicketService` resolver methods keep the file's pre-existing (non-canonical, non-archived, in-progress) `unify-ticket-supertype` reference — unrelated to this change's capability, left as-is.

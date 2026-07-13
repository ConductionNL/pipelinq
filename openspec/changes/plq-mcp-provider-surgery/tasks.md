## 1. Stubs + scannable-services seam

- [ ] 1.1 Add `tests/Stubs/Mcp/Attribute/McpTool.php` (guarded `class_exists`) and `tests/Stubs/Mcp/IMcpScannableServices.php` (guarded `interface_exists`), mirroring the existing `tests/Stubs/Mcp/IMcpToolProvider.php` pattern, verified against OR `669162eb3`.
- [ ] 1.2 Add `lib/Mcp/PipelinqScannableServices.php` implementing `IMcpScannableServices::getScannableServiceClasses()` returning `[LeadService::class, TicketService::class]`.

## 2. Migrate the 3 curated tools to attributed service methods

- [ ] 2.1 Add `lib/Service/LeadService.php` with `#[McpTool]`-attributed `createLead(...)` and `pipelineForecast()`, porting `handleCreateLead`/`buildCreateLeadPayload`/`handlePipelineForecast`/`buildForecastFromLeads` behaviour verbatim (minus the `decorateLead()` call — #381).
- [ ] 2.2 Add `#[McpTool]`-attributed `TicketService::logContactmoment(...)`, porting `handleLogContactmoment` behaviour verbatim.

## 3. Delete the derived-superseded surface

- [ ] 3.1 Delete `lib/Mcp/PipelinqToolProvider.php` and its `IMcpToolProvider::pipelinq` DI alias in `lib/AppInfo/Application.php`; register `IMcpScannableServices::pipelinq` → `PipelinqScannableServices::class` in its place.
- [ ] 3.2 Delete `tests/Unit/Mcp/PipelinqToolProviderTest.php`.

## 4. Tests

- [ ] 4.1 Add `tests/Unit/Service/LeadServiceTest.php`: attribute-presence assertions for both `#[McpTool]` methods, plus behaviour tests ported from the deleted provider test (missing-title, RBAC-denied, successful create incl. no `winProbability` alias, forecast grouping/summing, not-configured).
- [ ] 4.2 Extend `tests/Unit/Service/TicketServiceTest.php`: attribute-presence assertion for `logContactmoment`, plus behaviour tests ported from the deleted provider test (missing client/channel/title, not-configured, successful write incl. UUID return, RBAC-denied).
- [ ] 4.3 Add `tests/Unit/Mcp/PipelinqScannableServicesTest.php` asserting the returned class list.

## 5. Verify + spec sync

- [ ] 5.1 Run scoped `phpcs` on every touched/added `lib/` file; run full `phpunit-unit.xml` — zero new failures vs. the 1601-test baseline.
- [ ] 5.2 Confirm every `@spec` tag on touched files points at `openspec/specs/crm-mcp-tool-surface/spec.md` (canonical), not an archived change path.

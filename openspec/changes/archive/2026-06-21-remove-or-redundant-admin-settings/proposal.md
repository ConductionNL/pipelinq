---
kind: code
---

## Why

Pipelinq's admin settings page (`src/views/settings/Settings.vue` rendered by
`lib/Settings/AdminSettings.php`) carried two admin sections that OpenRegister (OR) now
fully subsumes, and which enforced **nothing** in Pipelinq — write-only configuration with
no runtime consumer. Per ADR-022 (*Apps Consume OpenRegister Abstractions*), a leaf app must
not re-implement a capability OR already owns. Both sections were dead code:

- **§5 "Objects API Access"** — a per-schema "allowed Nextcloud groups" map persisted under
  `IAppConfig` keys `objecten_access_<slug>` via `ObjectenAccessService`. Its enforcement
  method `ObjectenAccessService::isAllowed()` was called from exactly one place: the settings
  page's own read endpoint (`SettingsController::getObjectenAccess`). The real data path —
  `openregister/api/objects/{register}/{schema}` — never consulted it. OR's `PermissionHandler`
  plus the Register/Schema `groups` arrays already enforce RBAC on every object request, so the
  Pipelinq map was a permission UI that granted and revoked nothing.

- **§3 "MCP Server Administration"** — `mcp_*` config (endpoint, auth mode, API key, OAuth
  client) persisted via `ApiAuthService::saveMcpConfig()` / `getMcpConfig()`. There was **zero**
  consumer in Pipelinq: no MCP client, no tool dispatch, nothing read these keys. OR owns the
  real MCP server (`McpServerController`); the Pipelinq form configured a server that does not
  exist in this app.

Keeping write-only config that enforces nothing is worse than having no UI: it tells an admin
they have set an access policy or wired an MCP server when they have done neither. This change
deletes both — frontend and backend — and records that the capabilities live in OR.

## What Changes

- **DELETE** `lib/Service/ObjectenAccessService.php` (whole class — only consumer was the
  dead settings endpoint) and its unit test.
- **DELETE** `ApiAuthService::saveMcpConfig()` + `ApiAuthService::getMcpConfig()` (the class
  survives — it still backs §4 REST API token + OAuth config, which are out of scope here) and
  the matching `ApiAuthServiceTest::testGetMcpConfigExcludesSecrets`.
- **DELETE** `SettingsController::getObjectenAccess()`, `saveObjectenAccess()`, `saveMcp()`,
  the `ObjectenAccessService` constructor dependency, and the `objectenAccess` / `mcpConfig`
  payload keys from `index()`. Adjust `SettingsControllerTest` accordingly.
- **DELETE** the matching routes from `appinfo/routes.php`
  (`settings#getObjectenAccess`, `settings#saveObjectenAccess`, `settings#saveMcp`).
- **DELETE** the §5 and §3 `<NcSettingsSection>` blocks from `Settings.vue` plus their
  `data()` fields, computed (`objectenAccessEntries`), `mounted()` wiring, and methods
  (`loadGroupOptions`, `saveSchemaAccess`, `saveMcp`); drop the now-unused `NcSelect` import.
- **DELETE** the `objectenAccess` / `mcpConfig` state + hydration from
  `src/store/modules/settings.js`.
- The `IAppConfig` values already written under `objecten_access_*` and `mcp_*` are left in the
  database untouched (harmless orphan rows); only the code that read/wrote them is removed.

No new endpoints, services, or nextcloud-vue changes. The remaining admin sections (Register
mapping, Pipelines, Product Categories, Lead Sources, Request Channels, Prospect Discovery,
REST API Authentication, BI Export, Lead Management, Shillinq, Shillinq AP, xWiki) are
unaffected.

## Impact

- Affected specs: `admin-settings` (adds one requirement recording that per-schema Objects API
  access control and MCP server administration are OR-owned and MUST NOT be re-implemented in
  Pipelinq's admin settings).
- Affected code: `lib/Service/ObjectenAccessService.php` (removed),
  `lib/Service/ApiAuthService.php`, `lib/Controller/SettingsController.php`,
  `appinfo/routes.php`, `src/views/settings/Settings.vue`, `src/store/modules/settings.js`,
  `tests/Unit/Service/ObjectenAccessServiceTest.php` (removed),
  `tests/Unit/Service/ApiAuthServiceTest.php`, `tests/Unit/Controller/SettingsControllerTest.php`.
- Behaviour: net removal of dead code; no functional regression. RBAC on objects continues to
  be enforced by OR exactly as before (the deleted map never affected it). MCP remains
  available via OR's `McpServerController`.
- ADRs: ADR-022 (Apps Consume OR Abstractions) — the governing rationale. ADR-031
  (Schema-declarative business logic over service classes) — same principle: shared data-layer
  capabilities (RBAC, MCP) are not re-modelled in leaf service classes.

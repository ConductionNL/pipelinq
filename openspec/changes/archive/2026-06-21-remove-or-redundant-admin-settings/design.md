# Design — Remove OR-redundant admin settings (§5 Objects API Access, §3 MCP)

## The dead-config finding

Both removed sections shared the same anti-pattern: **write-only configuration with no
enforcement path**. The admin could set values, the values persisted to `IAppConfig`, and the
values were read back into the form — but no production code branch ever consulted them to
change behaviour.

### §5 Objects API Access — proof of deadness

`ObjectenAccessService` exposed `getAccessMap()`, `setSchemaAccess()`, and `isAllowed()`.
A full-tree grep for `isAllowed(` / `ObjectenAccessService` shows the only caller of the
enforcement method was `SettingsController::getObjectenAccess()` — i.e. the settings page asking
its own service whether the current user is allowed, purely to render a read response. The
canonical object data path is the OpenRegister REST API
(`/apps/openregister/api/objects/{register}/{schema}`), reached from the frontend via
`useObjectStore`; it never imports or calls `ObjectenAccessService`. Therefore setting a group
restriction in this UI changed nothing about who could read or write objects.

`EntityActivityService::isAllowedEntityType()` is a same-named-but-unrelated method (an
entity-type allowlist) and is **not** affected.

### What OR provides instead

OpenRegister enforces object-level RBAC in its own permission layer (`PermissionHandler`) using
the `groups` arrays declared on each Register and Schema. Access control for the objects
Pipelinq stores is configured **in OpenRegister**, on the register/schema, and is enforced on
every API call regardless of any leaf-app config. This is the ADR-022 division of labour: the
shared data layer owns data access control; leaf apps consume it.

### §3 MCP Server Administration — proof of deadness

`ApiAuthService::saveMcpConfig()` / `getMcpConfig()` persisted and read `mcp_endpoint`,
`mcp_auth_mode`, `mcp_api_key`, `mcp_oauth_client_id`, `mcp_oauth_client_secret`. A full-tree
grep for `mcp_` / `McpConfig` shows every occurrence is settings plumbing
(`SettingsController::index`/`saveMcp`, the service, the Vue form, the Pinia store). No Pipelinq
code instantiates an MCP client, dispatches an MCP tool call, or otherwise reads these keys.

### What OR provides instead

OpenRegister ships the real MCP server (`McpServerController`). The MCP endpoint that agents
talk to is OR's, configured in OR. Pipelinq's form configured a non-existent local server.

## What is intentionally kept

- `ApiAuthService` (the class) — still backs §4 "REST API Authentication" (token generate/
  list/revoke + OAuth config), which this change does **not** touch. Only the two MCP methods are
  removed. `SECRET_PLACEHOLDER` is retained (still used by `saveOAuthConfig`).
- The `index()` admin payload still returns `apiTokens` and `oauthConfig`; only `objectenAccess`
  and `mcpConfig` are dropped.
- All other admin sections.

## Persistence note

Existing `IAppConfig` rows (`pipelinq` app id) under keys `objecten_access_<slug>`, `mcp_endpoint`,
`mcp_auth_mode`, `mcp_api_key`, `mcp_oauth_client_id`, `mcp_oauth_client_secret` are left in the
database. They are inert once the reading code is gone. A future housekeeping migration could
delete them, but that is out of scope and carries no benefit beyond tidiness.

## Verification approach

- PHP: `composer lint` + `phpcs --standard=phpcs.xml --warning-severity=0` over the changed
  `lib/` files (the enforced gates), plus PHPUnit over the two adjusted suites.
- Frontend: `npm run build` must compile `Settings.vue` and the store after the deletions.
- Live: load `/settings/admin/pipelinq` after a hard cache-bust and confirm §5 + §3 are gone,
  the remaining sections still render, and the console is clean.

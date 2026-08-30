# Proposal: admin-settings

## Problem

Pipelinq's admin settings page lacks configuration panels for three high-demand integration areas, together cited in 111 market tender mentions:

1. **Objects API access control** — Administrators cannot restrict which object types (schemas) are accessible per user role in the Objecten API, creating a security gap for multi-tenant government deployments (59 tenders, demand 182).
2. **REST API authentication** — Pipelinq exposes no authenticated REST API surface for external integrations. Organizations require both API token (demand 93) and OAuth 2.0 (demand 78) authentication flows, including idToken forwarding for OpenID Connect (demand 5).
3. **MCP Server administration** — No admin UI exists for configuring a Model Context Protocol (MCP) server endpoint with OAuth2 or API key authentication (demand 2).

## Solution

Extend the Pipelinq admin settings page (`/settings/admin/pipelinq`) with four configuration panels:

1. **Objects API Access Control** — Per-schema group access restrictions stored in `IAppConfig`. Admin selects which Nextcloud groups may access each object type via the Objecten API.
2. **REST API Token Management** — Generate, list, and revoke API bearer tokens for external system integrations. Tokens are cryptographically random, stored as SHA-256 hashes.
3. **REST API OAuth 2.0 Configuration** — Configure OAuth 2.0 client credentials (client ID, secret, endpoints, scopes). Enable idToken forwarding when `openid` scope is active.
4. **MCP Server Administration** — Configure MCP server endpoint, select authentication mode (OAuth2 or API key), and store credentials securely in `IAppConfig`.

## Scope

### In Scope

- Admin settings sections for all four configuration panels
- Backend services: `ApiAuthService` (token + OAuth + MCP config), `ObjectenAccessService` (per-schema RBAC)
- Settings API extensions: `POST /api/settings/objecten-access`, `GET/POST/DELETE /api/settings/api-tokens`, `POST /api/settings/oauth`, `POST /api/settings/mcp`
- All secrets stored in `IAppConfig` with `sensitive: true`; never returned in API responses
- Translation keys for all new UI strings (en + nl)

### Out of Scope

- Custom login, session management, or password storage (forbidden by ADR-005-security)
- User-level OAuth consent flows (not admin settings)
- Billing or accounting system backends
- Live MCP server health-check polling in the UI
- API middleware enforcement of token/OAuth (separate change — auth enforcement is backend-only, not admin UI)
- OpenRegister schema changes (no new entities in this change)

## Approach

1. **Extend `SettingsController`** — Add endpoints for api-auth, objecten-access, mcp. All mutations enforce `IGroupManager::isAdmin()` on the backend.
2. **Create `ApiAuthService`** — Generates tokens via `ISecureRandom`, stores SHA-256 hashes in `IAppConfig`, manages OAuth 2.0 and MCP config.
3. **Create `ObjectenAccessService`** — Reads/writes per-schema group access maps (`objecten_access_<schemaSlug>` → JSON array of group IDs) to `IAppConfig`.
4. **Extend `AdminSettings.vue`** — Add `CnSettingsSection` panels for each configuration area. Use `NcSelect` for group pickers, `NcInputField` for credentials, `NcButton` for token generation.

## Features Addressed

| Feature | Demand | Category |
|---------|--------|----------|
| Restrict access to Objecten API admin by object type | 182 | integration |
| REST API for all entities with token authentication | 93 | integration |
| REST API covering all entities with OAuth 2.0 | 78 | integration |
| Admin Interface — Objects API | 14 | integration |
| REST API — send `idToken` with openid scope | 5 | integration |
| MCP Server Administration UI with OAuth2 and API Key Authentication | 2 | release-v17.2.0 |

## Cross-Project Dependencies

- **OpenRegister** — `SchemaService` for listing registered schemas in the access control panel; `AuthorizationService` for RBAC
- **Nextcloud core** — `IGroupManager` for group enumeration; `IAppConfig` for settings storage; `ISecureRandom` for token generation

## Rollback Strategy

All changes are additive to admin settings. Removing the added `IAppConfig` keys reverts access restrictions to default (open access). No database migrations, no schema changes, no data loss on rollback.

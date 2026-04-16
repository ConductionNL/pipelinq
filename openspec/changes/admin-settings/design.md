# Design: admin-settings

## Architecture Overview

This change is purely within the admin settings layer. No new OpenRegister schemas are introduced — all configuration is stored in `IAppConfig` (per ADR-001-data-layer: app config → `IAppConfig`, NOT OpenRegister).

```
AdminSettings.vue  (Vue, settings.js entry, /settings/admin/pipelinq)
    ↓ calls
GET  /api/settings              → SettingsController::index()
POST /api/settings/objecten-access → SettingsController::saveObjectenAccess()
GET  /api/settings/api-tokens   → SettingsController::listTokens()
POST /api/settings/api-tokens   → SettingsController::generateToken()
DELETE /api/settings/api-tokens/{id} → SettingsController::revokeToken()
POST /api/settings/oauth        → SettingsController::saveOAuth()
POST /api/settings/mcp          → SettingsController::saveMcp()
    ↓ delegates to
ApiAuthService / ObjectenAccessService
    ↓ stores/reads
IAppConfig (sensitive flag for secrets — never returned to frontend)
```

## Key Design Decisions

### 1. Objecten API Access Restrictions

**Decision**: Store per-schema group access as a JSON-encoded group ID array in `IAppConfig` using the key `objecten_access_<schemaSlug>`.

**Rationale**: Schema slugs are stable identifiers defined in `pipelinq_register.json`. Storing per-schema config under predictable keys enables individual updates without locking the full config. `IAppConfig` is the correct boundary for app config (ADR-001); no domain entity is needed.

**Access default**: If no access map exists for a schema, all authenticated users can access it (open default). This preserves backward compatibility with existing installations.

**Admin UI**: A `CnSettingsSection` labelled "Objects API Access" renders a table: one row per registered schema (schema title + slug). Each row has an `NcSelect` (multi-select, searchable) populated from `IGroupManager::search()`. The admin saves per-row or bulk saves on "Save" click.

### 2. REST API Token Authentication

**Decision**: Tokens are generated with `ISecureRandom` (256-bit entropy), displayed once at creation, stored only as SHA-256 hashes in `IAppConfig` under keys `api_token_<id>` where `<id>` is a UUID.

**Rationale**: `ISecureRandom` is the correct Nextcloud API for cryptographic randomness (ADR-005-security). Hash storage ensures tokens cannot be recovered from config reads. Each token is a separate `IAppConfig` key to allow individual revocation.

**Stored value structure** (per token):
```json
{
  "id": "uuid",
  "label": "External ERP integration",
  "hash": "sha256-hex",
  "created": "2026-04-16T09:00:00Z",
  "lastUsed": null
}
```

**Admin UI**: Token list shows label, creation date, last-used date (or "Never"), and a "Revoke" button. "Generate Token" opens a dialog (NcDialog) with a label field. After generation, the plaintext token is shown once in a read-only input with a copy button. Saving closes the dialog.

### 3. REST API OAuth 2.0 Configuration

**Decision**: Store OAuth 2.0 client config in `IAppConfig` with `sensitive: true` for secret fields:
- `oauth_client_id` — Client ID (non-sensitive)
- `oauth_client_secret` — Client secret (**sensitive**, never returned to frontend)
- `oauth_token_endpoint` — Token endpoint URL (non-sensitive)
- `oauth_auth_endpoint` — Authorization endpoint URL (non-sensitive)
- `oauth_scopes` — Space-separated scopes (non-sensitive)
- `oauth_id_token_forwarding` — Boolean: forward idToken when `openid` scope is active

**idToken forwarding**: When `oauth_id_token_forwarding` is `true` and the OAuth scope includes `openid`, the received `id_token` JWT is forwarded as `X-Id-Token` header in subsequent API calls. This satisfies the demand for OpenID Connect interoperability.

**Admin UI**: A `CnSettingsSection` labelled "OAuth 2.0 Configuration" with labelled `NcInputField` components (type="password" for secret). A toggle for idToken forwarding. The form loads client ID, endpoints, and scopes on open; the client secret field shows a placeholder ("••••••") if already configured and only updates if the user types a new value.

### 4. MCP Server Administration

**Decision**: Store MCP config in `IAppConfig`:
- `mcp_endpoint` — MCP server base URL (non-sensitive)
- `mcp_auth_mode` — `oauth2` or `apikey`
- `mcp_api_key` — API key value (**sensitive**, never returned to frontend)
- `mcp_oauth_client_id` — OAuth2 client ID for MCP (non-sensitive)
- `mcp_oauth_client_secret` — OAuth2 client secret (**sensitive**)

**Admin UI**: A `CnSettingsSection` labelled "MCP Server" with an endpoint input and an auth mode `NcSelect` (`OAuth 2.0` | `API Key`). Selecting auth mode conditionally shows credential fields.

## Reuse Analysis

Per ADR-012-deduplication, existing platform services leveraged — no custom implementations needed for:

| Capability | Service/Component Used |
|------------|------------------------|
| Cryptographic token generation | `ISecureRandom` (Nextcloud core) |
| Settings storage | `IAppConfig` (Nextcloud core) |
| Group list for access control | `IGroupManager::search()` (Nextcloud core) |
| Admin authorization check | `IGroupManager::isAdmin()` (Nextcloud core) |
| Schema list for access matrix | `SchemaService` (OpenRegister) |
| Admin settings UI layout | `CnVersionInfoCard`, `CnRegisterMapping`, `CnSettingsSection`, `CnSettingsCard` (`@conduction/nextcloud-vue`) |
| Group multi-select input | `NcSelect` (re-exported via `@conduction/nextcloud-vue`) |
| Admin settings registration | `ISettings` + `ISettingsManager` (existing `PipelinqAdmin.php` + `PipelinqSection.php`) |

Search performed across `openspec/specs/`, `openregister/lib/Service/`: no overlap found with ObjectService, RegisterService, ConfigurationService for token management or OAuth admin config.

## Seed Data

Not applicable. This change introduces no OpenRegister schemas and modifies only admin settings configuration panels. Per company-wide ADR rules: "Changes that only modify frontend components or non-schema backend logic (e.g., settings, permissions) do not require seed data."

## Backend

### SettingsController additions (`lib/Controller/SettingsController.php`)

Extend existing `GET /api/settings` response to include:
- `objectenAccess`: `{schemaSlug: [groupId, ...], ...}`
- `apiTokens`: `[{id, label, created, lastUsed}, ...]` (no hashes)
- `oauthConfig`: `{clientId, tokenEndpoint, authEndpoint, scopes, idTokenForwarding}` (NO secret)
- `mcpConfig`: `{endpoint, authMode, oauthClientId}` (NO secrets)

New action methods (all enforce `IGroupManager::isAdmin()` via `#[AuthorizedAdminSetting]`):

| Method | Route | Action |
|--------|-------|--------|
| POST | `/api/settings/objecten-access` | `saveObjectenAccess()` |
| GET | `/api/settings/api-tokens` | `listTokens()` |
| POST | `/api/settings/api-tokens` | `generateToken()` — returns plaintext token once |
| DELETE | `/api/settings/api-tokens/{id}` | `revokeToken()` |
| POST | `/api/settings/oauth` | `saveOAuth()` |
| POST | `/api/settings/mcp` | `saveMcp()` |

### ApiAuthService (`lib/Service/ApiAuthService.php`)

```
generateToken(string $label): array          — Creates, hashes, stores; returns {id, token, label, created}
listTokens(): array                          — Returns metadata array without hashes
revokeToken(string $id): void               — Deletes IAppConfig key
validateToken(string $plaintext): bool      — Hash-compares against stored tokens (for API middleware)
saveOAuthConfig(array $config): void        — Saves to IAppConfig; skips secret if value is placeholder
getOAuthConfig(): array                     — Returns non-sensitive fields only
saveMcpConfig(array $config): void          — Saves to IAppConfig; skips secrets if placeholder
getMcpConfig(): array                       — Returns non-sensitive fields only
```

### ObjectenAccessService (`lib/Service/ObjectenAccessService.php`)

```
getAccessMap(): array                       — Returns {schemaSlug: [groupIds]} for all configured schemas
setSchemaAccess(string $slug, array $ids): void  — Stores JSON-encoded group IDs in IAppConfig
isAllowed(string $schemaSlug, string $userId): bool  — Checks group membership; defaults true if no map
```

## Frontend

### AdminSettings.vue changes (`src/views/admin/AdminSettings.vue`)

Three new `CnSettingsSection` panels appended after `CnVersionInfoCard` + `CnRegisterMapping`:

**Section 1 — Objects API Access**
- Header: "Objects API Access"
- Body: table with columns: Schema, Allowed Groups, Actions
- Each row: schema title + slug label, `NcSelect` (multi, searchable, populated from groups API), "Save" per-row button
- Empty state: "No schemas registered. Run re-import first."

**Section 2 — REST API Authentication**
- Header: "REST API Authentication"
- Tabs via `NcButton` toggle: "Tokens" | "OAuth 2.0"
- Tokens tab: token list table (label, created, lastUsed, Revoke button) + "Generate Token" button
- OAuth 2.0 tab: labeled inputs (Client ID, Client Secret [password type], Token Endpoint, Authorization Endpoint, Scopes) + idToken forwarding toggle + "Save OAuth Configuration" button

**Section 3 — MCP Server**
- Header: "MCP Server Administration"
- Inputs: Endpoint URL, Auth Mode (NcSelect: OAuth 2.0 | API Key)
- Conditional on Auth Mode:
  - API Key: API Key input (password type)
  - OAuth 2.0: OAuth Client ID + OAuth Client Secret (password type)
- "Save MCP Configuration" button

All sensitive inputs: show placeholder "••••••••" if value is set server-side; only submit if user enters a new value.

## Files Changed

| File | Action | Description |
|------|--------|-------------|
| `lib/Controller/SettingsController.php` | MODIFY | Add objecten-access, api-tokens, oauth, mcp endpoints |
| `lib/Service/ApiAuthService.php` | CREATE | Token generation, OAuth 2.0 config, MCP config |
| `lib/Service/ObjectenAccessService.php` | CREATE | Per-schema group access CRUD |
| `appinfo/routes.php` | MODIFY | Register 6 new settings API routes |
| `src/views/admin/AdminSettings.vue` | MODIFY | Add 3 CnSettingsSection panels |
| `l10n/en.json` | MODIFY | Add translation keys for new UI strings |
| `l10n/nl.json` | MODIFY | Add Dutch translations |

## Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| OAuth client secret exposed in API response | High | `getOAuthConfig()` strips all sensitive keys; `IAppConfig::getValueString()` with sensitive flag hides value from config export |
| Token brute-force | Medium | 256-bit entropy via `ISecureRandom`; SHA-256 hash storage; Nextcloud rate limiting at HTTP layer |
| Schema slug change invalidates access map | Low | No map = open access default; admin must reconfigure after slug rename |
| Placeholder passthrough overwrites secrets | Medium | Backend checks: only update IAppConfig secret if received value ≠ placeholder pattern |

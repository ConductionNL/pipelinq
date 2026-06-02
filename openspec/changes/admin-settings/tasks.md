# Tasks: admin-settings

## 0. Deduplication Check

- [ ] 0.1 Search `openspec/specs/` and `openregister/lib/Service/` for token management, OAuth config, and per-schema access control
  - **spec_ref**: ADR-012-deduplication
  - **acceptance_criteria**:
    - GIVEN the search is complete
    - THEN findings MUST be documented in this task (even if "no overlap found")
    - AND any overlapping capability MUST be referenced before new code is written

## 1. Backend — ObjectenAccessService

- [ ] 1.1 Create `lib/Service/ObjectenAccessService.php`
  - **spec_ref**: `specs/admin-settings/spec.md#REQ-ADM-002`
  - **files**: `lib/Service/ObjectenAccessService.php`
  - **acceptance_criteria**:
    - GIVEN a schema slug and list of group IDs
    - WHEN `setSchemaAccess()` is called
    - THEN the group IDs MUST be JSON-encoded and stored in IAppConfig under `objecten_access_<slug>`
    - AND `getAccessMap()` MUST return the full map for all configured schemas
    - AND `isAllowed($slug, $userId)` MUST return true if user is in any configured group, or if no map exists

## 2. Backend — ApiAuthService

- [ ] 2.1 Create `lib/Service/ApiAuthService.php`
  - **spec_ref**: `specs/admin-settings/spec.md#REQ-ADM-003`, `specs/admin-settings/spec.md#REQ-ADM-004`, `specs/admin-settings/spec.md#REQ-ADM-006`
  - **files**: `lib/Service/ApiAuthService.php`
  - **acceptance_criteria**:
    - GIVEN a label string
    - WHEN `generateToken($label)` is called
    - THEN a 256-bit random token MUST be generated via `ISecureRandom`
    - AND only the SHA-256 hash MUST be stored in IAppConfig under `api_token_<uuid>`
    - AND the plaintext token MUST be returned exactly once (not persisted)
    - AND `listTokens()` MUST return metadata (id, label, created, lastUsed) without hashes
    - AND `revokeToken($id)` MUST delete the IAppConfig key
    - AND `validateToken($plaintext)` MUST compare hash and return bool
    - AND `saveOAuthConfig($config)` MUST skip updating the client secret if the value equals the placeholder string
    - AND `getOAuthConfig()` MUST return non-sensitive fields only (no client_secret)
    - AND `saveMcpConfig($config)` MUST store endpoint and auth mode; store secrets with sensitive: true; skip secrets if placeholder
    - AND `getMcpConfig()` MUST return non-sensitive fields only

## 3. Backend — SettingsController Extensions

- [ ] 3.1 Add `saveObjectenAccess()` action to `lib/Controller/SettingsController.php`
  - **spec_ref**: `specs/admin-settings/spec.md#REQ-ADM-002`
  - **files**: `lib/Controller/SettingsController.php`
  - **acceptance_criteria**:
    - GIVEN an admin POST to `/api/settings/objecten-access` with `{schemaSlug, groupIds}`
    - THEN `ObjectenAccessService::setSchemaAccess()` MUST be called
    - AND HTTP 200 with updated access map MUST be returned
    - AND a non-admin request MUST return HTTP 403

- [ ] 3.2 Add token management actions (`listTokens`, `generateToken`, `revokeToken`)
  - **spec_ref**: `specs/admin-settings/spec.md#REQ-ADM-003`
  - **files**: `lib/Controller/SettingsController.php`
  - **acceptance_criteria**:
    - GIVEN GET `/api/settings/api-tokens`
    - THEN a list of token metadata MUST be returned (no hashes)
    - GIVEN POST `/api/settings/api-tokens` with `{label}`
    - THEN a new token MUST be generated and the plaintext returned ONCE in the response
    - GIVEN DELETE `/api/settings/api-tokens/{id}`
    - THEN the token MUST be revoked and HTTP 200 returned
    - AND all three actions MUST enforce admin authorization

- [ ] 3.3 Add `saveOAuth()` action for OAuth 2.0 config
  - **spec_ref**: `specs/admin-settings/spec.md#REQ-ADM-004`, `specs/admin-settings/spec.md#REQ-ADM-005`
  - **files**: `lib/Controller/SettingsController.php`
  - **acceptance_criteria**:
    - GIVEN a POST to `/api/settings/oauth` with OAuth fields
    - THEN `ApiAuthService::saveOAuthConfig()` MUST be called
    - AND `idTokenForwarding` flag MUST be stored when provided
    - AND the client secret MUST NOT be returned in the response
    - AND non-admins MUST receive HTTP 403

- [ ] 3.4 Add `saveMcp()` action for MCP server config
  - **spec_ref**: `specs/admin-settings/spec.md#REQ-ADM-006`
  - **files**: `lib/Controller/SettingsController.php`
  - **acceptance_criteria**:
    - GIVEN a POST to `/api/settings/mcp` with endpoint, authMode, and credentials
    - THEN `ApiAuthService::saveMcpConfig()` MUST be called
    - AND secrets MUST NOT be returned in the response
    - AND non-admins MUST receive HTTP 403

- [ ] 3.5 Extend `GET /api/settings` (index action) to include admin config
  - **spec_ref**: `specs/admin-settings/spec.md#REQ-ADM-002`
  - **files**: `lib/Controller/SettingsController.php`
  - **acceptance_criteria**:
    - GIVEN GET `/api/settings` called by an admin
    - THEN the response MUST include `objectenAccess`, `apiTokens`, `oauthConfig` (no secret), and `mcpConfig` (no secrets)

## 4. Routes

- [ ] 4.1 Register new settings API routes in `appinfo/routes.php`
  - **spec_ref**: ADR-002-api
  - **files**: `appinfo/routes.php`
  - **acceptance_criteria**:
    - The following routes MUST be registered:
      - `POST api/settings/objecten-access` → `SettingsController::saveObjectenAccess`
      - `GET api/settings/api-tokens` → `SettingsController::listTokens`
      - `POST api/settings/api-tokens` → `SettingsController::generateToken`
      - `DELETE api/settings/api-tokens/{id}` → `SettingsController::revokeToken`
      - `POST api/settings/oauth` → `SettingsController::saveOAuth`
      - `POST api/settings/mcp` → `SettingsController::saveMcp`

## 5. Frontend — AdminSettings.vue Extensions

- [ ] 5.1 Add "Objects API Access" CnSettingsSection to `src/views/admin/AdminSettings.vue`
  - **spec_ref**: `specs/admin-settings/spec.md#REQ-ADM-002`
  - **files**: `src/views/admin/AdminSettings.vue`
  - **acceptance_criteria**:
    - GIVEN the admin opens the settings page
    - THEN a "Objects API Access" section MUST be visible
    - AND it MUST list all registered schemas (from settings.objectenAccess)
    - AND each schema row MUST have a multi-select NcSelect for groups
    - AND saving a row MUST POST to `/api/settings/objecten-access`
    - AND a success/error notification MUST be shown using try/catch
    - AND the empty state "No schemas registered. Run re-import first." MUST show when no schemas exist

- [ ] 5.2 Add "REST API Authentication" CnSettingsSection with token management
  - **spec_ref**: `specs/admin-settings/spec.md#REQ-ADM-003`
  - **files**: `src/views/admin/AdminSettings.vue`
  - **acceptance_criteria**:
    - GIVEN the admin opens the Tokens tab
    - THEN a table with Label, Created, Last Used, and Actions columns MUST be shown
    - AND a "Generate Token" button MUST open a dialog for entering a label
    - AND after generation, the plaintext token MUST be displayed once with a copy button
    - AND clicking "Revoke" MUST DELETE the token and remove the row
    - AND all store calls MUST be in try/catch with user-facing error feedback

- [ ] 5.3 Add OAuth 2.0 configuration tab
  - **spec_ref**: `specs/admin-settings/spec.md#REQ-ADM-004`, `specs/admin-settings/spec.md#REQ-ADM-005`
  - **files**: `src/views/admin/AdminSettings.vue`
  - **acceptance_criteria**:
    - GIVEN the admin opens the OAuth 2.0 tab
    - THEN labeled inputs MUST be shown for: Client ID, Client Secret (password type), Token Endpoint, Auth Endpoint, Scopes
    - AND a toggle MUST be shown for "Forward idToken (OpenID Connect)"
    - AND fields MUST be pre-populated from `settings.oauthConfig`
    - AND the client secret field MUST show "••••••••" placeholder if a secret is stored
    - AND on save, only non-placeholder values MUST be included in the POST body
    - AND a success/error notification MUST be shown

- [ ] 5.4 Add "MCP Server Administration" CnSettingsSection
  - **spec_ref**: `specs/admin-settings/spec.md#REQ-ADM-006`
  - **files**: `src/views/admin/AdminSettings.vue`
  - **acceptance_criteria**:
    - GIVEN the admin opens the MCP Server section
    - THEN an Endpoint URL input and Auth Mode NcSelect (API Key | OAuth 2.0) MUST be shown
    - AND selecting "API Key" MUST reveal an API Key input (password type)
    - AND selecting "OAuth 2.0" MUST reveal OAuth Client ID + Client Secret inputs
    - AND credential fields MUST show "••••••••" placeholder if a value is stored server-side
    - AND save MUST POST to `/api/settings/mcp` and show success/error notification

## 6. Translations

- [ ] 6.1 Add translation keys for all new UI strings to `l10n/en.json` and `l10n/nl.json`
  - **spec_ref**: ADR-007-i18n
  - **files**: `l10n/en.json`, `l10n/nl.json`
  - **acceptance_criteria**:
    - GIVEN the translation files
    - THEN both files MUST contain identical key sets (zero gaps)
    - AND all keys MUST be English strings
    - AND `nl.json` MUST contain Dutch translations for all new keys
    - AND no hardcoded Dutch or English strings MUST remain in Vue template or script blocks

## 7. Verification

- [ ] 7.1 Smoke test: token generation and revocation
  - **spec_ref**: `specs/admin-settings/spec.md#REQ-ADM-003`
  - **acceptance_criteria**:
    - GIVEN a running Pipelinq instance
    - WHEN `POST /api/settings/api-tokens` is called with a valid admin session and label
    - THEN HTTP 200 MUST be returned with a plaintext token
    - AND `GET /api/settings/api-tokens` MUST list the token metadata (no hash)
    - AND `DELETE /api/settings/api-tokens/{id}` MUST remove it
    - AND the same endpoint called by a non-admin MUST return HTTP 403

- [ ] 7.2 Smoke test: Objects API access restriction
  - **spec_ref**: `specs/admin-settings/spec.md#REQ-ADM-002`
  - **acceptance_criteria**:
    - GIVEN a schema restricted to group "sales-team"
    - WHEN a non-member calls `GET /api/settings/objecten-access`
    - THEN the restriction MUST be enforced (isAllowed returns false)
    - AND a group member MUST pass the check

- [ ] 7.3 Smoke test: OAuth and MCP save endpoints
  - **spec_ref**: `specs/admin-settings/spec.md#REQ-ADM-004`, `specs/admin-settings/spec.md#REQ-ADM-006`
  - **acceptance_criteria**:
    - GIVEN a valid admin session
    - WHEN POST `/api/settings/oauth` is called with valid config
    - THEN HTTP 200 MUST be returned
    - AND `GET /api/settings` MUST return `oauthConfig` WITHOUT the client secret
    - AND similarly for `POST /api/settings/mcp`

- [ ] 7.4 Run `npm run build` and verify no errors
  - **acceptance_criteria**:
    - GIVEN the modified source files
    - WHEN `npm run build` is run
    - THEN the build MUST complete without errors or warnings

- [ ] 7.5 Run `composer check:strict` and verify all tests pass
  - **acceptance_criteria**:
    - GIVEN the modified PHP files
    - WHEN `composer check:strict` is run
    - THEN all PHPUnit tests MUST pass
    - AND no strict type errors MUST be reported

# Admin Settings — delta

## ADDED Requirements

### Requirement: REQ-AS-121: REST API token issuance and OAuth client configuration are OpenRegister-owned

The Pipelinq admin settings page MUST NOT provide a "REST API Authentication" section — neither a
REST API token-issuance UI nor an OAuth 2.0 client-configuration UI. Authenticated
machine-to-machine access to the objects Pipelinq stores is owned by OpenRegister: API consumers
are persisted as OpenRegister `Consumer` records, authenticated at runtime by OpenRegister's
`AuthorizationService`, and managed through OpenRegister's `/api/consumers` surface. Per ADR-022
(*Apps Consume OpenRegister Abstractions*), Pipelinq MUST NOT re-implement API-credential issuance
or OAuth client configuration as leaf-app settings. Specifically, the page MUST NOT render a "REST
API Authentication" section, and the backend MUST NOT expose endpoints that issue, list, revoke or
validate Pipelinq-local API tokens (`api_token_*`) or persist OAuth client credentials (`oauth_*`)
as Pipelinq application config. The `ApiAuthService` class MUST NOT exist.

#### Scenario: No REST API Authentication section on the settings page
@e2e exclude UI-removal; covered by build + live verification
- GIVEN an admin user on the Pipelinq admin settings page
- THEN the page MUST NOT display a "REST API Authentication" section
- AND API consumer credentials MUST be managed in OpenRegister via `/api/consumers`

#### Scenario: Removed token + OAuth endpoints are not routed
@e2e exclude route removal; covered by route inspection + PHPUnit
- GIVEN the Pipelinq routing table
- THEN there MUST be no route named `settings#listTokens`, `settings#generateToken`, `settings#revokeToken`, or `settings#saveOAuth`
- AND `SettingsController` MUST NOT define `listTokens`, `generateToken`, `revokeToken`, or `saveOAuth`
- AND the `ApiAuthService` class MUST NOT exist

## MODIFIED Requirements

### Requirement: REQ-AS-120: Object access control and MCP administration are OpenRegister-owned

The Pipelinq admin settings page MUST NOT provide per-schema "Objects API access" controls or
"MCP server" administration. Access control for the objects Pipelinq stores is enforced by
OpenRegister's permission layer using the `groups` arrays declared on each Register and Schema,
and the MCP server is owned and exposed by OpenRegister (`McpServerController`). Per ADR-022
(*Apps Consume OpenRegister Abstractions*), Pipelinq MUST NOT re-implement either capability as
leaf-app configuration. Specifically, the page MUST NOT render an "Objects API Access" section
or an "MCP Server Administration" section, and the backend MUST NOT expose endpoints that
persist a per-schema allowed-groups map (`objecten_access_*`) or MCP server credentials
(`mcp_*`) as Pipelinq application config.

#### Scenario: No Objects API Access section on the settings page
@e2e exclude UI-removal; covered by build + live verification
- GIVEN an admin user on the Pipelinq admin settings page
- THEN the page MUST NOT display an "Objects API Access" section
- AND object-level access control MUST be configured in OpenRegister on the relevant Register/Schema `groups`

#### Scenario: No MCP Server Administration section on the settings page
@e2e exclude UI-removal; covered by build + live verification
- GIVEN an admin user on the Pipelinq admin settings page
- THEN the page MUST NOT display an "MCP Server Administration" section
- AND MCP server access MUST be configured in OpenRegister

#### Scenario: Settings read payload excludes the removed maps
@e2e exclude API payload shape; covered by PHPUnit
- GIVEN an admin user calls `GET /api/settings`
- THEN the response MUST NOT include an `objectenAccess` key
- AND the response MUST NOT include an `mcpConfig` key
- AND the response MUST NOT include an `apiTokens` key
- AND the response MUST NOT include an `oauthConfig` key

#### Scenario: Removed endpoints are not routed
@e2e exclude route removal; covered by route inspection + PHPUnit
- GIVEN the Pipelinq routing table
- THEN there MUST be no route named `settings#getObjectenAccess`, `settings#saveObjectenAccess`, or `settings#saveMcp`
- AND `SettingsController` MUST NOT define `getObjectenAccess`, `saveObjectenAccess`, or `saveMcp`

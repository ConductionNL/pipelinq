# Admin Settings — delta

## ADDED Requirements

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
- AND the response MUST still include `apiTokens` and `oauthConfig`

#### Scenario: Removed endpoints are not routed
@e2e exclude route removal; covered by route inspection + PHPUnit
- GIVEN the Pipelinq routing table
- THEN there MUST be no route named `settings#getObjectenAccess`, `settings#saveObjectenAccess`, or `settings#saveMcp`
- AND `SettingsController` MUST NOT define `getObjectenAccess`, `saveObjectenAccess`, or `saveMcp`

# Admin Settings — Spec

## Purpose

Define the requirements for extended admin settings in Pipelinq covering: Objects API access restrictions by object type, REST API token and OAuth 2.0 authentication configuration, idToken forwarding for OpenID Connect, and MCP Server administration.

**Change:** `admin-settings`
**App:** Pipelinq — CRM and customer interaction
**Platform:** Nextcloud + OpenRegister

---

## Stakeholders

| Role | Description | Goals |
|------|-------------|-------|
| Nextcloud Administrator | IT staff responsible for configuring and managing the Pipelinq installation | Configure secure API access, restrict data exposure by role, integrate with external systems |
| Security Officer | Responsible for data governance and access control compliance | Ensure only authorized groups access specific object types via the Objecten API |
| Integration Developer | External developer connecting ERP or workflow systems to Pipelinq via REST API | Obtain API tokens or OAuth 2.0 credentials to authenticate integration flows |
| MCP Client Operator | Staff operating AI assistants or LLM tooling that communicates with Pipelinq via MCP | Configure and verify MCP server connectivity and authentication |

---

## REQ-ADM-001: Admin Settings Registration and Access Control

The Pipelinq admin settings page MUST be accessible exclusively to Nextcloud administrators.

### Scenario: Admin accesses the settings page

```
GIVEN a user with Nextcloud admin privileges
WHEN they navigate to Settings → Administration → Pipelinq
THEN the Pipelinq admin settings page MUST be displayed
AND it MUST show the CnVersionInfoCard as the first component
AND it MUST show the CnRegisterMapping section
AND it MUST show the Objects API Access, REST API Authentication, and MCP Server sections
```

### Scenario: Non-admin cannot access admin settings

```
GIVEN a regular Nextcloud user (non-admin)
WHEN they navigate to the Pipelinq admin settings URL
THEN Nextcloud MUST deny access with HTTP 403
AND the user MUST NOT see any Pipelinq admin configuration
```

### Scenario: Admin authorization enforced on all mutations

```
GIVEN a non-admin user
WHEN they POST to any /api/settings/* mutation endpoint
THEN the backend MUST return HTTP 403
AND the response MUST contain a static error message (no internal details)
```

---

## REQ-ADM-002: Objects API Access Control by Object Type

The admin MUST be able to restrict which Nextcloud groups can access each object type (schema) via the Objecten API. Access defaults to open (all authenticated users) if no restriction is configured.

### Scenario: Admin configures group access for a schema

```
GIVEN the admin opens the "Objects API Access" settings section
WHEN they select one or more Nextcloud groups for a schema (e.g. "client") and save
THEN the backend MUST store the group IDs in IAppConfig under key objecten_access_client
AND a success notification MUST be displayed
AND the saved groups MUST be shown when the page reloads
```

### Scenario: Access restriction enforced for restricted schema

```
GIVEN the "lead" schema is restricted to the group "sales-team"
AND a user who is NOT a member of "sales-team" calls the Objecten API for lead objects
WHEN the API request is evaluated
THEN the response MUST be HTTP 403
AND no lead objects MUST be returned
```

### Scenario: Unrestricted schema remains open

```
GIVEN the "contact" schema has no group access configured
WHEN any authenticated user calls the Objecten API for contact objects
THEN the request MUST succeed with HTTP 200
AND contact objects MUST be returned per normal authorization
```

### Scenario: Admin removes all group restrictions

```
GIVEN a schema has existing group restrictions
WHEN the admin clears all groups and saves
THEN the IAppConfig key for that schema MUST be removed or set to an empty list
AND the schema reverts to open access (all authenticated users)
```

### Scenario: No schemas registered

```
GIVEN OpenRegister has no registered schemas for Pipelinq
WHEN the admin opens the "Objects API Access" section
THEN an empty state message MUST be shown: "No schemas registered. Run re-import first."
```

---

## REQ-ADM-003: REST API Token Authentication

Administrators MUST be able to generate, list, and revoke API bearer tokens for external system integrations.

### Scenario: Admin generates a new API token

```
GIVEN the admin opens the "REST API Authentication" section and selects the Tokens tab
WHEN they click "Generate Token" and enter a label (e.g. "ERP Integration")
AND click "Generate"
THEN a cryptographically random token (minimum 256-bit entropy) MUST be generated
AND the token MUST be displayed once in the dialog in a read-only input with a copy button
AND the token MUST NOT be retrievable again after the dialog is closed
AND only the SHA-256 hash MUST be stored in IAppConfig
AND the token list MUST show the new token entry with label, creation date, and "Never" as last-used date
```

### Scenario: Admin lists existing tokens

```
GIVEN one or more API tokens exist
WHEN the admin opens the Tokens tab
THEN a table MUST be displayed with columns: Label, Created, Last Used, Actions
AND each row MUST show the token label, creation timestamp, last-used timestamp (or "Never"), and a Revoke button
AND token hashes MUST NOT be shown in the UI or returned by the API
```

### Scenario: Admin revokes a token

```
GIVEN the admin clicks "Revoke" on a token row
WHEN they confirm the action
THEN the token MUST be deleted from IAppConfig
AND the token row MUST be removed from the list
AND subsequent API calls using the revoked token MUST be rejected with HTTP 401
```

### Scenario: Token validated on API call

```
GIVEN a valid API token exists
WHEN an external system sends a request with Authorization: Bearer <token>
THEN the backend MUST hash the token and compare against stored hashes
AND if a match is found, the request MUST be authenticated
AND the matching token's lastUsed field MUST be updated
```

---

## REQ-ADM-004: REST API OAuth 2.0 Configuration

Administrators MUST be able to configure OAuth 2.0 client credentials and settings for external system authentication.

### Scenario: Admin saves OAuth 2.0 configuration

```
GIVEN the admin opens the OAuth 2.0 tab in "REST API Authentication"
WHEN they enter: Client ID, Client Secret, Token Endpoint URL, Authorization Endpoint URL, and Scopes
AND click "Save OAuth Configuration"
THEN the non-sensitive fields (Client ID, endpoints, scopes) MUST be stored in IAppConfig
AND the Client Secret MUST be stored in IAppConfig with sensitive: true
AND the Client Secret MUST NOT be returned in any API response
AND a success notification MUST be displayed
```

### Scenario: Admin views existing OAuth configuration

```
GIVEN OAuth 2.0 is already configured
WHEN the admin opens the OAuth 2.0 tab
THEN the Client ID, endpoints, and scopes fields MUST be pre-populated
AND the Client Secret field MUST show a placeholder (e.g. "••••••••") indicating a value is stored
AND the placeholder MUST NOT reveal any characters of the actual secret
```

### Scenario: Admin updates only non-secret fields

```
GIVEN OAuth 2.0 is already configured with a client secret
WHEN the admin updates only the Token Endpoint URL and saves
THEN the existing client secret MUST remain unchanged in IAppConfig
AND the updated endpoint MUST be stored
```

---

## REQ-ADM-005: idToken Forwarding for OpenID Connect

When OAuth 2.0 with `openid` scope is configured, the admin MUST be able to enable idToken forwarding for subsequent API calls.

### Scenario: Admin enables idToken forwarding

```
GIVEN the OAuth 2.0 configuration includes "openid" in the scopes
WHEN the admin enables the "Forward idToken" toggle and saves
THEN the setting oauth_id_token_forwarding MUST be stored as true in IAppConfig
AND a success notification MUST be displayed
```

### Scenario: idToken forwarded in authenticated requests

```
GIVEN oauth_id_token_forwarding is true
AND a successful OAuth 2.0 login with openid scope returns an id_token
WHEN the system makes subsequent API calls on behalf of the authenticated session
THEN the id_token MUST be forwarded as the X-Id-Token HTTP header
```

### Scenario: idToken not forwarded when toggle is off

```
GIVEN oauth_id_token_forwarding is false (or not configured)
WHEN API calls are made after OAuth login
THEN no X-Id-Token header MUST be sent
```

---

## REQ-ADM-006: MCP Server Administration

Administrators MUST be able to configure a Model Context Protocol (MCP) server endpoint with either OAuth 2.0 or API key authentication.

### Scenario: Admin configures MCP server with API key authentication

```
GIVEN the admin opens the "MCP Server Administration" section
WHEN they enter an endpoint URL (e.g. https://mcp.example.nl/pipelinq), select "API Key" auth mode, enter an API key, and save
THEN mcp_endpoint and mcp_auth_mode: "apikey" MUST be stored in IAppConfig
AND the API key MUST be stored in IAppConfig with sensitive: true
AND the API key MUST NOT be returned in any API response
AND a success notification MUST be displayed
```

### Scenario: Admin configures MCP server with OAuth 2.0 authentication

```
GIVEN the admin opens the "MCP Server Administration" section
WHEN they select "OAuth 2.0" as auth mode, enter MCP OAuth Client ID and Client Secret, and save
THEN mcp_auth_mode: "oauth2", mcp_oauth_client_id MUST be stored in IAppConfig
AND mcp_oauth_client_secret MUST be stored with sensitive: true
AND neither secret MUST be returned in API responses
```

### Scenario: Auth mode change clears irrelevant credential fields

```
GIVEN MCP is configured with API key auth
WHEN the admin switches auth mode to "OAuth 2.0" and saves
THEN only OAuth 2.0 credential fields MUST be shown and saved
AND the previous API key MUST be removed from IAppConfig
```

### Scenario: Admin views MCP configuration

```
GIVEN MCP is already configured
WHEN the admin opens the MCP Server section
THEN the endpoint URL and auth mode MUST be pre-populated
AND credential fields MUST show a placeholder (e.g. "••••••••") for stored secrets
```

---

## Non-Functional Requirements

### NFR-ADM-001: Secret handling

All credentials (client secrets, API keys, tokens) MUST be:
- Stored in `IAppConfig` with `sensitive: true`
- Never returned in any API response body
- Never logged (no PII or secrets in log output per ADR-005-security)

### NFR-ADM-002: Authorization on all mutations

All POST and DELETE endpoints under `/api/settings/*` MUST:
- Verify `IGroupManager::isAdmin($userId)` on the backend before processing
- Return HTTP 403 with a static error message for unauthorized requests
- Never rely on frontend-only auth checks

### NFR-ADM-003: Accessibility and responsive design

The admin settings UI MUST:
- Meet WCAG AA compliance: all inputs MUST have associated labels
- Be keyboard-navigable (tab order, focus management in dialogs)
- Function correctly at 768px viewport width (tablet)
- Use NL Design System CSS custom properties (no hardcoded colors per ADR-010)

### NFR-ADM-004: Translation coverage

All user-visible strings in new UI panels MUST:
- Use `this.t(appName, 'key')` in Vue templates (never hardcoded Dutch or English)
- Have matching entries in both `l10n/en.json` (English) and `l10n/nl.json` (Dutch)
- Use English as the translation key (not Dutch)

# customer-portal Specification Delta

## ADDED Requirements

### Requirement: Widget-mode requests are gated by the tenant's origin allow-list (REQ-PORTAL-ORIGIN)

Every portal API request resolves its tenant through a single server-trusted
gate (`PortalRequestGuard::resolveTenant()`) from the host/subdomain or the
`X-Portal-Tenant` header. When — and only when — the tenant is asserted via the
`X-Portal-Tenant` header (widget mode, the sole cross-origin entry point), the
system MUST enforce the tenant's widget-origin allow-list: the request's
`Origin` MUST appear in the tenant's `widgetAllowedOrigins` (and the tenant MUST
have `widgetEmbedAllowed` enabled), or the request MUST be rejected with HTTP
403 before any portal action (login, data read, request create) runs. First-party
requests (host/subdomain, no `X-Portal-Tenant` header) MUST NOT be subject to
this check. The check MUST fail closed: a tenant that has not enabled widget
embedding rejects every widget-mode request.

@e2e exclude backend cross-origin tenant-resolution gate — covered by PHPUnit, no browser UI (the boundary is an HTTP Origin/header check on the API, not a screen)

#### Scenario: a widget-mode request from a non-allow-listed origin is rejected

- **GIVEN** a request carrying `X-Portal-Tenant: <tenant>` and an `Origin`
  header that is not in that tenant's `widgetAllowedOrigins`
- **WHEN** the portal request guard resolves the tenant
- **THEN** it SHALL throw a 403 and no portal action SHALL run

#### Scenario: a widget-mode request from an allow-listed origin resolves

- **GIVEN** a request carrying `X-Portal-Tenant: <tenant>` and an `Origin`
  header present in that tenant's `widgetAllowedOrigins` (with
  `widgetEmbedAllowed` enabled)
- **WHEN** the portal request guard resolves the tenant
- **THEN** it SHALL return the resolved tenant id and the request proceeds

#### Scenario: a first-party request is not subject to the origin gate

- **GIVEN** a request with no `X-Portal-Tenant` header (host/subdomain mode)
- **WHEN** the portal request guard resolves the tenant
- **THEN** the widget-origin allow-list SHALL NOT be consulted and the tenant
  SHALL resolve from the host signal unchanged

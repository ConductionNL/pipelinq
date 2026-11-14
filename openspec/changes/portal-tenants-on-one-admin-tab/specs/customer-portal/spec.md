## ADDED Requirements

### Requirement: The admin tab lists every portal tenant

The customer portal admin tab MUST list every stored portal tenant with its name, domain, colour, the tabs residents see and its status, and MUST let an administrator open one to edit it or add a new one. Only administrators may read the list.

#### Scenario: Three portals on one install
- GIVEN three `portalTenantConfig` records, two live and one draft
- WHEN an administrator opens the customer portal tab
- THEN the Portalen tab reads 3
- AND each portal is one row with its domain, colour, tabs in words and a Live or Concept pill
- AND the first portal's form is open below the list
- @e2e exclude admin settings screen; covered by vitest `portalTenantManager.spec.js` and PHPUnit `PortalAdminControllerTest`

#### Scenario: A non-admin asks for the list
- GIVEN a logged-in user who is not an administrator
- WHEN they request `GET /portal/api/admin/tenants`
- THEN the answer is 403 and lists nothing
- @e2e exclude covered by PHPUnit `PortalAdminControllerTest`

### Requirement: A portal can be prepared before it goes live

A portal tenant MUST carry a status, `live` or `draft`; a tenant without one is live.

#### Scenario: Saving a draft portal
- GIVEN an administrator adds a portal and sets its status to Concept
- WHEN they save it
- THEN the record stores `status: draft` and the list shows a Concept pill
- @e2e exclude covered by vitest `portalTenantManager.spec.js`

### Requirement: The admin tab shows the latest portal actions across portals

The admin tab MUST show the latest portal audit events of all tenants, newest first, with the portal each belongs to, and the total count, and MUST offer them as a CSV download.

#### Scenario: Latest actions
- GIVEN audit events in two tenants
- WHEN an administrator opens the customer portal tab
- THEN "Laatste acties in het portaal" lists them newest first with a Portaal column
- AND the Auditlog tab shows the total count
- AND Downloaden saves the shown events as CSV
- @e2e exclude covered by vitest `portalTenantManager.spec.js` and PHPUnit `PortalAdminControllerTest`

### Requirement: A request reaches the portal tenant of its host

The portal MUST resolve the tenant of every request from server-trusted signals only: first a tenant whose custom domain equals the request host, then a tenant whose subdomain equals the host's first label, then the widget header, and otherwise the default tenant. It MUST NOT read a tenant id from the request body or query string, so each tenant's residents see only that tenant's branding, tabs and records.

#### Scenario: Two portals on their own domains
- GIVEN tenant `noord` with custom domain `klant.noord.example` and tenant `zuid` with subdomain `zuid`
- WHEN a resident opens `https://klant.noord.example`
- THEN the portal serves tenant `noord` with its colour and tabs
- AND a request to `https://zuid.portal.example` serves tenant `zuid`
- @e2e exclude host resolution needs DNS; unit test planned in T7

#### Scenario: A tenant id in the query string is ignored
- GIVEN a request to the host of tenant `noord` with `?tenantId=zuid`
- WHEN the portal resolves the tenant
- THEN it serves tenant `noord`
- @e2e exclude unit test planned in T7

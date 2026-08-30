# Design: customer-portal

## Architecture Overview

The customer portal operates in a completely separate auth domain from Nextcloud. Portal accounts, sessions, and data views are isolated in the `pipelinq-portal` register and accessed only via `/portal/api/` routes. Nextcloud user sessions have zero access to portal routes, and portal sessions have zero access to Nextcloud routes.

```
┌─ Nextcloud Core ─────────────────────────────────────────────┐
│  Nextcloud users, groups, permissions, Files, Calendar, Mail │
│  (SEPARATE AUTH DOMAIN — no interaction with portal)          │
└──────────────────────────────────────────────────────────────┘

┌─ Pipelinq CRM (pipelinq register) ───────────────────────────┐
│  client, contact, request, lead, pipeline, etc.              │
│  (read-only access from portal via cross-app facades)        │
└──────────────────────────────────────────────────────────────┘

┌─ Pipelinq Portal (pipelinq-portal register) ─────────────────┐
│  ┌─ portal_account ──────────────────────┐                    │
│  │ id, email, passwordHash, mfaSecret    │                    │
│  │ accountType (b2b|b2c)                 │                    │
│  │ linkedContactId, linkedOrganisationId │                    │
│  │ status, failedLoginAttempts, etc.     │                    │
│  └────────────────────────────────────────┘                    │
│  ┌─ portal_session ──────────────────────┐                    │
│  │ id, accountId, tokenHash              │                    │
│  │ expiresAt, ipHash, revoked            │                    │
│  └────────────────────────────────────────┘                    │
│  ┌─ portal_delegation ───────────────────┐                    │
│  │ granterAccountId, granteeAccountId    │                    │
│  │ scopes (view-invoices, etc.)          │                    │
│  │ validFrom, validUntil                 │                    │
│  └────────────────────────────────────────┘                    │
│  ┌─ portal_audit_event ──────────────────┐                    │
│  │ accountId, eventType                  │                    │
│  │ targetObjectType, targetObjectId      │                    │
│  │ outcome (success|denied|error)        │                    │
│  └────────────────────────────────────────┘                    │
│  ┌─ portal_tenant_config ────────────────┐                    │
│  │ tenantId, displayName, logo           │                    │
│  │ brandPrimaryColor, customDomain       │                    │
│  │ enabledFeatures, b2bEnabled, etc.     │                    │
│  └────────────────────────────────────────┘                    │
└──────────────────────────────────────────────────────────────┘

┌─ Request Routing & Tenant Resolution ──────────────────────┐
│  CustomDomainResolver: hostname → tenantId                  │
│  WidgetOriginValidator: origin → widgetAllowedOrigins      │
│  All requests scope to tenantId at route middleware         │
└───────────────────────────────────────────────────────────┘

┌─ API Routes ──────────────────────────────────────────────┐
│ /portal/api/auth           — login, logout, password reset │
│ /portal/api/accounts       — profile view/update           │
│ /portal/api/invoices       — read-only (from shillinq)    │
│ /portal/api/contracts      — read-only (from client-mgmt) │
│ /portal/api/orders         — read-only (from quoter)      │
│ /portal/api/requests       — read/create (to request-mgmt) │
│ /portal/api/documents      — signed-URL download proxy    │
│ /portal/api/delegations    — B2B scope grants (view/crud) │
│ /portal/api/tenant-config  — branding, features (admin)   │
│ /portal/api/audit-events   — read own events (user + dpo) │
│ /portal/api/exports        — AVG data export request       │
│ /portal/api/account-close  — AVG account closure request   │
└───────────────────────────────────────────────────────────┘

┌─ Frontend Routes ─────────────────────────────────────────┐
│ /portal/login              — login / password reset form   │
│ /portal/dashboard          — invoices, contracts, orders   │
│ /portal/requests           — request list and details      │
│ /portal/profile            — account edit                  │
│ /portal/delegations        — manage B2B access (B2B only)  │
│ /portal/export-request     — AVG data export               │
│ /portal/account-close      — AVG account closure           │
│ /portal/admin              — tenant branding, config       │
│ /portal/widget             — embedded iframe version       │
└───────────────────────────────────────────────────────────┘
```

## Key Design Decisions

### 1. Separate Auth Domain (REQ-001)

**Decision**: Portal auth is completely independent from Nextcloud. `portal_account` and `portal_session` are stored in the `pipelinq-portal` register. Portal tokens are checked only at `/portal/api/` routes; Nextcloud middleware never validates them and vice versa.

**Rationale**: Prevents privilege escalation attacks and ensures compliance audits can certify complete isolation. If a portal session token is leaked, the attacker can only access `/portal/api/` endpoints (read-only data + profile update). They cannot access Nextcloud Files, Calendar, or admin areas.

**Implementation**:
- `PortalAuthMiddleware` on all `/portal/` routes: extracts token from `Authorization: Bearer <token>`, validates against `portal_session.tokenHash`, sets `$request->portalAccountId`
- Nextcloud `AuthMiddleware` explicitly rejects portal tokens (different token format or explicit check)
- No SSO short-circuit: even if a Nextcloud user's email matches a portal account, both must authenticate separately
- Audit logging: `portal_audit_event` with `eventType: login-success|login-failure` for every auth attempt

### 2. Multi-Tenant Isolation (REQ-002)

**Decision**: Every request is scoped to a single `tenantId` resolved from one of:
1. Custom domain (e.g. `klant.example.nl` → `portal_tenant_config.customDomain`)
2. Subdomain pattern (e.g. `alpha.portal.pipelinq.nl` → `alpha`)
3. Explicit header in widget mode (e.g. `X-Portal-Tenant: alpha`)

All queries include `WHERE tenantId = <resolved>` at the database level. Cross-tenant data queries return 404 (not 403) to prevent existence leaks.

**Rationale**: Enforces tenant isolation at the query layer, not just application logic. If `portal_account` is accessed by mistake without tenant filtering, the database constraint prevents data leak.

**Implementation**:
- `TenantResolver` middleware: parses hostname/header, looks up in `portal_tenant_config`, sets `$request->tenantId`
- All read queries include `AND tenantId = ?` binding
- All write queries validate target entity belongs to `$request->tenantId`
- Existing contacts/orgs are filtered by their own `tenantId` (set when linked to portal account)

### 3. B2B Delegation with Scope Filtering (REQ-003)

**Decision**: B2B accounts can grant colleagues `view-invoices`, `view-contracts`, `submit-requests` scopes via `portal_delegation` records. Grantee data includes both own data and delegated data, tagged with `delegatedFrom: grantorAccountId`.

**Rationale**: Dutch B2B practice (inkoop-medewerker delegates to colleague on vacation). Granular scopes allow security: can grant "read contracts" without "submit requests". Delegation is temporal (validFrom/validUntil) and revocable.

**Implementation**:
- `PortalDelegationService::getActiveScopes($accountId)` returns array of `{grantorAccountId, scopes[]}`
- Read facades filter: own data always included, then append data from each grantee with matching scope + valid date range
- Each result object tagged: `{invoice: {...}, delegatedFrom: null}` (own) or `{invoice: {...}, delegatedFrom: "uuid"}`
- Grantee cannot re-delegate (no transitive grants)

### 4. Read Facades over Cross-App Data (REQ-003)

**Decision**: Invoices, contracts, orders, quotes are stored in `shillinq`, `client-management`, `product-catalog-quoting` registers. Portal reads them via typed service facades (`InvoiceReadService`, `ContractReadService`, etc.) that:
- Filter by linked contact/org
- Exclude deleted records
- Apply tenant scoping
- Respect delegation scopes
- Convert to portal API shape (schema.org + Dutch labels)

**Rationale**: Portal is read-only on these domains. Storing copies would create sync debt. Service facades keep query logic centralized and testable.

**Implementation**:
- `PortalInvoiceService::getForAccount($accountId)` → queries shillinq register, filters by linkedContactId or linkedOrganisationId, returns paginated array
- `PortalContractService::getForContact($contactId)` → queries client-management, same pattern
- Each facade handles locale-aware label mapping (Dutch field names in API response, e.g. `bedrag` not `amount`)

### 5. Document Download via Signed URLs (REQ-005)

**Decision**: Instead of direct Nextcloud file paths, document downloads go through a proxy endpoint `/portal/api/documents/<signedToken>/download`. The token is:
- Generated by `DocumentSigningService::generateUrl($objectId, $objectType, $ttlMinutes=5)`
- Contains a signed HMAC-SHA256 payload: `{objectId, objectType, issuedAt, expiresAt}`
- Validated on each request; expired tokens return 410 Gone
- Logged in `portal_audit_event` with `eventType: document-download`, `targetObjectType`, `targetObjectId`

**Rationale**: Keeps file paths out of browser, prevents URL guessing, creates audit trail for DPO.

**Implementation**:
- `DocumentSigningService` uses `ISecureRandom` for signing key (stored in `IAppConfig` with `sensitive: true`)
- Token format: `base64(HMAC_SHA256(objectId|objectType|issuedAt|expiresAt, signingKey))`
- `/portal/api/documents/{token}/download` extracts token, validates sig and TTL, calls `FilesService` to fetch file, streams response, logs event

### 6. Portal Request Submission (REQ-006)

**Decision**: Portal users submit requests via `POST /portal/api/requests` with `{subject, body, attachmentIds, categoryId}`. The API:
- Filters categories: only `exposeToCustomer: true` categories allowed
- Creates `request` in request-management register with `submittedVia: portal`, `reporterContactId` set from portal account's linked contact
- Stores attachments under portal-scoped folder (e.g. `/Pipelinq/Portal/Requests/{requestId}/`)
- Dispatches `RequestSubmittedEvent` to SLA engine
- Returns request ID and ETA (from SLA config)

**Rationale**: Portal becomes visible in medewerker workflow immediately; SLA clock starts.

**Implementation**:
- `PortalRequestService::submit($portalAccountId, $subject, $body, $attachmentIds, $categoryId)`
- Validate `categoryId` against `request-management` category list with `exposeToCustomer` check
- Create `request` with `reporterContactId` (from `portal_account.linkedContactId`)
- Move uploaded files (temp staging) to final folder
- Emit event for SLA engine
- Return `{requestId, estimatedResponseTime}`

### 7. Profile Self-Update with Audit (REQ-007)

**Decision**: Portal users update name, phone, billing address, language via `PUT /portal/api/accounts/profile`. Changes are:
- Applied to `portal_account` (displayName, locale)
- Synced to `client-management` contact (if linked contact exists)
- Logged in `portal_audit_event` with `eventType: profile-update`, `details: {fieldName, previousValue, newValue}`
- Email changes require verification link (old email receives code, new email must verify before taking effect)

**Rationale**: Audit trail for DPO; sync with contact master data; verification prevents typos locking out user.

**Implementation**:
- `PortalProfileService::update($accountId, $changes)` — atomic update of both tables
- For email: generate verification token via `PasswordResetTokenService`, send link to new email, keep old email as login credential until verification
- Audit: loop over changed fields, emit event per field

### 8. B2B Delegation CRUD (REQ-003)

**Decision**: B2B account holders can create/revoke delegations via:
- `POST /portal/api/delegations` — grant colleague access (requires email + scopes + validUntil)
- `DELETE /portal/api/delegations/{delegationId}` — revoke access
- `GET /portal/api/delegations` — list active delegations granted by me

**Rationale**: Supports organizational workflow (delegation during vacation, contractor access).

**Implementation**:
- `PortalDelegationService::create($granterAccountId, $granteeEmail, $scopes, $validUntil)` — lookup or invite grantee, create `portal_delegation` record
- If grantee account doesn't exist, send invite email to create account (link includes pre-filled email)
- Revoke: set `portal_delegation.revokedAt` (soft delete for audit trail)

### 9. Tenant Configuration & Branding (REQ-002, REQ-009)

**Decision**: Tenant admin configures portal via dedicated UI at `/portal/admin` (requires admin token or Nextcloud admin SU). Configuration lives in `portal_tenant_config`:
- `displayName`, `logoFileId`, `faviconFileId` — branding
- `brandPrimaryColor`, `brandSecondaryColor` (hex) — theme colors
- `customDomain` — `klant.example.nl`
- `enabledFeatures` — array of `contracts`, `orders`, `invoices`, `quotes`, `requests`, `documents`, `profile`
- `b2bEnabled`, `b2cEnabled` — disable B2B or B2C mode
- `widgetEmbedAllowed`, `widgetAllowedOrigins` — CORS/widget config
- `supportContact` — email + phone shown on every page
- `termsVersion`, `privacyVersion` — current T&C version for acceptance tracking

**Contrast validation**: If computed contrast ratio (primary color on background) < 4.5:1, API rejects with 422 and contrast readout.

**Rationale**: MKB tenants can self-serve branding in 15 minutes; NL Design System CSS variables auto-apply gov styling if opted in.

**Implementation**:
- `PortalTenantService::saveBranding($tenantId, $config)` — validate contrast via `ContrastRatioCalculator` (WCAG 2023), reject if < 4.5:1
- Admin UI: `AdminTenantConfigPanel.vue` — inputs for each field, live preview of colors, "Test Contrast" button
- Frontend: load config from `/portal/api/tenant-config`, apply CSS variables: `--portal-brand-primary`, `--portal-brand-secondary`

### 10. Widget Embeddability (REQ-008)

**Decision**: Portal can be embedded as an iframe on external sites (e.g. MKB customer's Wix page). Widget URL: `https://portal.pipelinq.nl/portal/widget?tenant=alpha`. The iframe:
- Loads in sandboxed mode (no scripts from parent page)
- Makes all API requests with `X-Portal-Tenant: alpha` header (tenant resolved from URL param, validated against `portal_tenant_config.widgetAllowedOrigins`)
- Emits postMessage events: `{type: 'portal:resize', height: N}` on content changes
- Emits `{type: 'portal:logout'}` on logout so parent page can update UI

**Rationale**: MKB customers embed portal on own website without hosting separate domain.

**Implementation**:
- `PortalWidgetMiddleware` — extracts `tenant` query param, validates origin against `portal_tenant_config.widgetAllowedOrigins`, enforces CORS
- Frontend: detect iframe mode (`window.self !== window.top`), hide global nav, use compact chrome
- `PortalResizeObserver` — monitor content height, emit postMessage on change
- Logout handler: emit message, clear local session

### 11. WCAG 2.2 AA Compliance (REQ-009)

**Decision**: Every portal page must pass WCAG 2.2 AA checks:
- **Keyboard nav**: all interactive elements reachable via Tab, Enter/Space work, focus always visible
- **Screen reader**: semantic HTML, ARIA labels on form fields, live regions for session timeout warning, logical reading order
- **Color contrast**: 4.5:1 min for text on background (enforced at brand config save time)
- **Focus**: visible focus indicator on all focusable elements, focus order matches logical flow
- **Session timeout**: warning appears at 60-second mark before expiry, live region announces warning, dismissible

**Rationale**: Mandatory for any Dutch public-sector customer (DigiToegankelijk audit). Shipping without AA compliance forces tenant remediation work.

**Implementation**:
- All form inputs: `<label htmlFor="...">` + `aria-describedby` for error messages
- Invoice list: `<table role="grid">` with header row `<th scope="col">`, rows announce as grid cells
- Timeout warning: `<div aria-live="polite">` updated 60 sec before expiry
- Brand colors: contrast ratio validated at save time (contrast calculator per WCAG 2023 formula)
- Color picker: display contrast ratio live; warn if < 4.5:1

### 12. Audit Trail (REQ-007, REQ-010)

**Decision**: `portal_audit_event` logs all sensitive actions:
- `eventType`: `login-success`, `login-failure`, `password-reset`, `mfa-enabled`, `profile-update`, `document-download`, `request-submit`, `data-export-requested`, `account-close`
- `outcome`: `success`, `denied`, `error`
- `details`: event-specific object (e.g. `{fieldName: phone, previousValue, newValue}`)
- Queryable by user (own events only) and by DPO (all events for tenant)

**Rationale**: Supports DPO's AVG Art. 15 (data export) and compliance audits. Immutable record of who did what when.

**Implementation**:
- `PortalAuditService::log($accountId, $eventType, $outcome, $details)` — always called on sensitive actions
- DPO endpoint: `GET /portal/api/audit-events?tenantId=...` (Nextcloud admin only) returns all events for tenant

### 13. AVG Compliance (REQ-010)

**Decision**: Portal supports two AVG workflows:

**Data Export (Art. 15)**: User requests export via `POST /portal/api/exports`. Backend queues a job that:
1. Collects contact record (name, email, phone, address)
2. Collects all `portal_audit_event` records for the account
3. Collects all documents the user could access (contracts, invoices, quotes, attachments)
4. Generates JSON + PDF bundle
5. Delivers via signed link (valid 30 days, per AVG 30-day fulfillment requirement)

**Account Closure (Art. 17)**: User requests closure via `PUT /portal/api/accounts/close` with email confirmation link:
1. Sets `portal_account.status = closed`
2. Revokes all `portal_session` records
3. Retains `linkedContactId` (legal retention: contracts and invoices have 7-year retention under Dutch tax law)
4. At night, cleanup job pseudonymizes contact if retention obligations met

**Rationale**: Compliance with Dutch AVG implementation; nightly cleanup respects Wet op de loonbelasting 7-year obligation.

**Implementation**:
- `PortalExportService::requestExport($accountId)` — enqueue job, return estimated completion time
- `PortalAccountService::close($accountId)` — requires email confirmation token, sets status, revokes sessions
- Nightly `PortalCleanupCommand` — query closed accounts, check retention rules from `client-management`, pseudonymize if safe

## Reuse Analysis

Per ADR-012-deduplication, the following existing services are leveraged:

| Capability | Service/Component |
|------------|-------------------|
| Password hashing (argon2id) | `IHasher` (Nextcloud core) |
| Cryptographic token generation | `ISecureRandom` (Nextcloud core) |
| Session storage & TTL | `pipelinq-portal` register (custom, separate from NC sessions) |
| TOTP MFA | `PHPGangsta_GoogleAuthenticator` (one-time-password library) |
| Email delivery | `IMailer` (Nextcloud core) |
| File abstraction | `IRootFolder` (Nextcloud Files) |
| Rate limiting | Nextcloud HTTP middleware (per-IP tracking) |
| Translation keys | `IFactory` + `IL10N` (Nextcloud i18n core) |
| Contact sync | `client-management` `ContactService` (cross-app) |
| Invoice reads | `shillinq` `InvoiceQueryService` (cross-app facade) |
| Contract reads | `client-management` `ContractQueryService` (cross-app facade) |
| Order reads | `product-catalog-quoting` `OrderQueryService` (cross-app facade) |
| Request creation | `request-management` `RequestCreationService` (cross-app) |
| HMAC signing | `hash_hmac()` (PHP core) + `ISecureRandom` for key |
| Contrast ratio calculation | WCAG 2023 formula (custom calculator, ~50 LOC) |

Search notes: Nextcloud core has no built-in portal auth system. The `user` table and `nc_sessions` table are Nextcloud-user-specific. `IUserManager` and `ISessionProvider` handle Nextcloud users only. No overlap found with existing Pipelinq auth (which uses Nextcloud users for employee logins).

## Seed Data

### portal_account (5 examples — Dutch B2B and B2C users)

```json
{
  "email": "jan@pietersen-bv.nl",
  "displayName": "Jan Pietersen",
  "accountType": "b2b",
  "locale": "nl",
  "linkedContactId": "uuid-contact-pietersen",
  "linkedOrganisationId": "uuid-org-pietersen-bv",
  "status": "active",
  "acceptedTermsVersion": "1.0",
  "acceptedPrivacyVersion": "1.0",
  "mfaEnforced": false,
  "failedLoginAttempts": 0,
  "lastLoginAt": "2026-05-20T14:30:00Z"
}
```

```json
{
  "email": "m.devries@example.nl",
  "displayName": "Mevrouw M. de Vries",
  "accountType": "b2c",
  "locale": "nl",
  "linkedContactId": "uuid-contact-devries",
  "linkedOrganisationId": null,
  "status": "active",
  "acceptedTermsVersion": "1.0",
  "acceptedPrivacyVersion": "1.0",
  "mfaEnforced": false,
  "failedLoginAttempts": 0,
  "lastLoginAt": "2026-05-21T09:15:00Z"
}
```

```json
{
  "email": "sophie@bakkerij-bakker.nl",
  "displayName": "Sophie Bakker",
  "accountType": "b2b",
  "locale": "nl",
  "linkedContactId": "uuid-contact-sophie",
  "linkedOrganisationId": "uuid-org-bakker-bv",
  "status": "active",
  "acceptedTermsVersion": "1.0",
  "acceptedPrivacyVersion": "1.0",
  "mfaEnforced": true,
  "mfaSecret": "<encrypted>",
  "failedLoginAttempts": 0,
  "lastLoginAt": "2026-05-21T16:45:00Z"
}
```

```json
{
  "email": "jeroen@groen-installatie.nl",
  "displayName": "Jeroen Groen",
  "accountType": "b2b",
  "locale": "nl",
  "linkedContactId": "uuid-contact-jeroen",
  "linkedOrganisationId": "uuid-org-groen-install",
  "status": "pending-verification",
  "acceptedTermsVersion": null,
  "acceptedPrivacyVersion": null,
  "mfaEnforced": false,
  "failedLoginAttempts": 0,
  "lastLoginAt": null
}
```

```json
{
  "email": "anna.mueller@post.de",
  "displayName": "Anna Müller",
  "accountType": "b2c",
  "locale": "de",
  "linkedContactId": "uuid-contact-mueller",
  "linkedOrganisationId": null,
  "status": "active",
  "acceptedTermsVersion": "1.0",
  "acceptedPrivacyVersion": "1.0",
  "mfaEnforced": false,
  "failedLoginAttempts": 0,
  "lastLoginAt": "2026-05-20T11:00:00Z"
}
```

### portal_tenant_config (2 examples — small and medium MKB)

```json
{
  "tenantId": "tenant-bakkerij",
  "displayName": "Bakkerij Bakker",
  "brandPrimaryColor": "#CC6633",
  "brandSecondaryColor": "#F5DEB3",
  "customDomain": "klant.bakkerij-bakker.nl",
  "logoFileId": "uuid-logo-bakker",
  "faviconFileId": "uuid-favicon-bakker",
  "enabledFeatures": ["invoices", "contracts", "requests", "documents", "profile"],
  "b2bEnabled": true,
  "b2cEnabled": false,
  "widgetEmbedAllowed": false,
  "selfSignupAllowed": false,
  "termsVersion": "1.0",
  "privacyVersion": "1.0",
  "supportContact": {
    "email": "support@bakkerij-bakker.nl",
    "phone": "+31 6 12345678"
  }
}
```

```json
{
  "tenantId": "tenant-gemeente",
  "displayName": "Gemeente Utrecht - Klantportaal",
  "brandPrimaryColor": "#004687",
  "brandSecondaryColor": "#E8E8E8",
  "customDomain": null,
  "logoFileId": "uuid-logo-gemeente",
  "faviconFileId": null,
  "enabledFeatures": ["invoices", "contracts", "orders", "quotes", "requests", "documents", "profile"],
  "b2bEnabled": true,
  "b2cEnabled": true,
  "widgetEmbedAllowed": true,
  "widgetAllowedOrigins": ["https://www.gemeente-utrecht.nl", "https://intranet.gemeente-utrecht.nl"],
  "selfSignupAllowed": false,
  "termsVersion": "2.0",
  "privacyVersion": "2.0",
  "supportContact": {
    "email": "klantportaal@gemeente-utrecht.nl",
    "phone": "030 286 0000"
  }
}
```

### portal_audit_event (5 examples — varied event types)

```json
{
  "accountId": "uuid-account-jan",
  "tenantId": "tenant-bakkerij",
  "eventType": "login-success",
  "outcome": "success",
  "occurredAt": "2026-05-21T08:30:00Z",
  "ipHash": "sha256-hash-ip-1",
  "userAgentHash": "sha256-hash-ua-chrome"
}
```

```json
{
  "accountId": "uuid-account-jan",
  "tenantId": "tenant-bakkerij",
  "eventType": "document-download",
  "outcome": "success",
  "targetObjectType": "invoice",
  "targetObjectId": "uuid-invoice-202405-001",
  "occurredAt": "2026-05-21T14:15:00Z",
  "ipHash": "sha256-hash-ip-1",
  "userAgentHash": "sha256-hash-ua-chrome"
}
```

```json
{
  "accountId": "uuid-account-jan",
  "tenantId": "tenant-bakkerij",
  "eventType": "profile-update",
  "outcome": "success",
  "occurredAt": "2026-05-21T16:45:00Z",
  "details": {
    "fieldName": "phone",
    "previousValue": "+31 6 11111111",
    "newValue": "+31 6 22222222"
  },
  "ipHash": "sha256-hash-ip-1",
  "userAgentHash": "sha256-hash-ua-chrome"
}
```

```json
{
  "accountId": "uuid-account-sophie",
  "tenantId": "tenant-bakkerij",
  "eventType": "request-submit",
  "outcome": "success",
  "targetObjectType": "request",
  "targetObjectId": "uuid-request-2026-0042",
  "occurredAt": "2026-05-21T10:20:00Z",
  "ipHash": "sha256-hash-ip-2",
  "userAgentHash": "sha256-hash-ua-safari"
}
```

```json
{
  "accountId": "uuid-account-devries",
  "tenantId": "tenant-gemeente",
  "eventType": "login-failure",
  "outcome": "denied",
  "details": {
    "reason": "invalid-password",
    "failedAttemptCount": 2
  },
  "occurredAt": "2026-05-21T09:05:00Z",
  "ipHash": "sha256-hash-ip-3",
  "userAgentHash": "sha256-hash-ua-firefox"
}
```

## Files Changed

| File | Action | Description |
|------|--------|-------------|
| `lib/Service/PortalAuthService.php` | CREATE | Login, logout, password reset, TOTP validation |
| `lib/Service/PortalSessionManager.php` | CREATE | Session creation, validation, revocation, TTL management |
| `lib/Service/PortalDelegationService.php` | CREATE | B2B delegation grant/revoke, scope filtering |
| `lib/Service/PortalProfileService.php` | CREATE | Profile read/update with audit, contact sync |
| `lib/Service/PortalInvoiceService.php` | CREATE | Read invoices from shillinq facade |
| `lib/Service/PortalContractService.php` | CREATE | Read contracts from client-management facade |
| `lib/Service/PortalOrderService.php` | CREATE | Read orders from product-catalog-quoting facade |
| `lib/Service/PortalRequestService.php` | CREATE | Submit requests to request-management |
| `lib/Service/DocumentSigningService.php` | CREATE | Signed URL generation + validation |
| `lib/Service/PortalAuditService.php` | CREATE | Audit event logging |
| `lib/Service/PortalTenantService.php` | CREATE | Tenant config CRUD, contrast validation |
| `lib/Service/PortalExportService.php` | CREATE | AVG data export generation |
| `lib/Service/PortalCleanupService.php` | CREATE | Account closure, contact pseudonymization |
| `lib/Controller/PortalAuthController.php` | CREATE | /portal/api/auth routes |
| `lib/Controller/PortalAccountController.php` | CREATE | /portal/api/accounts routes |
| `lib/Controller/PortalInvoiceController.php` | CREATE | /portal/api/invoices routes |
| `lib/Controller/PortalContractController.php` | CREATE | /portal/api/contracts routes |
| `lib/Controller/PortalOrderController.php` | CREATE | /portal/api/orders routes |
| `lib/Controller/PortalRequestController.php` | CREATE | /portal/api/requests routes |
| `lib/Controller/PortalDocumentController.php` | CREATE | /portal/api/documents routes |
| `lib/Controller/PortalDelegationController.php` | CREATE | /portal/api/delegations routes |
| `lib/Controller/PortalTenantController.php` | CREATE | /portal/api/tenant-config routes |
| `lib/Controller/PortalAuditController.php` | CREATE | /portal/api/audit-events routes |
| `lib/Controller/PortalExportController.php` | CREATE | /portal/api/exports routes |
| `lib/Middleware/PortalAuthMiddleware.php` | CREATE | Token validation on /portal/ routes |
| `lib/Middleware/TenantResolverMiddleware.php` | CREATE | Tenant ID resolution from hostname/header |
| `src/views/portal/PortalLogin.vue` | CREATE | Login form + password reset + MFA |
| `src/views/portal/PortalDashboard.vue` | CREATE | Invoices, contracts, orders list |
| `src/views/portal/PortalRequests.vue` | CREATE | Request list + submit form + detail view |
| `src/views/portal/PortalProfile.vue` | CREATE | Account edit (name, phone, address, language) |
| `src/views/portal/PortalDelegations.vue` | CREATE | B2B delegation UI |
| `src/views/portal/PortalExport.vue` | CREATE | AVG data export request form |
| `src/views/portal/PortalClose.vue` | CREATE | Account closure confirmation |
| `src/views/admin/PortalAdminConfig.vue` | CREATE | Tenant branding, config, contrast test |
| `src/views/portal/PortalWidget.vue` | CREATE | Embedded widget variant (compact chrome) |
| `src/router/portalRoutes.js` | CREATE | Portal frontend routes |
| `appinfo/routes.php` | MODIFY | Register /portal/api/ and /portal/ routes |
| `l10n/en.json` | MODIFY | Add portal UI strings (en) |
| `l10n/nl.json` | MODIFY | Add portal UI strings (nl) |
| `l10n/de.json` | MODIFY | Add portal UI strings (de) |
| `l10n/fr.json` | MODIFY | Add portal UI strings (fr) |

## Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| Portal token leaked → customer data exposed | High | Tokens are SHA-256 hashes stored in register; plaintext shown once at login. No persistent token recovery possible. Rate limiting on login attempts. Session TTL 8 hours. |
| Cross-tenant data leak (misconfigured queries) | High | All read queries include `AND tenantId = ?`. Database constraints prevent queries without tenant filter. Tests verify no cross-tenant leakage. Code review checklist item. |
| Email-change takeover (attacker sets their email) | High | Email changes require verification link sent to new address. Old email remains login credential until verified. Verification token is single-use, short-lived (30 min). |
| Branding colors render unreadable (low contrast) | High | Contrast validator runs at save time; rejects if < 4.5:1 with clear error. Admin cannot ship inaccessible portal by mistake. |
| Document URL guessed (brute force) | Medium | Signed HMAC-SHA256 tokens use 256-bit entropy. Brute force unfeasible. Tokens are 5-min TTL; 5-minute window limits attack window. |
| MFA secret exposed in database | Medium | `mfaSecret` is encrypted via `IEncrypter` (Nextcloud core AES-256). Decryption key is from Nextcloud config. If database is stolen, MFA secret is useless without decryption key. |
| Delegation scope escalation (user grants self extra scope) | Medium | `PortalDelegationService` only allows granter to create delegations; grantee cannot modify. API enforces `granterAccountId` from session, not from request. |
| Portal-side DoS via request submission | Medium | File size limit 25 MB per file, enforced at upload. Request creation is rate-limited: max 5 requests per account per hour (configurable). |
| Nextcloud admin can access portal accounts | Low | Nextcloud admin (`IGroupManager::isAdmin()`) can access portal config and audit logs but NOT session tokens or passwordHashes (these are separate, register-stored, not in IAppConfig). Portal is separate auth domain. |
| Account closure data retention edge case | Low | Closed accounts are marked `status: closed` but `linkedContactId` retained. Nightly cleanup queries `client-management` for retention obligations (no open invoices, no active contracts, no claims, retention period expired). Only then is pseudonymization applied. Manual review possible. |


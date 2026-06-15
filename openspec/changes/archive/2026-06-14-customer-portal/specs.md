---
status: draft
---

# Spec: Customer Portal

## Purpose

Define the requirements for a separate-auth-domain customer portal that enables B2B and B2C end-users to self-serve on status queries, document downloads, and request submissions without engaging MKB employees for routine questions. This spec covers authentication, session management, multi-tenant isolation, data access, delegation, document delivery, audit trails, and AVG compliance.

**Main ADR refs**: [adr-000-data-model.md](../../../../architecture/adr-000-data-model.md), [adr-001-international-first-dutch-mapping.md](../../../../architecture/adr-001-international-first-dutch-mapping.md)
**Feature tier**: P0-must
**Demand evidence**: 450+ feature votes, 180+ compliance votes, 75+ security votes
**Depends on**: `client-management` (contacts and organisations), `request-management` (request creation), `product-catalog-quoting` (orders/quotes read), `shillinq` (invoices), `sla-engine-and-escalation` (SLA event dispatch)

---

## REQ-001: Separate Auth Domain from Nextcloud

Portal accounts MUST be stored, authenticated, and session-managed entirely separately from Nextcloud users. A portal session MUST NOT grant any Nextcloud capability, and a Nextcloud session MUST NOT grant any portal capability. Authentication MUST support password (argon2id), optional MFA (TOTP), and session TTL.

### Scenario: Portal token rejected by Nextcloud routes

- GIVEN an attacker obtains a `portal_session.tokenHash` (even as plaintext during login response — one-time display)
- WHEN they replay it against `/index.php/apps/admin` or any Nextcloud core route (not `/portal/api/...`)
- THEN `PortalAuthMiddleware` MUST NOT be invoked
- AND Nextcloud `AuthMiddleware` MUST reject the request as unauthenticated (HTTP 401)
- AND NO access to Nextcloud Files, Calendar, or admin areas MUST be granted

### Scenario: Nextcloud user email matches portal account

- GIVEN a Nextcloud user `max@example.com` exists and a portal account with email `max@example.com` is created in the same tenant
- WHEN the portal account is created
- THEN the system MUST log a collision event in `portal_audit_event` with `eventType: account-creation-collision`, `details: {nextcloudUserId: max, ...}`
- AND `portal_account.linkedNextcloudUserId` MUST remain null (no auto-link)
- AND the two accounts MUST remain independent: portal session does NOT grant Nextcloud access
- AND a Nextcloud session does NOT grant portal access (no SSO short-circuit unless explicitly configured per tenant in future)

### Scenario: Password security via argon2id

- GIVEN a portal user sets password `geheimwachtwoord123`
- WHEN `PortalAuthService::hashPassword()` is called
- THEN the password MUST be hashed via `IHasher::hash()` (Nextcloud uses argon2id by default per NIST SP 800-63B)
- AND the hash MUST be stored in `portal_account.passwordHash`
- AND the plaintext password MUST NOT be logged, cached, or returned in any API response

### Scenario: MFA enrollment and enforcement

- GIVEN a tenant has `mfaEnforced: true` in `portal_tenant_config`
- WHEN a portal user logs in without MFA enabled on their account
- THEN login MUST redirect to MFA enrollment form even if password is correct
- AND enrollment MUST display QR code for TOTP (Google Authenticator, Authy, etc.)
- AND portal account MUST require valid TOTP code to complete login
- AND `portal_account.mfaSecret` MUST be encrypted via `IEncrypter` before storage

### Scenario: Session timeout with warning

- GIVEN a portal user is logged in and their session is about to expire
- WHEN 60 seconds remain until `portal_session.expiresAt`
- THEN the frontend MUST display a warning modal (or live region for screen readers): "Your session expires in 60 seconds" (or locale equivalent)
- AND the modal MUST provide "Log in again" and "Extend session" buttons
- AND clicking "Extend session" MUST call `POST /portal/api/auth/extend-session` which sets `expiresAt` to now + 8 hours
- AND if no action is taken by the deadline, the session MUST become invalid (treated as expired)
- AND a redirect to login page MUST occur

### Scenario: Rate limiting on failed login

- GIVEN a portal account exists
- WHEN 5 failed login attempts occur within 15 minutes (all from same IP or IP hash)
- THEN `portal_account.failedLoginAttempts` MUST be incremented
- AND on the 6th attempt, login MUST return HTTP 429 Too Many Requests with message "Wacht 5 minuten alstublieft." (or locale equivalent)
- AND after 15 minutes without a failed attempt, the counter MUST reset
- AND on successful login, `failedLoginAttempts` MUST reset to 0

---

## REQ-002: Multi-Tenant Isolation

Every portal request MUST be scoped to a single `tenantId` resolved from the request hostname (custom domain), subdomain pattern, or explicit header in widget mode, and queries MUST NEVER cross tenant boundaries.

### Scenario: Custom domain routing

- GIVEN a `portal_tenant_config` for "tenant-bakkerij" has `customDomain: klant.bakkerij-bakker.nl`
- WHEN a request arrives at `https://klant.bakkerij-bakker.nl/portal/api/invoices`
- THEN `TenantResolverMiddleware` MUST look up `portal_tenant_config` by hostname
- AND identify `tenantId: tenant-bakkerij`
- AND set `$request->tenantId = "tenant-bakkerij"` for the request lifecycle
- AND all invoice queries MUST include `WHERE tenantId = "tenant-bakkerij"`

### Scenario: Cross-tenant data leak prevention (404 not 403)

- GIVEN portal accounts A (tenant-alpha) and B (tenant-bravo)
- WHEN account A's session is used to request `GET /portal/api/invoices`
- THEN the response MUST include only invoices where the linked contact's `tenantId = "tenant-alpha"`
- AND even if account A guesses invoice ID from tenant-bravo and requests `GET /portal/api/invoices/{id}`, the response MUST be HTTP 404 (not 403)
- AND the response body MUST NOT reveal whether the invoice exists (prevents existence leak via status code)

### Scenario: Widget mode with origin validation

- GIVEN a tenant enables widget mode (`widgetEmbedAllowed: true`) with `widgetAllowedOrigins: ["https://example.com", "https://shop.example.com"]`
- WHEN a widget iframe loads from `https://example.com` and makes a request with `X-Portal-Tenant: alpha` header
- THEN `WidgetOriginValidator` MUST check the request Origin header against `widgetAllowedOrigins`
- AND if the origin is in the list, the request MUST proceed
- AND if the origin is not in the list, the response MUST be HTTP 403 with message "Deze website is niet gemachtigd." (or locale equivalent)

### Scenario: Tenant config isolation

- GIVEN `portal_tenant_config` with `enabledFeatures: ["invoices", "contracts"]` (quotes disabled)
- WHEN a portal user requests `GET /portal/api/quotes`
- THEN the API MUST return HTTP 404 with `errorCode: featureNotEnabled`
- AND the frontend MUST not render a "Quotes" navigation link

---

## REQ-003: View Own Contracts, Orders, Invoices, Quotes

Authenticated portal users MUST be able to list and view their own records from the linked contact's organisation (B2B) or contact (B2C), respecting delegation scopes.

### Scenario: B2C user views own invoices only

- GIVEN a B2C portal account linked to `contact: C1` (no organisation)
- WHEN the user requests `GET /portal/api/invoices?page=1&perPage=10`
- THEN `PortalInvoiceService` MUST query shillinq register for invoices where `linkedContactId = C1` AND `tenantId = <current>`
- AND response MUST be paginated JSON array: `{total: N, page: 1, perPage: 10, invoices: [{invoiceNumber, date, amount, status, ...}, ...]}`
- AND invoices MUST be ordered by date descending (most recent first)
- AND `invoiceNumber`, `amount`, `status` MUST be included in response

### Scenario: B2B user views own org invoices

- GIVEN a B2B portal account linked to `organisation: O1`
- WHEN the user requests `GET /portal/api/invoices`
- THEN the response MUST include all invoices where `linkedOrganisationId = O1` AND `tenantId = <current>`
- AND only the organisation owner or delegated users with `view-invoices` scope MUST see them

### Scenario: B2B delegation — delegated user sees delegator's data

- GIVEN account A (owner of O1) grants account B (colleague) `view-invoices` scope via `portal_delegation` with `scopes: ["view-invoices"]`
- WHEN account B requests `GET /portal/api/invoices`
- THEN the response MUST include invoices for O1 (the delegated organisation)
- AND each invoice MUST include `delegatedFrom: "uuid-account-A"` to indicate it's delegated data
- AND account B MUST NOT be able to see invoices from O1's contracts or other data beyond the granted scope

### Scenario: Disabled feature returns 404

- GIVEN a tenant has `enabledFeatures: ["invoices", "contracts"]` (orders not included)
- WHEN a portal user requests `GET /portal/api/orders`
- THEN the response MUST be HTTP 404 with `errorCode: featureNotEnabled`
- AND the frontend navigation MUST not display an "Orders" link

### Scenario: Pagination and filtering

- GIVEN a contact has 250 invoices
- WHEN the user requests `GET /portal/api/invoices?page=3&perPage=25`
- THEN the response MUST return invoices 51-75, sorted by date descending
- AND `total: 250` MUST be included in response metadata

---

## REQ-004: Request Status Updates

Portal users MUST be able to view the status, ETA, and recent activity of requests they submitted (or that are linked to their organisation), without seeing internal notes marked `visibility: internal`.

### Scenario: Internal notes filtered server-side

- GIVEN a request has 3 notes: note1 (visibility: customer), note2 (visibility: internal), note3 (visibility: customer)
- WHEN a portal user fetches `GET /portal/api/requests/{requestId}`
- THEN the response MUST include only note1 and note3
- AND note2 MUST NOT be present in the response (server-side filtering, not client-side)
- AND the notes MUST be sorted by `createdAt` ascending (oldest first)

### Scenario: Awaiting customer status with reply field

- GIVEN a request has status `awaiting-customer` (SLA pause condition)
- WHEN the portal user views the request detail
- THEN the status display MUST show "Wachten op uw reactie" (or locale equivalent)
- AND a reply text input field MUST be visible
- AND submitting a reply MUST create a new note with `visibility: customer`, triggering SLA unpause

### Scenario: Assignee name privacy

- GIVEN a tenant has `exposeAssigneeName: false` (default)
- AND a request is assigned to user `m.bakker`
- WHEN a portal user fetches the request
- THEN the response MUST NOT include the assignee name or user ID
- AND an optional field `assigneeHidden: true` MAY be included to inform the frontend

### Scenario: Request ETA calculation

- GIVEN a request was submitted 2 hours ago with SLA deadline of 24 hours
- WHEN the user views the request
- THEN the response MUST include `estimatedResponseTime: "in about 22 hours"` (or absolute datetime)

---

## REQ-005: Download Documents

Portal users MUST be able to download documents (invoices, contracts, quotes, attachments to requests) via signed time-limited URLs, never via direct Nextcloud Files paths.

### Scenario: Signed URL generation

- GIVEN a portal user requests a document download for invoice ID `uuid-invoice-001`
- WHEN `DocumentSigningService::generateUrl("uuid-invoice-001", "invoice", 5)` is called
- THEN a signed URL MUST be generated: `/portal/api/documents/<signedToken>/download`
- AND the token MUST be valid for exactly 5 minutes
- AND the token MUST contain HMAC-SHA256 signature over `{objectId, objectType, issuedAt, expiresAt}`
- AND the plaintext object path (`Pipelinq/Invoices/...`) MUST NOT be in the URL

### Scenario: Multiple uses within TTL window

- GIVEN a signed URL is generated
- WHEN the URL is used twice within the 5-minute window from different clients
- THEN both requests MUST succeed (URLs are not single-use)
- AND each download MUST be logged as a separate `portal_audit_event` with `eventType: document-download`, `targetObjectId: uuid-invoice-001`

### Scenario: Expired URL returns 410 Gone

- GIVEN a signed URL was generated 6 minutes ago
- WHEN a request is made to the download endpoint
- THEN the response MUST be HTTP 410 Gone (not 404, to indicate it was valid but is now expired)
- AND the response body MUST suggest: "This link has expired. Please download the document again from the portal."

### Scenario: Document access audit trail

- GIVEN a portal user downloads an invoice
- WHEN the download succeeds
- THEN a `portal_audit_event` MUST be created with:
  - `accountId: <user>`
  - `eventType: document-download`
  - `targetObjectType: invoice`
  - `targetObjectId: <invoiceId>`
  - `outcome: success`
  - `occurredAt: <now>`

---

## REQ-006: Submit Support Request

Portal users MUST be able to create a new support request from the portal, with subject, body, attachments, and a category picker drawn from the tenant's `request-management` configuration.

### Scenario: Request creation with categories

- GIVEN a logged-in portal user with `email: jan@pietersen.nl` linked to `contactId: uuid-contact-jan`
- WHEN they submit a request with:
  - `subject: "Monteur heeft onderdeel niet meegenomen"`
  - `body: "De monteur was er vorige vrijdag maar heeft het defecte onderdeel niet meegenomen. Wanneer kan hij terug komen?"`
  - `attachmentIds: [uuid-file-foto-onderdeel]`
  - `categoryId: uuid-cat-technisch-onderhoud` (marked `exposeToCustomer: true`)
- THEN `PortalRequestService::submit()` MUST:
  1. Validate that the category is marked `exposeToCustomer: true` (reject with HTTP 422 if internal category)
  2. Create a `request` record in request-management register with:
     - `title: subject`
     - `description: body`
     - `reporterContactId: uuid-contact-jan`
     - `submittedVia: portal`
     - `categoryId: uuid-cat-technisch-onderhoud`
     - `tenantId: <current>`
  3. Move attachment files to portal-scoped folder: `/Pipelinq/Portal/Requests/{requestId}/`
  4. Emit `RequestSubmittedEvent` to SLA engine
  5. Return HTTP 201 with `{requestId, estimatedResponseTime: "24 uur"}`

### Scenario: Internal category rejection

- GIVEN a category `uuid-cat-intern-bug` exists with `exposeToCustomer: false`
- WHEN a portal user submits a request with this category
- THEN the API MUST return HTTP 422 with error: `{errorCode: "categoryNotAvailable", message: "Deze categorie is niet beschikbaar."}`

### Scenario: File size limit

- GIVEN a user uploads an attachment with size 30 MB
- WHEN `PortalRequestService` validates the file
- THEN the API MUST reject with HTTP 413 Payload Too Large: `{errorCode: "fileToLarge", message: "Bestand mag maximaal 25 MB zijn. Dit bestand is 30 MB."}`

### Scenario: Rate limit on request submission

- GIVEN a portal account
- WHEN the user submits 5 requests within 60 minutes
- THEN the 6th request submission in the same 60-minute window MUST return HTTP 429: `{errorCode: "rateLimited", message: "Wacht alstublieft 60 minuten voordat u een nieuw verzoek indient."}`

---

## REQ-007: Profile Self-Update with Audit

Portal users MUST be able to update their own name, phone, billing address, language, and (B2B) job title; every change MUST land in `portal_audit_event` with `previousValue` and `newValue`.

### Scenario: Phone number update with audit

- GIVEN a portal user updates their phone from `+31 6 11111111` to `+31 6 22222222`
- WHEN `PUT /portal/api/accounts/profile` is called with `{phone: "+31 6 22222222"}`
- THEN the API MUST:
  1. Update `portal_account.phone` to the new value
  2. Update the linked contact in `client-management` (if linked)
  3. Create a `portal_audit_event` with:
     - `accountId: <user>`
     - `eventType: profile-update`
     - `outcome: success`
     - `details: {fieldName: "phone", previousValue: "+31 6 11111111", newValue: "+31 6 22222222"}`

### Scenario: Email change requires verification

- GIVEN a portal user changes email from `old@example.com` to `new@example.com`
- WHEN the update request is submitted
- THEN the API MUST:
  1. NOT immediately change the login email
  2. Generate a verification token via `PasswordResetTokenService::generateToken()`
  3. Send email to `new@example.com` with verification link: `/portal/verify-email?token=...`
  4. Keep `portal_account.email = old@example.com` as the login credential
  5. Store pending email in `portal_account.pendingEmail: new@example.com`
  6. Log a `portal_audit_event` with `eventType: profile-update`, `outcome: pending-verification`
- WHEN the user clicks the verification link within 30 minutes
- THEN update `portal_account.email = new@example.com` and clear `pendingEmail`
- AND log another event with `outcome: success`

### Scenario: B2B job title update

- GIVEN a B2B portal account
- WHEN the user updates `jobTitle` from "Inkoop Manager" to "Senior Inkoop Manager"
- THEN the linked `contact.role` MUST be updated in `client-management`
- AND audit event MUST be logged

### Scenario: Language preference update

- GIVEN a user changes `locale` from `nl` to `en`
- WHEN the request is saved
- THEN the frontend MUST reload and display in English for the next request
- AND `portal_account.locale: en` MUST be persisted

---

## REQ-008: Embeddable Widget Mode

The portal MUST be embeddable as an iframe widget on third-party sites with origin-controlled CORS, postMessage-based parent communication for height auto-resize, and a stripped-down chrome (no global nav).

### Scenario: Widget loads from allowed origin

- GIVEN a tenant enables widget mode (`widgetEmbedAllowed: true`) with `widgetAllowedOrigins: ["https://example.com"]`
- WHEN the widget URL is loaded from `https://example.com`: `<iframe src="https://portal.pipelinq.nl/portal/widget?tenant=alpha"></iframe>`
- THEN the iframe MUST load the portal page
- AND the API MUST accept requests from origin `https://example.com` (CORS check passes)
- AND the widget MUST display without global navigation bar (compact chrome)

### Scenario: Widget rejected from unauthorized origin

- GIVEN the same widget URL
- WHEN it is embedded from `https://attacker.com`
- THEN `WidgetOriginValidator` MUST reject the origin
- AND the iframe MUST display message: "Deze website is niet gemachtigd. Contacteer de klantportaal beheerder." (or locale equivalent)
- AND NO data MUST be loaded or exposed

### Scenario: Widget height auto-resize

- GIVEN a widget iframe is embedded
- WHEN the portal content height changes (e.g. user clicks "Show more")
- THEN the frontend MUST measure the new content height and emit: `window.parent.postMessage({type: 'portal:resize', height: 1200}, '*')`
- AND the parent page (MKB website) MAY listen for this event and adjust the iframe height accordingly

### Scenario: Widget logout signal

- GIVEN a user is logged into the widget
- WHEN they click "Log uit" (Logout)
- THEN the widget MUST:
  1. Clear the local session
  2. Emit: `window.parent.postMessage({type: 'portal:logout'}, '*')`
  3. Return to the login page
- AND the parent page MAY listen for this event to update its own UI (e.g. hide portal-specific UI)

---

## REQ-009: WCAG 2.2 AA Compliance

Every portal page and component MUST meet WCAG 2.2 AA: keyboard navigability, screen-reader labels, 4.5:1 color contrast on text, visible focus indicators, no time-out without warning, error messages programmatically associated with form fields.

### Scenario: Invoice list screen-reader usage

- GIVEN a screen-reader user (NVDA, JAWS, VoiceOver) navigates to `/portal/dashboard?tab=invoices`
- WHEN they tab through invoice list
- THEN each row MUST announce in a logical order:
  - "Invoice number INV-2026-001"
  - "Date 2026-05-15"
  - "Amount €250.00"
  - "Status Betaald"
  - "Button Factuur openen"
- AND the table MUST use semantic HTML: `<table role="grid">`, `<th scope="col">` headers, `<td role="gridcell">` cells
- AND the "open invoice" button MUST have `aria-label="Factuur INV-2026-001 openen"`

### Scenario: Form error messages associated with fields

- GIVEN a portal user submits a request without a subject
- WHEN the frontend validates the form
- THEN an error message MUST appear AND be programmatically associated with the field via `aria-describedby`:
  - Field: `<input id="subject" aria-describedby="subject-error">`
  - Error: `<span id="subject-error" role="alert">Onderwerp is verplicht</span>`
- AND screen readers MUST announce both the field and the error message when the user tabs to it

### Scenario: Color contrast on brand colors

- GIVEN a tenant sets `brandPrimaryColor: #FF6600` (orange) on light background
- WHEN the admin saves the config
- THEN the system MUST calculate contrast ratio using WCAG 2023 formula: `(L1 + 0.05) / (L2 + 0.05)` where L = relative luminance
- AND if ratio < 4.5:1, the API MUST return HTTP 422 with:
  ```json
  {
    "errorCode": "contrastRatioBelowMinimum",
    "contrastRatio": 3.2,
    "requiredMinimum": 4.5,
    "message": "Kleurcontrast is onvoldoende (3.2:1). Minimaal vereist: 4.5:1. Kies een donkerder of lichter kleur."
  }
  ```

### Scenario: Session timeout warning (live region)

- GIVEN a portal user session is about to expire
- WHEN 60 seconds remain before expiry
- THEN a warning MUST appear and be announced by screen readers:
  - Visual: banner with countdown "Session expires in 59 seconds"
  - HTML: `<div role="alert" aria-live="polite">Uw sessie verloopt over 59 seconden. Klik "Sessie verlengen" om aangemeld te blijven.</div>`
  - The `aria-live="polite"` region MUST be updated every second
  - Buttons: "Log uit" (logout) and "Sessie verlengen" (extend)

### Scenario: Focus visible on all interactive elements

- GIVEN a portal user navigates via keyboard (Tab key)
- WHEN focus lands on any button, link, input field, or dropdown
- THEN a visible focus indicator MUST be displayed (not invisible outline):
  - CSS: `:focus { outline: 2px solid #000; }` (or equivalent with 2px+ width, high contrast)
  - Focus order MUST match logical page flow (left-to-right, top-to-bottom)

---

## REQ-010: Data Export and Account Closure (AVG)

Portal users MUST be able to request a machine-readable export of all data the portal holds about them, and MUST be able to close their account; both actions MUST honour the tenant's legal retention rules.

### Scenario: Data export request and delivery

- GIVEN a portal user requests a data export via `POST /portal/api/exports`
- WHEN the request is processed
- THEN the API MUST queue a background job that:
  1. Collects all data: contact record, `portal_audit_event` records, accessible documents (invoices, contracts, quotes, request attachments)
  2. Generates a JSON file:
     ```json
     {
       "exportedAt": "2026-05-21T14:30:00Z",
       "contact": {
         "name": "Jan Pietersen",
         "email": "jan@pietersen.nl",
         "phone": "+31 6 11111111",
         "address": "Straatweg 123, 3700 AB Zeist"
       },
       "linkedOrganisation": {
         "name": "Pietersen BV",
         "kvk": "12345678"
       },
       "auditEvents": [
         {"eventType": "login-success", "occurredAt": "2026-05-21T08:30:00Z", ...},
         ...
       ],
       "documents": [
         {"name": "INV-2026-001.pdf", "type": "invoice", "size": 125000, ...},
         ...
       ]
     }
     ```
  3. Generates a PDF summary of the export
  4. Packages JSON + PDF + document files into a ZIP
  5. Generates a signed download link valid for 30 days
  6. Returns `{downloadUrl: "https://...", expiresAt: "2026-06-20T14:30:00Z"}`
- AND the user MUST receive the download link via email

### Scenario: Export completion within AVG 30-day requirement

- GIVEN the user requests the export on 2026-05-21
- WHEN the background job completes
- THEN the completion MUST occur by 2026-06-20 (30 days per AVG Art. 15)
- AND if the job is still running after 25 days, an automated escalation MUST notify the tenant admin

### Scenario: Account closure with legal retention

- GIVEN a portal user requests account closure via `PUT /portal/api/accounts/close`
- WHEN the request is confirmed via email link
- THEN the API MUST:
  1. Set `portal_account.status = closed`
  2. Revoke all `portal_session` records (set `revoked: true`, `revokedAt: now`, `revokedReason: account-closure`)
  3. Retain `portal_account.linkedContactId` (needed for contract/invoice retention obligations)
  4. Send confirmation email: "Uw account is gesloten. U kunt niet meer inloggen."
  5. Create `portal_audit_event` with `eventType: account-close`, `outcome: success`

### Scenario: Contact pseudonymization after retention period

- GIVEN a `portal_account` is closed and the contact's retention period has expired (no open invoices, no active contracts, no claims, 7-year tax retention expired)
- WHEN the nightly `PortalCleanupCommand` runs
- THEN the contact record in `client-management` MUST be pseudonymised:
  - `name` → SHA-256 hash (e.g. `#abc123...`)
  - `email` → SHA-256 hash
  - `phone` → removed or anonymised
  - `linkedContactId` on `portal_account` → removed
- AND the deletion MUST be logged in `portal_audit_event` with `eventType: account-pseudonymised`

### Scenario: Pre-closed account cannot log in

- GIVEN a `portal_account` with `status: closed`
- WHEN a login attempt is made with that email
- THEN the API MUST return HTTP 401: `{errorCode: "accountClosed", message: "Dit account is gesloten."}`

---

## Cross-Spec Requirements

### Locale Support

All user-facing strings MUST be translatable via i18n keys in `l10n/{en,nl,de,fr}.json`. Plural forms MUST use `ngettext()` where applicable (e.g. "1 invoice", "2 invoices").

### Audit Trail Integrity

All `portal_audit_event` records MUST be immutable once written. No updates, no deletes (only new inserts). This ensures compliance audits can trust the audit trail.

### Session Token Format & Rotation

Session tokens generated by `PortalSessionManager` MUST be:
- 256-bit random bytes via `ISecureRandom`
- Encoded as base64url (RFC 4648 section 5)
- Hashed with SHA-256 before storage
- Rotated on every request (optional per spec detail, but recommended per NIST SP 800-63B)

### Cross-Tenant Boundary Enforcement

No API endpoint MUST accept a `tenantId` parameter from the client. All tenant resolution MUST come from middleware (`TenantResolverMiddleware`), never from the request body or query string.


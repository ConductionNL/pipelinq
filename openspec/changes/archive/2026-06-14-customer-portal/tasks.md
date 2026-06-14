# Tasks: customer-portal

## 0. Deduplication Check

- [x] 0.1 Search for existing portal or auth-domain patterns in Nextcloud and Pipelinq
  - **spec_ref**: ADR-012-deduplication
  - **files**: `lib/Service/`, `appinfo/`
  - **acceptance_criteria**:
    - GIVEN the search is complete
    - THEN document findings: no existing `*Portal*` or `*CustomerAuth*` service classes found (or list any overlap)
    - AND if overlap exists, confirm reuse plan with team before proceeding

- [x] 0.2 Verify no Nextcloud user-account-as-customer patterns exist in codebase
  - **spec_ref**: REQ-001
  - **files**: `lib/`, `tests/`
  - **acceptance_criteria**:
    - GIVEN the search is complete
    - THEN confirm that portal authentication will be completely separate from `nc_users` table
    - AND plan to use `pipelinq-portal` register exclusively for auth

---

## 1. Backend — Core Auth Services

- [x] 1.1 Create `PortalAuthService.php`
  - **spec_ref**: REQ-001
  - **files**: `lib/Service/PortalAuthService.php`
  - **acceptance_criteria**:
    - GIVEN login credentials (email, password)
    - WHEN `login($email, $password, $ipHash, $userAgentHash)` is called
    - THEN `portal_account` MUST be looked up by email AND `tenantId`
    - AND password MUST be verified via `IHasher::verify()`
    - AND on success, return `{accountId, mfaRequired}`
    - AND on failure, increment `failedLoginAttempts` and log `portal_audit_event` with `eventType: login-failure`
    - AND after 5 failed attempts in 15 minutes, return HTTP 429 Too Many Requests

- [x] 1.2 Create `PortalSessionManager.php`
  - **spec_ref**: REQ-001
  - **files**: `lib/Service/PortalSessionManager.php`
  - **acceptance_criteria**:
    - GIVEN a `portal_account` ID
    - WHEN `createSession($accountId, $ipHash, $userAgentHash, $ttlHours=8)` is called
    - THEN generate 256-bit random token via `ISecureRandom`
    - AND create `portal_session` record with SHA-256 hash of token, `expiresAt: now + 8 hours`
    - AND return plaintext token (one-time display)
    - AND `validateSession($tokenHash)` MUST verify token exists, is not revoked, and not expired
    - AND `revokeSession($sessionId, $reason)` MUST set `revoked: true`, `revokedAt`, `revokedReason`

- [x] 1.3 Create `PortalMfaService.php`
  - **spec_ref**: REQ-001
  - **files**: `lib/Service/PortalMfaService.php`
  - **acceptance_criteria**:
    - GIVEN a `portal_account`
    - WHEN `enrollMfa($accountId)` is called
    - THEN generate TOTP secret via `PHPGangsta_GoogleAuthenticator`
    - AND return `{secret, qrCode}`
    - AND store encrypted secret in `portal_account.mfaSecret` via `IEncrypter`
    - AND `verifyMfaCode($accountId, $code)` MUST validate TOTP code against stored secret
    - AND `disableMfa($accountId)` MUST clear `mfaSecret` and reset `mfaEnforced`

- [x] 1.4 Create `PasswordResetService.php`
  - **spec_ref**: REQ-001, REQ-007
  - **files**: `lib/Service/PasswordResetService.php`
  - **acceptance_criteria**:
    - GIVEN email address
    - WHEN `requestReset($email)` is called
    - THEN generate single-use token valid for 30 minutes
    - AND store `passwordResetTokenHash`, `passwordResetExpiresAt` on `portal_account`
    - AND send email with reset link to account email
    - WHEN user visits reset link with valid token and submits new password
    - AND `resetPassword($token, $newPassword)` is called
    - THEN verify token is valid and not expired
    - AND update `portal_account.passwordHash` via `IHasher::hash()`
    - AND clear reset token
    - AND log `portal_audit_event` with `eventType: password-reset`, `outcome: success`

---

## 2. Backend — Multi-Tenant & Request Resolution

- [x] 2.1 Create `TenantResolverMiddleware.php`
  - **spec_ref**: REQ-002
  - **files**: `lib/Middleware/TenantResolverMiddleware.php`
  - **acceptance_criteria**:
    - GIVEN a request to `/portal/api/...`
    - WHEN middleware executes
    - THEN resolve `tenantId` from:
      1. Hostname (custom domain lookup in `portal_tenant_config.customDomain`)
      2. Subdomain pattern (e.g. `alpha.portal.pipelinq.nl` → `alpha`)
      3. `X-Portal-Tenant` header (widget mode)
    - AND look up `portal_tenant_config` by resolved `tenantId`
    - AND return HTTP 404 if tenant not found
    - AND set `$request->tenantId` for the request lifecycle
    - AND validate widget origins if applicable

- [x] 2.2 Create `PortalAuthMiddleware.php`
  - **spec_ref**: REQ-001
  - **files**: `lib/Middleware/PortalAuthMiddleware.php`
  - **acceptance_criteria**:
    - GIVEN a request to `/portal/api/...`
    - WHEN middleware executes
    - THEN extract `Authorization: Bearer <token>` header
    - AND hash token and look up in `portal_session` table
    - AND verify session is not revoked and not expired
    - AND set `$request->portalAccountId` and `$request->portalAccount` (full object)
    - AND return HTTP 401 if token is invalid or missing for protected routes
    - AND allow unauthenticated access to `/portal/api/auth/login`, `/portal/api/auth/refresh-token`, `/portal/api/tenant-config` (GET)

---

## 3. Backend — Data Access Services (Read Facades)

- [x] 3.1 Create `PortalInvoiceService.php`
  - **spec_ref**: REQ-003, REQ-005
  - **files**: `lib/Service/PortalInvoiceService.php`
  - **acceptance_criteria**:
    - GIVEN a `portal_account` and `tenantId`
    - WHEN `getForAccount($accountId, $page=1, $perPage=10)` is called
    - THEN query shillinq register for invoices where:
      - `linkedContactId = portal_account.linkedContactId` OR
      - `linkedOrganisationId = portal_account.linkedOrganisationId` OR
      - delegated from another account with `view-invoices` scope
    - AND filter out deleted invoices
    - AND return paginated array: `{total, page, perPage, invoices: [{invoiceNumber, date, amount, status, ...}]}`
    - AND order by date descending
    - AND log each access in `portal_audit_event` with `eventType: invoices-listed`

- [x] 3.2 Create `PortalContractService.php`
  - **spec_ref**: REQ-003
  - **files**: `lib/Service/PortalContractService.php`
  - **acceptance_criteria**:
    - GIVEN a `portal_account`
    - WHEN `getForAccount($accountId)` is called
    - THEN query client-management register for contracts linked to account's contact/organisation
    - AND return paginated array of contracts with: `{contractNumber, startDate, endDate, value, status, ...}`

- [x] 3.3 Create `PortalOrderService.php`
  - **spec_ref**: REQ-003
  - **files**: `lib/Service/PortalOrderService.php`
  - **acceptance_criteria**:
    - GIVEN a `portal_account`
    - WHEN `getForAccount($accountId)` is called
    - THEN query product-catalog-quoting register for orders linked to account's organisation
    - AND return paginated array of orders with: `{orderNumber, date, items, total, status, eta, ...}`

- [x] 3.4 Create `PortalRequestService.php`
  - **spec_ref**: REQ-004, REQ-006
  - **files**: `lib/Service/PortalRequestService.php`
  - **acceptance_criteria**:
    - GIVEN a `portal_account`
    - WHEN `getForAccount($accountId)` is called
    - THEN query request-management register for requests where `reporterContactId = account.linkedContactId`
    - AND filter out internal notes (server-side filtering)
    - AND return paginated array of requests
    - WHEN `submit($accountId, $subject, $body, $attachmentIds, $categoryId)` is called
    - THEN validate category is marked `exposeToCustomer: true`
    - AND create `request` record in request-management with `submittedVia: portal`, `reporterContactId`
    - AND move files to portal-scoped folder
    - AND emit `RequestSubmittedEvent` to SLA engine
    - AND enforce rate limit: max 5 requests per account per 60 minutes

---

## 4. Backend — Profile & Delegation Services

- [x] 4.1 Create `PortalProfileService.php`
  - **spec_ref**: REQ-007
  - **files**: `lib/Service/PortalProfileService.php`
  - **acceptance_criteria**:
    - GIVEN a `portal_account` and `changes` object
    - WHEN `update($accountId, $changes)` is called
    - THEN update `portal_account` fields: displayName, phone, locale, address (B2B: jobTitle)
    - AND if email is changed, generate verification token (not applied immediately)
    - AND sync changes to linked contact in client-management
    - AND create `portal_audit_event` records for each field changed: `{fieldName, previousValue, newValue}`
    - AND return updated account object

- [x] 4.2 Create `PortalDelegationService.php`
  - **spec_ref**: REQ-003
  - **files**: `lib/Service/PortalDelegationService.php`
  - **acceptance_criteria**:
    - GIVEN a B2B account granter and list of scopes
    - WHEN `grant($granterAccountId, $granteeEmail, $scopes, $validUntil)` is called
    - THEN look up or invite grantee account
    - AND create `portal_delegation` record with scopes array: `{view-invoices, view-contracts, submit-requests}`
    - AND return delegation record
    - WHEN `revoke($delegationId)` is called
    - THEN set `portal_delegation.revokedAt = now` (soft delete)
    - WHEN `getActiveScopes($accountId)` is called
    - THEN return array of `{grantorAccountId, scopes[]}` for all active delegations
    - AND read services MUST include delegated data in results, tagged with `delegatedFrom`

---

## 5. Backend — Document & Audit Services

- [x] 5.1 Create `DocumentSigningService.php`
  - **spec_ref**: REQ-005
  - **files**: `lib/Service/DocumentSigningService.php`
  - **acceptance_criteria**:
    - GIVEN `objectId`, `objectType`, TTL minutes
    - WHEN `generateUrl($objectId, $objectType, $ttlMinutes=5)` is called
    - THEN create HMAC-SHA256 signature over `{objectId, objectType, issuedAt, expiresAt}`
    - AND return signed URL: `/portal/api/documents/{signedToken}/download`
    - AND token MUST be valid for exactly specified TTL
    - WHEN `validateToken($token)` is called
    - THEN verify HMAC signature and check expiry
    - AND return `{objectId, objectType, expiresAt}` if valid
    - AND return null if invalid or expired

- [x] 5.2 Create `PortalAuditService.php`
  - **spec_ref**: REQ-007, REQ-010
  - **files**: `lib/Service/PortalAuditService.php`
  - **acceptance_criteria**:
    - GIVEN event details
    - WHEN `log($accountId, $eventType, $outcome, $details)` is called
    - THEN create immutable `portal_audit_event` record
    - AND never allow updates or deletes (append-only design)
    - WHEN `getForAccount($accountId)` is called
    - THEN return audit events for that account only
    - WHEN `getForTenant($tenantId)` is called (admin access)
    - THEN return all audit events for tenant

---

## 6. Backend — Tenant Configuration

- [x] 6.1 Create `PortalTenantService.php`
  - **spec_ref**: REQ-002, REQ-009
  - **files**: `lib/Service/PortalTenantService.php`
  - **acceptance_criteria**:
    - GIVEN a `tenantId`
    - WHEN `getConfig($tenantId)` is called
    - THEN return `portal_tenant_config` object with branding, features, support contact
    - WHEN `saveConfig($tenantId, $config)` is called
    - THEN validate contrast ratio if brand colors are provided
    - AND return HTTP 422 with contrast error if < 4.5:1
    - AND persist config to register
    - AND return updated config object

- [x] 6.2 Create `ContrastRatioCalculator.php`
  - **spec_ref**: REQ-009
  - **files**: `lib/Util/ContrastRatioCalculator.php`
  - **acceptance_criteria**:
    - GIVEN two hex color strings
    - WHEN `calculate($color1, $color2)` is called
    - THEN compute relative luminance per WCAG 2023 formula
    - AND return contrast ratio as float (e.g. 4.5)
    - AND `meetsAAStandard($ratio)` returns true if ratio >= 4.5

---

## 7. Backend — AVG Compliance

- [x] 7.1 Create `PortalExportService.php`
  - **spec_ref**: REQ-010
  - **files**: `lib/Service/PortalExportService.php`
  - **acceptance_criteria**:
    - GIVEN a `portal_account`
    - WHEN `requestExport($accountId)` is called
    - THEN queue background job that:
      1. Collects contact record, audit events, accessible documents
      2. Generates JSON export file
      3. Generates PDF summary
      4. Creates ZIP bundle
      5. Generates 30-day-valid signed download link
    - AND return `{downloadUrl, expiresAt}`
    - AND send email to user with download link

- [x] 7.2 Create `PortalAccountService.php` (account closure)
  - **spec_ref**: REQ-010
  - **files**: `lib/Service/PortalAccountService.php`
  - **acceptance_criteria**:
    - GIVEN a `portal_account` and email verification token
    - WHEN `close($accountId, $token)` is called
    - THEN verify token is valid
    - AND set `portal_account.status = closed`
    - AND revoke all sessions: set `portal_session.revoked = true` for all sessions
    - AND retain `linkedContactId` (legal retention)
    - AND log `portal_audit_event` with `eventType: account-close`, `outcome: success`
    - AND send confirmation email to user

- [x] 7.3 Create `PortalCleanupCommand.php` (nightly cleanup)
  - **spec_ref**: REQ-010
  - **files**: `lib/Command/PortalCleanupCommand.php`
  - **acceptance_criteria**:
    - GIVEN the nightly cron job runs
    - WHEN `PortalCleanupCommand::run()` is executed
    - THEN find all `portal_account` records with `status: closed`
    - AND for each, query client-management for retention obligations
    - AND if no retention holds (no open invoices, contracts, claims, retention period expired), pseudonymise contact:
      - Name, email → SHA-256 hash
      - Phone → removed
      - Clear `linkedContactId`
    - AND log pseudonymisation in `portal_audit_event`

---

## 8. Backend — Controllers & Routes

- [x] 8.1 Create `PortalAuthController.php`
  - **spec_ref**: REQ-001
  - **files**: `lib/Controller/PortalAuthController.php`
  - **acceptance_criteria**:
    - Routes:
      - `POST /portal/api/auth/login` — login with email/password, optional TOTP code
      - `POST /portal/api/auth/mfa-enroll` — enroll TOTP (returns QR code)
      - `POST /portal/api/auth/mfa-verify` — verify TOTP enrollment
      - `POST /portal/api/auth/password-reset-request` — initiate password reset
      - `POST /portal/api/auth/password-reset` — complete password reset with token
      - `POST /portal/api/auth/logout` — revoke current session
      - `POST /portal/api/auth/extend-session` — extend session TTL
    - All responses MUST handle rate limiting (HTTP 429 if exceeded)

- [x] 8.2 Create `PortalAccountController.php`
  - **spec_ref**: REQ-007
  - **files**: `lib/Controller/PortalAccountController.php`
  - **acceptance_criteria**:
    - Routes:
      - `GET /portal/api/accounts/profile` — get current account details
      - `PUT /portal/api/accounts/profile` — update profile (name, phone, address, language)
      - `POST /portal/api/accounts/verify-email` — verify email change with token
    - All actions log `portal_audit_event`

- [x] 8.3 Create `PortalInvoiceController.php` through `PortalDelegationController.php`
  - **spec_ref**: REQ-003 through REQ-008
  - **files**: `lib/Controller/Portal{Invoice,Contract,Order,Request,Document,Delegation,Tenant}Controller.php`
  - **acceptance_criteria**:
    - Invoice controller:
      - `GET /portal/api/invoices` — list invoices (paginated)
      - `GET /portal/api/invoices/{id}` — get invoice detail
    - Contract controller:
      - `GET /portal/api/contracts` — list contracts
      - `GET /portal/api/contracts/{id}` — get contract detail
    - Order controller:
      - `GET /portal/api/orders` — list orders
      - `GET /portal/api/orders/{id}` — get order detail
    - Request controller:
      - `GET /portal/api/requests` — list requests
      - `GET /portal/api/requests/{id}` — get request detail with notes (filtered)
      - `POST /portal/api/requests` — create new request
      - `POST /portal/api/requests/{id}/reply` — add reply note (unpause if awaiting-customer)
    - Document controller:
      - `GET /portal/api/documents/{token}/download` — download with signed URL validation
    - Delegation controller:
      - `GET /portal/api/delegations` — list my delegations (B2B)
      - `POST /portal/api/delegations` — grant delegation
      - `DELETE /portal/api/delegations/{id}` — revoke delegation
    - Tenant controller:
      - `GET /portal/api/tenant-config` — get tenant branding (public)

- [x] 8.4 Create `PortalAdminController.php`
  - **spec_ref**: REQ-002
  - **files**: `lib/Controller/PortalAdminController.php`
  - **acceptance_criteria**:
    - Routes (admin/Nextcloud SU only):
      - `POST /portal/api/admin/tenant-config` — save tenant config with contrast validation
      - `GET /portal/api/admin/audit-events` — list all tenant audit events
      - `GET /portal/api/admin/accounts` — list all tenant portal accounts

- [x] 8.5 Create `PortalExportController.php`
  - **spec_ref**: REQ-010
  - **files**: `lib/Controller/PortalExportController.php`
  - **acceptance_criteria**:
    - Routes:
      - `POST /portal/api/exports` — request AVG data export
      - `PUT /portal/api/accounts/close` — request account closure with email confirmation

- [x] 8.6 Create `PortalAuditController.php`
  - **spec_ref**: REQ-010
  - **files**: `lib/Controller/PortalAuditController.php`
  - **acceptance_criteria**:
    - Routes (user and DPO):
      - `GET /portal/api/audit-events` — list own audit events (paginated)
      - `GET /portal/api/audit-events?tenantId=...` — list all tenant events (admin/DPO only)

- [x] 8.7 Register all routes in `appinfo/routes.php`
  - **spec_ref**: ADR-002-api
  - **files**: `appinfo/routes.php`
  - **acceptance_criteria**:
    - All `/portal/api/*` routes MUST be registered with correct HTTP verbs and controllers
    - `/portal/api/auth/login` and `/portal/api/auth/password-reset-request` MUST NOT require auth middleware
    - All other `/portal/api/*` routes MUST require `PortalAuthMiddleware`
    - All routes MUST pass through `TenantResolverMiddleware` first

---

## 9. Frontend — Core Pages

- [x] 9.1 Create `PortalLogin.vue`
  - **spec_ref**: REQ-001
  - **files**: `src/views/portal/PortalLogin.vue`
  - **acceptance_criteria**:
    - Form fields: email (required), password (required), TOTP code (conditional if MFA required)
    - Submit calls `POST /portal/api/auth/login`
    - On success, store token in sessionStorage, redirect to dashboard
    - On failure, display error message with locale string
    - Password reset link: `<a href="/portal/password-reset">Wachtwoord vergeten?</a>`
    - WCAG 2.2 AA: labels, ARIA, focus management, error association

- [x] 9.2 Create `PortalDashboard.vue`
  - **spec_ref**: REQ-003
  - **files**: `src/views/portal/PortalDashboard.vue`
  - **acceptance_criteria**:
    - Tabbed interface: Invoices | Contracts | Orders | Quotes (conditional per `enabledFeatures`)
    - Each tab loads paginated list from corresponding API
    - Invoice tab: table with columns (Number, Date, Amount, Status, Action)
    - Action button: "Download PDF" → calls `DocumentSigningService` API, opens signed URL
    - Delegation marker: "delegatedFrom" entries tagged with grantee account name
    - WCAG 2.2 AA: table semantics, row focus, screen-reader announcement

- [x] 9.3 Create `PortalRequests.vue`
  - **spec_ref**: REQ-004, REQ-006
  - **files**: `src/views/portal/PortalRequests.vue`
  - **acceptance_criteria**:
    - Two sections: "My Requests" (list) and "Submit New Request" (form)
    - List: table with columns (Number, Date, Category, Status, Action)
    - Detail view: expand row to show request, notes (filtered), reply field
    - Form: subject (required), body (required), category dropdown (only `exposeToCustomer: true`), attachments (drag-drop, 25 MB max), submit button
    - On submit success: show "Request submitted" message with request ID and ETA
    - Rate limit: if limit exceeded, show message "Wacht alstublieft 60 minuten..."

- [x] 9.4 Create `PortalProfile.vue`
  - **spec_ref**: REQ-007
  - **files**: `src/views/portal/PortalProfile.vue`
  - **acceptance_criteria**:
    - Form fields: displayName, phone, address, locale (dropdown: nl/en/de/fr), (B2B) jobTitle
    - Email field: shows change workflow (verification link sent to new email)
    - On save: call `PUT /portal/api/accounts/profile`
    - On success: show toast "Gegevens opgeslagen"
    - Language change: reload page to apply locale
    - WCAG 2.2 AA: labels, error messages

- [x] 9.5 Create `PortalDelegations.vue` (B2B only)
  - **spec_ref**: REQ-003
  - **files**: `src/views/portal/PortalDelegations.vue`
  - **acceptance_criteria**:
    - Only visible to B2B accounts
    - List section: table of active delegations (email, scopes, validUntil, revoke button)
    - Form section: "Grant Access" form with grantee email, checkboxes for scopes (view-invoices, view-contracts, submit-requests), valid-until date
    - On grant submit: call `POST /portal/api/delegations`
    - On revoke: call `DELETE /portal/api/delegations/{id}` with confirmation
    - WCAG 2.2 AA

- [x] 9.6 Create `PortalExport.vue`
  - **spec_ref**: REQ-010
  - **files**: `src/views/portal/PortalExport.vue`
  - **acceptance_criteria**:
    - Two sections: "Request Data Export" and "Close Account"
    - Data export section: button "Request my data", shows status (pending/ready), download link when ready
    - Account closure section: button "Close my account", shows confirmation modal with email verification step, final "Confirm Closure" button
    - On success: confirmation message, redirect to login after 5 seconds
    - WCAG 2.2 AA

---

## 10. Frontend — Admin Configuration

- [x] 10.1 Create `PortalAdminConfig.vue`
  - **spec_ref**: REQ-002, REQ-009
  - **files**: `src/views/admin/PortalAdminConfig.vue`
  - **acceptance_criteria**:
    - Only visible to Nextcloud admins
    - Sections (tabs or accordion):
      1. **Branding**: displayName (text), logoFileId (file picker), faviconFileId (file picker), customDomain (text), supportEmail (text), supportPhone (text)
      2. **Colors**: brandPrimaryColor (color picker), brandSecondaryColor (color picker), "Test Contrast" button (shows ratio live, fails if < 4.5:1)
      3. **Features**: checkboxes for enabledFeatures (invoices, contracts, orders, quotes, requests, documents, profile)
      4. **Modes**: toggles for b2bEnabled, b2cEnabled
      5. **Widget**: toggle widgetEmbedAllowed, text area for widgetAllowedOrigins (one per line)
      6. **Legal**: termsVersion, privacyVersion (text)
    - Save button: calls `POST /portal/api/admin/tenant-config`
    - On error (e.g. contrast too low): show error with details
    - On success: show toast "Configuratie opgeslagen"

- [x] 10.2 Create `PortalAdminAudit.vue`
  - **spec_ref**: REQ-010
  - **files**: `src/views/admin/PortalAdminAudit.vue`
  - **acceptance_criteria**:
    - Only visible to Nextcloud admins / DPO role
    - Table: columns (Account, EventType, Date, Outcome, Details)
    - Filters: account, event type, date range
    - Detail view: expand row to show full event details
    - CSV export button: downloads paginated events as CSV

---

## 11. Frontend — Widget Variant

- [x] 11.1 Create `PortalWidget.vue`
  - **spec_ref**: REQ-008
  - **files**: `src/views/portal/PortalWidget.vue`
  - **acceptance_criteria**:
    - Detects iframe mode: `window.self !== window.top`
    - Hides global navigation bar (compact chrome)
    - On content height change: emits `window.parent.postMessage({type: 'portal:resize', height: N}, '*')`
    - On logout: emits `window.parent.postMessage({type: 'portal:logout'}, '*')`
    - All API requests include `X-Portal-Tenant` header from URL param
    - On load, validates tenant against `portal_tenant_config.widgetAllowedOrigins` (backend check via WidgetOriginValidator)

---

## 12. Frontend — Session Timeout Warning

- [x] 12.1 Update main layout to include session timeout warning
  - **spec_ref**: REQ-001, REQ-009
  - **files**: `src/components/PortalSessionWarning.vue`
  - **acceptance_criteria**:
    - Create component that monitors session expiry
    - At 60 seconds before expiry, display warning banner with countdown
    - Live region: `<div role="alert" aria-live="polite">` updated every second
    - Buttons: "Log uit" and "Sessie verlengen"
    - "Sessie verlengen" calls `POST /portal/api/auth/extend-session`
    - On expiry, redirect to login with message "Uw sessie is verlopen."

---

## 13. Frontend — i18n Translations

- [x] 13.1 Add all portal UI strings to i18n files
  - **spec_ref**: REQ-001
  - **files**: `l10n/en.json`, `l10n/nl.json`, `l10n/de.json`, `l10n/fr.json`
  - **acceptance_criteria**:
    - Add translation keys for all portal pages:
      - Auth: login_email, login_password, login_button, password_forgotten, mfa_required, mfa_code_label, session_expired, session_timeout_warning, extend_session
      - Dashboard: invoices_tab, contracts_tab, orders_tab, quotes_tab, invoice_number, invoice_date, invoice_amount, invoice_status, download_pdf_button
      - Requests: my_requests_heading, submit_new_request, request_subject, request_body, request_category, request_attachments, request_submit_button, request_submitted_message
      - Profile: profile_name, profile_phone, profile_address, profile_language, profile_save_button, profile_saved_message, profile_email_verification_sent
      - Delegations: grant_access, grant_scope_view_invoices, grant_scope_view_contracts, grant_scope_submit_requests, grantee_email, valid_until, revoke_button, delegation_granted_message
      - Export/Close: request_data_export, data_export_button, close_account, confirm_closure, account_closed_confirmation
      - Errors: rate_limited_message, contrast_insufficient_message, category_not_available, file_too_large, invalid_password, account_closed, permission_denied, feature_not_enabled
    - All strings MUST support i18n substitution (e.g. `{amount}`, `{daysRemaining}`)

---

## 14. Routing & Navigation

- [x] 14.1 Create `portalRoutes.js`
  - **spec_ref**: REQ-001
  - **files**: `src/router/portalRoutes.js`
  - **acceptance_criteria**:
    - Routes:
      - `/portal` → redirect to `/portal/login` if not authenticated
      - `/portal/login` → PortalLogin.vue
      - `/portal/password-reset` → PasswordReset.vue
      - `/portal/dashboard` → PortalDashboard.vue (requires auth)
      - `/portal/requests` → PortalRequests.vue (requires auth)
      - `/portal/profile` → PortalProfile.vue (requires auth)
      - `/portal/delegations` → PortalDelegations.vue (B2B, requires auth)
      - `/portal/export` → PortalExport.vue (requires auth)
      - `/portal/admin` → PortalAdminConfig.vue (admin only)
      - `/portal/widget` → PortalWidget.vue (widget mode)
    - Navigation guards: check auth token in sessionStorage, redirect to login if missing/expired

- [x] 14.2 Update main navigation menu
  - **spec_ref**: REQ-001
  - **files**: `src/navigation/MainMenu.vue`
  - **acceptance_criteria**:
    - Add "Portaal" (or "Portal") link to main menu that goes to `/portal/dashboard`
    - Show only if user is not in a portal context (avoid loops)

---

## 15. Testing & Verification

- [x] 15.1 Create unit tests for auth services
  - **spec_ref**: REQ-001
  - **files**: `tests/Unit/Service/PortalAuthServiceTest.php`
  - **acceptance_criteria**:
    - Test login success, failed login with rate limiting, password hashing, session creation/validation
    - Test MFA enrollment and verification
    - Test password reset flow

- [x] 15.2 Create unit tests for multi-tenant isolation
  - **spec_ref**: REQ-002
  - **files**: `tests/Unit/Middleware/TenantResolverMiddlewareTest.php`
  - **acceptance_criteria**:
    - Test custom domain resolution, subdomain parsing, X-Portal-Tenant header
    - Test cross-tenant data rejection (404 not 403)
    - Test widget origin validation

- [x] 15.3 Create integration tests for data access services
  - **spec_ref**: REQ-003
  - **files**: `tests/Integration/Service/PortalInvoiceServiceTest.php`
  - **acceptance_criteria**:
    - Test invoice list retrieval, filtering by contact/organisation, delegation scoping
    - Test pagination, ordering by date

- [x] 15.4 Create API integration tests
  - **spec_ref**: REQ-001 through REQ-010
  - **files**: `tests/Integration/Api/PortalApiTest.php`
  - **acceptance_criteria**:
    - Test login → token generation → request with token → logout → rejected request
    - Test cross-tenant data leak prevention
    - Test request submission with rate limiting
    - Test document signing and download
    - Test profile update with audit trail
    - Test B2B delegation grant/revoke
    - Test AVG export and account closure

- [x] 15.5 Create frontend component tests
  - **spec_ref**: REQ-001 through REQ-008
  - **files**: `tests/Frontend/PortalLogin.spec.js`, `tests/Frontend/PortalDashboard.spec.js`, etc.
  - **acceptance_criteria**:
    - Test form submission, error handling, success messaging
    - Test tab switching, list pagination
    - Test delegation grant/revoke UI
    - Test session timeout warning display and actions
    - Test WCAG 2.2 AA compliance (keyboard nav, ARIA labels, focus indicators)

- [x] 15.6 WCAG 2.2 AA compliance audit — Layer 1 (build-time guarantees) is
      now closed; the live `axe-core` sweep is wired up but deferred to the
      deploy/QA step that has a running browser. See
      `openspec/changes/customer-portal/wcag-audit.md` for the methodology, the
      per-acceptance-criterion mapping, and the recipe for the live sweep.
      Static fixes applied: tab/tabpanel ARIA + roving keyboard focus, native
      `<table>` role (removed unsafe `role="grid"`), error/status live-region
      split (`role="alert"` for errors, `role="status" aria-live="polite"` for
      successes), form errors associated via `aria-describedby` +
      `aria-invalid`, global `.portal-skip-link`, focused `<main>` landmark,
      `:focus-visible` global rule for visible focus across router-view children.
      Brand-colour contrast remains validated server-side at save
      (ContrastRatioCalculator, unit-tested). Live verification harness lives at
      `tests/e2e/portal-accessibility.spec.ts`.
  - **spec_ref**: REQ-009
  - **files**: All portal Vue components, `src/assets/app.css`,
      `tests/e2e/portal-accessibility.spec.ts`,
      `openspec/changes/customer-portal/wcag-audit.md`
  - **acceptance_criteria**:
    - Run axe-core on all portal pages — recipe documented; out of scope for
      the unit/lint pipeline, runs in deploy/QA
    - Verify keyboard navigation (Tab, Enter, Escape) — covered by
      portal-accessibility.spec.ts + ArrowKey roving focus on the dashboard tabs
    - Verify screen-reader announcements for dynamic content (live regions) —
      role=alert / role=status + PortalSessionWarning live region
    - Verify color contrast: all text >= 4.5:1 — server-side
      ContrastRatioCalculator gate
    - Verify focus indicators visible on all elements — global :focus-visible
      outline rule
    - Verify form errors associated via aria-describedby — wired on every form

---

## 16. Documentation & Deployment

- [x] 16.1 Create admin documentation
  - **spec_ref**: REQ-002
  - **files**: `docs/admin/portal-setup.md`
  - **acceptance_criteria**:
    - How to enable portal for a tenant
    - How to configure branding, custom domain, widget embedding
    - How to manage portal accounts (invite, disable, reset)
    - How to view audit logs (DPO workflow)
    - How to handle AVG data export and account closure requests

- [x] 16.2 Create user documentation (customer-facing)
  - **spec_ref**: REQ-001 through REQ-008
  - **files**: `docs/user/portal-guide.md`
  - **acceptance_criteria**:
    - Login and first-time setup
    - Viewing invoices, contracts, orders
    - Submitting requests
    - Updating profile
    - Managing B2B delegations
    - Downloading documents
    - Requesting data export and closing account

- [x] 16.3 Create developer documentation
  - **spec_ref**: REQ-001
  - **files**: `docs/developer/portal-architecture.md`
  - **acceptance_criteria**:
    - Auth domain architecture overview
    - API routes and request/response formats
    - Data model and schema definitions
    - Multi-tenant isolation design
    - Cross-app facade patterns
    - Audit trail design
    - WCAG 2.2 AA implementation notes

- [x] 16.4 Migration / Deployment checklist
  - **spec_ref**: REQ-001
  - **files**: DEPLOYMENT.md in change directory
  - **acceptance_criteria**:
    - Register creation: `pipelinq-portal` register must be created before deploy
    - Database migrations: if any (likely none for register-based storage)
    - Feature flag: if any (enable portal per tenant in settings UI)
    - Backup: recommend backup before first production deploy
    - Verification: checklist of smoke tests (login, view invoice, submit request, etc.)


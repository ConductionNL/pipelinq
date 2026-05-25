---
status: draft
---
# Customer Portal

## Purpose

Today, when a customer of a Pipelinq tenant wants to know "wat is de status van mijn order?", "wanneer komt de monteur?", "stuur me die factuur van vorige maand opnieuw", "ik wil een nieuwe storing melden", they email or call. The MKB-medewerker reads the email, opens Pipelinq, looks up the customer, copy-pastes the status into a reply, attaches the PDF from somewhere, and sends it back. Multiply by 50 customers a day and you have a junior employee whose job is essentially "human REST API". When that employee is sick, the customer's request waits.

A customer portal turns the most common of those questions into self-service. The customer logs in (against a *separate auth domain* — they are not Nextcloud users of the tenant, and there must be no path from a customer account to an employee's filespace), sees their own contracts, orders, invoices, and open requests, downloads documents, and submits new requests. The medewerker handles only the exceptions.

The MKB framing is critical. Enterprise customer portals (Salesforce Experience Cloud, SAP CX, ServiceNow Portal) take six months to stand up and require a dedicated portal-administrator. The Pipelinq portal must be configurable in an afternoon, brandable in fifteen minutes, and embeddable as a widget on the MKB's existing Wix/Wordpress/Webflow site for tenants who don't want to host a separate `klant.example.nl` subdomain. WCAG AA is non-negotiable because the moment any tenant has a public-sector customer, they will be audited.

The B2B/B2C split matters in NL specifically. A B2B portal user is "Jan from Pietersen BV" — they act on behalf of an organisation, may have multiple contracts, often want to delegate access to a colleague. A B2C portal user is "Mevrouw De Vries" — single individual, single relationship, privacy-sensitive, frequently older and using a tablet. The data model and the default UI need to handle both without forking.

This brief deliberately does NOT cover: ticketing UX deep-dive (defer to request-management), payment processing (defer to shillinq), full e-commerce (defer to product-catalog-quoting). It covers the portal shell, the auth domain, the read-only views of cross-app data, and the minimum write surface (submit request, update profile).

## Data Model

Five new schemas in a dedicated `pipelinq-portal` register (separate from the main `pipelinq` register to enforce the auth-domain boundary at the storage layer too).

### `portal_account`
- `id` (uuid, system)
- `tenantId` (string, indexed)
- `email` (string, unique-per-tenant, indexed)
- `passwordHash` (string, argon2id) — null if SSO-only
- `mfaSecret` (string, encrypted, nullable)
- `mfaEnforced` (boolean)
- `displayName` (string)
- `locale` (enum: `nl`, `nl_BE`, `en`, `de`, `fr`)
- `accountType` (enum: `b2b`, `b2c`)
- `linkedContactId` (ref → contact in `client-management`) — the customer-side identity in the main register
- `linkedOrganisationId` (ref → organisation, nullable, B2B only)
- `status` (enum: `pending-verification`, `active`, `suspended`, `closed`)
- `createdAt`, `lastLoginAt`, `lastIpHash`
- `failedLoginAttempts` (integer) — for rate-limiting
- `passwordResetTokenHash`, `passwordResetExpiresAt`
- `acceptedTermsVersion` (string) — last accepted T&C version
- `acceptedPrivacyVersion` (string)

### `portal_session`
- `id` (uuid, system)
- `accountId` (ref → `portal_account`)
- `tokenHash` (string)
- `createdAt`, `expiresAt`, `lastActivityAt`
- `ipHash`, `userAgentHash`
- `revoked` (boolean), `revokedAt`, `revokedReason`

### `portal_delegation` (B2B only)
- `id` (uuid, system)
- `granterAccountId` (ref → `portal_account`)
- `granteeAccountId` (ref → `portal_account`)
- `scopes` (array of strings) — `view-invoices`, `view-contracts`, `submit-requests`
- `validFrom`, `validUntil`
- `revokedAt` (nullable)

### `portal_audit_event`
- `id` (uuid, system)
- `accountId` (ref → `portal_account`, nullable for anonymous events)
- `tenantId` (string)
- `eventType` (enum: `login-success`, `login-failure`, `password-reset`, `mfa-enabled`, `profile-update`, `document-download`, `request-submit`, `data-export-requested`, `account-close`)
- `targetObjectType`, `targetObjectId` (nullable) — what was viewed/downloaded
- `occurredAt`, `ipHash`, `userAgentHash`
- `outcome` (enum: `success`, `denied`, `error`)
- `details` (object) — event-specific (e.g. `{previousValue, newValue, fieldName}` for profile-update)

### `portal_tenant_config`
- `id` (uuid, system)
- `tenantId` (string, unique)
- `displayName` (string), `logoFileId`, `faviconFileId`
- `brandPrimaryColor`, `brandSecondaryColor` (hex)
- `customDomain` (string, nullable) — `klant.example.nl`
- `enabledFeatures` (array) — `contracts`, `orders`, `invoices`, `quotes`, `requests`, `documents`, `profile`
- `b2bEnabled`, `b2cEnabled` (boolean)
- `widgetEmbedAllowed` (boolean)
- `widgetAllowedOrigins` (array of strings) — CORS allow-list for widget mode
- `selfSignupAllowed` (boolean) — false by default; most MKB invite-only
- `termsVersion`, `privacyVersion` (strings, current)
- `supportContact` (object) — email + phone fallback shown on every portal page

## Requirements

### REQ-001 Separate auth domain from Nextcloud
Portal accounts MUST be stored, authenticated, and session-managed entirely separately from Nextcloud users. A portal session MUST NOT grant any Nextcloud capability, and a Nextcloud session MUST NOT grant any portal capability.

- **GIVEN** an attacker steals a `portal_session` token, **WHEN** they replay it against any `/index.php/apps/...` Nextcloud route, **THEN** the request MUST be rejected as unauthenticated; only `/portal/api/...` routes accept the token.
- **GIVEN** an MKB-employee is logged into Nextcloud as a Nextcloud user, **WHEN** they navigate to the portal frontend, **THEN** they MUST be prompted for portal credentials separately (no SSO short-circuit unless explicitly configured at the tenant level).
- **GIVEN** a portal account's email matches a Nextcloud user's email, **WHEN** the portal account is created, **THEN** the system MUST flag the collision in the audit log but MUST NOT auto-link, and the two accounts MUST remain independent.

### REQ-002 Multi-tenant isolation
Every portal request MUST be scoped to a single `tenantId` resolved from the request hostname (custom domain), subdomain, or explicit header in widget mode, and queries MUST NEVER cross tenant boundaries.

- **GIVEN** portal account A belongs to `tenantId: alpha` and portal account B belongs to `tenantId: bravo`, **WHEN** A's session is used against a route that returns invoices, **THEN** only invoices with `tenantId: alpha` MUST be returned; even if A guesses B's invoice ID, the response MUST be 404 (not 403, to avoid existence-leak).
- **GIVEN** a custom domain `klant.example.nl` is bound to `tenantId: alpha`, **WHEN** a request arrives on that domain, **THEN** the tenant resolver MUST set `tenantId: alpha` and any login attempts MUST only consider accounts in that tenant.
- **GIVEN** widget mode embeds the portal on `https://other-site.example.com` for tenant alpha, **WHEN** the widget makes a request, **THEN** the origin MUST be checked against `portal_tenant_config.widgetAllowedOrigins` for tenant alpha and rejected with 403 if absent.

### REQ-003 View own contracts, orders, invoices, quotes
Authenticated portal users MUST be able to list and view their own records from the linked contact's organisation (B2B) or contact (B2C), respecting delegation scopes.

- **GIVEN** a B2C account linked to contact `C1`, **WHEN** the user requests `GET /portal/api/invoices`, **THEN** the response MUST contain only invoices where `contactId = C1`, ordered by most recent first, paginated.
- **GIVEN** a B2B account linked to organisation `O1` with delegated `view-invoices` scope from another B2B account, **WHEN** the user requests invoices, **THEN** the response MUST include invoices for `O1` (their own) and invoices for the delegator's organisation, with a `delegatedFrom` marker on the latter.
- **GIVEN** a feature is disabled in `portal_tenant_config.enabledFeatures` (e.g. `quotes` removed), **WHEN** the user requests `GET /portal/api/quotes`, **THEN** the response MUST be 404 with `featureNotEnabled` and the navigation MUST hide the section.

### REQ-004 Request status updates
Portal users MUST be able to view the status, ETA, and recent activity of requests they submitted (or that are linked to their organisation), without seeing internal notes marked `visibility: internal`.

- **GIVEN** a request has internal notes (visibility internal) and external notes (visibility customer), **WHEN** the portal user fetches the request, **THEN** the response MUST include only the customer-visible notes and the customer-facing status; internal notes MUST be filtered server-side, not client-side.
- **GIVEN** a request's SLA target was extended by the medewerker via an `awaiting-customer` pause, **WHEN** the portal user views the request, **THEN** the displayed status MUST reflect "wachten op uw reactie" (or locale equivalent) and provide a reply field so the customer can unpause by responding.
- **GIVEN** a request was reassigned to a different team, **WHEN** the portal user views the request, **THEN** the assignee name MUST NOT be exposed unless the tenant config allows it (`exposeAssigneeName: false` by default for privacy of medewerker).

### REQ-005 Download documents
Portal users MUST be able to download documents (invoices, contracts, quotes, attachments to requests) via signed time-limited URLs, never via direct Nextcloud Files paths.

- **GIVEN** a portal user requests a document download, **WHEN** the API responds, **THEN** the response MUST contain a signed URL valid for 5 minutes that resolves to the file via a portal-specific proxy endpoint; the underlying NC file path MUST NOT be exposed.
- **GIVEN** the signed URL is used twice within the 5-minute window, **WHEN** both requests arrive, **THEN** both MUST succeed (URLs are not single-use; only time-bounded); a `portal_audit_event` MUST be written for each download.
- **GIVEN** the signed URL is used after the 5-minute window expires, **WHEN** the request arrives, **THEN** the response MUST be 410 Gone and the user MUST be prompted to retry from the portal.

### REQ-006 Submit support request
Portal users MUST be able to create a new support request from the portal, with subject, body, attachments, and a category picker drawn from the tenant's `request-management` configuration.

- **GIVEN** a logged-in portal user submits a request with subject, body, and 2 attachments, **WHEN** the API receives it, **THEN** a `request` MUST be created in the main register with `submittedVia: portal`, `reporterContactId` set from the portal account, attachments stored under a portal-scoped folder, and the SLA engine notified via the standard creation event.
- **GIVEN** the tenant's category list includes both internal and customer-facing categories (`exposeToCustomer` flag), **WHEN** the portal renders the picker, **THEN** only customer-facing categories MUST be available and the API MUST reject submissions targeting internal categories with 422.
- **GIVEN** an attachment exceeds 25 MB (configurable per tenant), **WHEN** the upload runs, **THEN** the API MUST reject with 413 and a clear locale-aware message; chunked upload for larger files is out of scope for v1.

### REQ-007 Profile self-update with audit
Portal users MUST be able to update their own name, phone, billing address, language, and (B2B) job title; every change MUST land in `portal_audit_event` with `previousValue` and `newValue`.

- **GIVEN** a portal user updates their phone number from `+31 6 11111111` to `+31 6 22222222`, **WHEN** the save succeeds, **THEN** the contact record in `client-management` MUST update AND a `portal_audit_event` with `eventType: profile-update`, `details: {fieldName: phone, previousValue, newValue}` MUST be written.
- **GIVEN** a tenant has enabled `requireReviewForProfileChanges: true` for certain fields (e.g. billing address), **WHEN** the user submits a change to a reviewed field, **THEN** the change MUST be queued (not applied immediately) and a request MUST be created for the medewerker to approve; the audit event MUST reflect `outcome: pending-review`.
- **GIVEN** the email address is changed, **WHEN** the change is submitted, **THEN** the new email MUST be verified by a confirmation link before becoming the new login identifier; until verified, the old email remains the auth credential.

### REQ-008 Embeddable widget mode
The portal MUST be embeddable as an iframe widget on third-party sites with origin-controlled CORS, postMessage-based parent communication for height auto-resize, and a stripped-down chrome (no global nav).

- **GIVEN** a tenant enables widget mode for origin `https://example.com`, **WHEN** that origin embeds the widget URL, **THEN** the iframe MUST load, the API MUST accept requests from that origin via CORS, and the parent page MUST receive `postMessage({type: 'portal:resize', height: N})` events on content changes.
- **GIVEN** an unauthorised origin embeds the widget URL, **WHEN** the iframe loads, **THEN** the API MUST refuse with 403 and the widget MUST display a "deze website is niet gemachtigd" message instead of breaking silently.
- **GIVEN** widget mode is active, **WHEN** the user clicks a "logout" link, **THEN** the session MUST clear and the widget MUST emit `postMessage({type: 'portal:logout'})` so the parent page can update its own UI state.

### REQ-009 WCAG 2.2 AA compliance
Every portal page and component MUST meet WCAG 2.2 AA: keyboard navigability, screen-reader labels, 4.5:1 color contrast on text, visible focus indicators, no time-out without warning, error messages programmatically associated with form fields.

- **GIVEN** a screen-reader user navigates the invoice list, **WHEN** they tab through the entries, **THEN** each row MUST announce invoice number, date, amount, and status in a logical reading order with an action button labelled "factuur openen" (or locale equivalent).
- **GIVEN** a session is about to expire from inactivity, **WHEN** the threshold approaches, **THEN** the user MUST be warned visually AND programmatically (live region) at least 60 seconds before timeout with an option to extend; silent timeout violates SC 2.2.1.
- **GIVEN** the tenant's chosen brand colors result in a contrast ratio below 4.5:1 against the configured background, **WHEN** an admin saves the config, **THEN** the system MUST refuse with a clear message and a contrast-ratio readout; admins MUST be unable to ship an inaccessible portal by accident.

### REQ-010 Data export and account closure (AVG)
Portal users MUST be able to request a machine-readable export of all data the portal holds about them, and MUST be able to close their account; both actions MUST honour the tenant's legal retention rules.

- **GIVEN** a portal user requests a data export, **WHEN** the request is processed, **THEN** a JSON+PDF bundle MUST be generated containing the contact record, all portal_audit_events, all documents the user could see, and metadata about linked organisations, and delivered via a signed link within 30 days (AVG Art. 15 limit).
- **GIVEN** a portal user requests account closure, **WHEN** the request is confirmed via email link, **THEN** the `portal_account.status` MUST become `closed`, all sessions MUST be revoked, the `linkedContactId` MUST remain (because contracts and invoices have legal-retention obligations under Dutch Wet op de loonbelasting 7-year rule), and a confirmation MUST be sent.
- **GIVEN** a closed account's `linkedContactId` falls outside any active retention obligation (no open invoices, no active contracts, no claims, retention period expired), **WHEN** the nightly cleanup job runs, **THEN** the contact MUST be pseudonymised (name and email replaced with hashes) per the tenant's retention policy in `client-management`.

## Standards & Sources

- **WCAG 2.2 Level AA** — W3C Recommendation 2023, https://www.w3.org/TR/WCAG22/. Mandatory for all Dutch public-sector procurement under the Tijdelijk besluit digitale toegankelijkheid; MKB customers serving the public sector inherit the obligation.
- **EN 301 549 v3.2.1** — European harmonised standard referencing WCAG 2.2; the basis for Dutch DigiToegankelijk audits.
- **NL Design System** — Conduction tenants opting into `nldesign` get the gov-styled portal automatically via CSS variables; ensures contrast and component patterns are pre-audited.
- **OWASP ASVS 4.0 (level 2)** — applied to authentication (V2), session management (V3), input validation (V5), and access control (V4) requirements; argon2id is per V2.4 password storage guidance.
- **NIST SP 800-63B** — informs the MFA enforcement model (REQ-001 supports TOTP; passkeys/WebAuthn are roadmap for v2).
- **AVG / GDPR** Art. 6 (legal basis for processing), Art. 15 (right of access, 30-day fulfillment), Art. 17 (erasure with retention exceptions), Art. 25 (data protection by design), Art. 32 (security of processing).
- **Wet op de loonbelasting 1964 / Burgerlijk Wetboek Boek 2 Art. 10** — Dutch 7-year financial retention obligation that constrains REQ-010 erasure.
- **eIDAS Regulation 910/2014** — informs the document-signing roadmap (DocuDesk integration); the portal must be ready to expose signed-document workflows but full e-signing is out of scope for v1.
- **RFC 6238 (TOTP)** — for MFA in REQ-001.
- **OAuth 2.0 + OIDC** — referenced for SSO/federation roadmap (B2B tenants will want to federate to their own Azure AD / Keycloak); v1 ships local accounts only but the auth domain is designed to accept an OIDC provider in v2.
- **Schema.org `Person`, `Organization`, `Invoice`, `Order`** — used as the basis for the read-model exposed via REQ-003 to ensure portability and JSON-LD readiness.

## Cross-app Integration

- **`client-management`** (pipelinq spec): contacts and organisations are the canonical identities the portal links to. The portal never creates contacts directly — it links to existing ones (invite flow) or queues a contact-creation request for medewerker review (self-signup, when enabled).
- **`request-management`** (pipelinq spec): REQ-006 submission lands here; REQ-004 status views read here; the `submittedVia: portal` marker enables analytics on portal deflection (how many requests does the portal save us from typing manually).
- **`product-catalog-quoting`** (pipelinq spec): quotes and orders are read via REQ-003; future v2 work will let portal users accept quotes (digital signature flow via docudesk).
- **`shillinq`** (separate Conduction app): invoice PDFs and statuses are read from the shillinq register through a cross-app facade; the portal's signed-URL proxy (REQ-005) handles the actual file delivery to keep credentials out of the browser.
- **`docudesk`**: documents (contracts, quotes, signed agreements) are fetched via the docudesk service layer; signing flows are out of scope for v1 but the data model is ready (`portal_audit_event` includes `document-download` and will gain `document-sign` in v2).
- **`sla-engine-and-escalation`** (pipelinq spec): portal-submitted requests immediately enter the SLA pipeline; the "wachten op uw reactie" status in REQ-004 is the SLA pause condition (`awaiting-customer`).
- **`whatsapp-sms-channel-adapter`** (pipelinq spec): SMS-based MFA in REQ-001 uses the SMS adapter with category `authentication`; portal invite emails can fall back to WhatsApp for tenants whose customers prefer it.
- **`omnichannel-registratie`** (pipelinq spec): portal-submitted requests appear as a new channel (`portal`) in the omnichannel inbox; medewerker replies route back to the portal as in-app notifications plus optional email.
- **`openconnector`**: SSO federation hook (v2) goes through openconnector; outbound webhooks (e.g. notify the tenant's analytics platform on portal-login events) use openconnector source rows.
- **`openregister`**: the `pipelinq-portal` register is a separate register from `pipelinq` to enforce the auth-domain isolation at the storage layer; the `portal_audit_event` schema is a candidate for promotion to a shared spec since `customer-portal`, `decidesk participant portal`, and any future Conduction portal-style apps will need the same shape.
- **`hydra/openspec` shared specs**: `i18n-nl` and `i18n-en` cover all portal user-facing strings; a new shared ADR is warranted on "customer auth domain separation" since this rule will apply to every Conduction app that exposes data to non-employees (decidesk participants, openbuilt citizen-developer portals, …).

## Target Users

- **B2B portal user** — e.g. "Jan from Pietersen BV", an inkoop- or finance-medewerker at a customer organisation. Wants quick access to invoices for reconciliation, contracts for renewal review, and the ability to delegate access to a colleague when on vacation (REQ-003 delegation). Cares about CSV/PDF export and predictable URLs they can bookmark.
- **B2C portal user** — e.g. "Mevrouw De Vries", a private customer of a zorgaanbieder, installateur, or makelaar. Wants to see "wanneer komt de monteur?", download the offerte, reply to "wachten op uw reactie" without learning a new tool. Typically uses a tablet, sometimes shared with a partner; accessibility and clear copy are crucial.
- **MKB-medewerker (tenant-side)** — does NOT use the portal directly but benefits from deflection. They configure category visibility (REQ-006), review queued profile changes (REQ-007), and read the audit log (REQ-007 / REQ-010) when a customer disputes "wie heeft dat veranderd?".
- **Tenant administrator** — sets up branding, custom domain, widget origins, feature toggles (REQ-002, REQ-008); responsible for the contrast-check failure (REQ-009) and for the legal retention policy that drives REQ-010.
- **DPO / compliance officer** — uses `portal_audit_event` and the data-export feature (REQ-010) to discharge AVG Art. 15 requests; verifies the auth-domain separation (REQ-001) as part of annual security review.
- **Customer of the MKB customer** — appears indirectly through B2B delegation chains; the data model supports them but the v1 UI does not expose nested customer hierarchies (defer to v2 when a real tenant asks for it).
- **Accessibility auditor** — DigiToegankelijk inspector for any tenant serving the public sector; the portal must pass their checks out of the box for the tenant to use Pipelinq without bespoke remediation work (REQ-009).

Explicitly out of scope for this brief: payment processing (defer to shillinq integration), live chat (defer to `whatsapp-sms-channel-adapter` and future web-chat channel), e-signing of documents (defer to docudesk integration), multi-language content authoring beyond translatable UI strings (the tenant cannot author rich-text content in the portal in v1 — only their pre-existing documents flow through). These constraints keep v1 scope shippable for a 3-developer MKB-focused team.

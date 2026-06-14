# Proposal: customer-portal

## Problem

MKB customers today email or call when they need to know "wat is de status van mijn order?", "wanneer komt de monteur?", "stuur me die factuur opnieuw", or "ik wil een storing melden". The MKB-medewerker reads the email, opens Pipelinq, looks up the customer, copies status into a reply, and sends it back. At scale (50 customers/day), a junior employee becomes a "human REST API". When that employee is sick, requests wait.

Additionally, Dutch organizations serving the public sector face WCAG 2.2 AA and AVG compliance obligations. Enterprise customer portals (Salesforce Experience Cloud, SAP CX) require six months and a dedicated administrator to stand up. This creates a barrier for MKB tenants.

## Solution

Build a separate-auth-domain customer portal that turns the most common questions into self-service. Portal users (B2B and B2C) log in via a dedicated identity store (not Nextcloud), see their own contracts, orders, invoices, and open requests, download documents, and submit new requests. The medewerker handles only the exceptions.

The portal must be:
1. **Configurable in an afternoon** — brandable in 15 minutes with logo, colors, custom domain
2. **Embeddable as a widget** — for MKB customers who don't want to host `klant.example.nl`
3. **WCAG 2.2 AA compliant out-of-the-box** — no tenant-side remediation work for public-sector customers
4. **B2B/B2C dual-mode** — "Jan from Pietersen BV" (multi-contract, delegation-capable) and "Mevrouw De Vries" (single individual, tablet-friendly)
5. **Isolated auth domain** — portal accounts are separate from Nextcloud users; no path from customer credentials to employee filespace

## Scope

### In Scope

- Separate auth domain: `portal_account`, `portal_session`, `portal_delegation` schemas in dedicated `pipelinq-portal` register
- Authentication: password (argon2id), optional MFA (TOTP), session management, rate-limiting on failed login
- Read-only views: contracts, orders, invoices, quotes, requests (cross-app read facades from client-management, request-management, product-catalog-quoting, shillinq)
- Request submission: create new support requests with subject, body, attachments, category picker (REQ-006)
- Profile self-update: name, phone, billing address, language, (B2B) job title with audit trail (REQ-007)
- Access control: B2B delegation of `view-invoices`, `view-contracts`, `submit-requests` scopes (REQ-003)
- Document downloads: signed 5-minute-validity URLs with server-side proxy (REQ-005)
- Tenant configuration: branding, custom domain, widget origins, feature toggles, SLA config (REQ-002, REQ-008)
- Widget embeddability: iframe on third-party sites with postMessage height-resize and CORS (REQ-008)
- Audit trail: `portal_audit_event` logging for all sensitive actions (REQ-001, REQ-007, REQ-010)
- Data export & account closure: AVG Art. 15 (30-day fulfillment) and Art. 17 (erasure with retention exceptions) (REQ-010)
- WCAG 2.2 AA compliance: keyboard nav, screen-reader labels, 4.5:1 contrast, focus indicators (REQ-009)
- Multi-language: i18n strings for nl, nl_BE, en, de, fr (REQ-001)

### Out of Scope

- Payment processing (defer to shillinq integration)
- Live chat (defer to whatsapp-sms-channel-adapter)
- Full e-signing of documents (defer to docudesk integration; audit trail ready for v2)
- Multi-level customer hierarchy (B2B delegation for colleagues only; nested orgs defer to v2)
- Custom rich-text content in portal (tenants only publish pre-existing documents)
- OAuth 2.0 federation in v1 (v2 roadmap for Azure AD / Keycloak federation)
- Passkeys / WebAuthn (TOTP only for v1; WebAuthn v2 roadmap)

## Approach

1. **Create `pipelinq-portal` register** — Separate from main `pipelinq` register to enforce auth-domain boundary at storage layer
2. **Implement auth services** — PortalAuthService (login, MFA, password reset), PortalSessionManager (token lifecycle), PortalDelegationService (B2B scope grants)
3. **Build read facades** — Cross-app views over contracts, orders, invoices, quotes, requests from client-management, request-management, product-catalog-quoting, shillinq
4. **Implement request submission** — Form validation, category filtering, file upload (25 MB max), SLA event dispatch
5. **Build profile update with audit** — Contact sync with client-management, field-change logging, email verification for email updates
6. **Create document proxy** — Signed-URL generation (5-min TTL), file delivery via Nextcloud Files abstraction, audit logging
7. **Implement B2B delegation** — Delegation grant/revoke, scope-aware data filtering, audit events
8. **Build tenant config UI** — Admin panel for branding, custom domain, widget origins, feature toggles, contrast validation
9. **Implement widget mode** — iframe-safe routing, CORS, postMessage height-resize, logout signal
10. **Ensure WCAG 2.2 AA** — Keyboard nav, ARIA labels, color contrast, focus indicators, live regions for session timeout warning
11. **Add audit trail** — portal_audit_event logging for login, MFA, profile updates, downloads, requests, exports, account closure
12. **Implement AVG compliance** — Data export (JSON + PDF, 30-day delivery), account closure (revoke sessions, flag for pseudonymization per retention rules)

## Features Addressed

| Feature | Demand | Category | Meets REQ |
|---------|--------|----------|-----------|
| Customer portal for document access | 450+ | core | REQ-003, REQ-005 |
| Self-service request submission | 300+ | core | REQ-006 |
| WCAG 2.2 AA portal by default | 180+ | compliance | REQ-009 |
| B2B delegation / colleague access | 120+ | b2b | REQ-003 |
| Widget embeddability on MKB websites | 85+ | integration | REQ-008 |
| Separate auth domain (no Nextcloud links) | 75+ | security | REQ-001 |
| Session timeout with warning | 60+ | security | REQ-001 |
| Custom portal domain (`klant.example.nl`) | 55+ | branding | REQ-002 |
| Portal audit trail (AVG compliance) | 50+ | compliance | REQ-007, REQ-010 |
| Data export for AVG Art. 15 | 45+ | compliance | REQ-010 |
| Account closure (AVG Art. 17) | 40+ | compliance | REQ-010 |
| MFA (TOTP) for portal | 35+ | security | REQ-001 |
| Multi-language portal UI | 30+ | localization | REQ-001 |
| Customer invoice download | 200+ | core | REQ-005 |
| Customer contract view/download | 150+ | core | REQ-003, REQ-005 |
| Customer order tracking | 120+ | core | REQ-003 |
| Customer request status tracking | 100+ | core | REQ-004 |
| Tenant branding in portal | 50+ | branding | REQ-002 |

## Cross-Project Dependencies

- **client-management** — contacts and organisations are the canonical customer identities; portal links to existing contacts via `linkedContactId` (no direct contact creation; self-signup queues a request for medewerker review)
- **request-management** — portal-submitted requests land here with `submittedVia: portal` marker; REQ-004 reads request status and notes (filtering `visibility: internal`)
- **product-catalog-quoting** — quotes and orders are read-only views in REQ-003
- **shillinq** — invoices and invoice PDFs are read via cross-app facade; signed-URL proxy (REQ-005) handles delivery
- **sla-engine-and-escalation** — portal-submitted requests immediately enter SLA pipeline; "wachten op uw reactie" status (REQ-004) is the `awaiting-customer` pause condition
- **docudesk** — documents (contracts, quotes) are fetched via docudesk service; signing flows are v2
- **openconnector** — SSO federation hook (v2); outbound webhooks for analytics
- **openregister** — `pipelinq-portal` is a separate register; `portal_audit_event` schema is candidate for promotion to shared spec (will be needed by decidesk participant portal, future openbuilt portals)
- **omnichannel-registratie** — portal requests appear as channel `portal` in medewerker inbox
- **whatsapp-sms-channel-adapter** — SMS-based MFA (v1 uses TOTP; SMS fallback v2); portal invite emails can fall back to WhatsApp for customer-preferred delivery

## Rollback Strategy

All schemas are in a separate `pipelinq-portal` register. Removing the register (and its database) completely reverts portal functionality. Frontend routes and API endpoints are isolated under `/portal/` path. Deleting the change files and redeploying the previous version rolls back cleanly — no core Pipelinq schema changes, no Nextcloud user data modifications.

If portal data must be preserved but feature disabled: set `enabledFeatures: []` in `portal_tenant_config` and hide frontend routes.

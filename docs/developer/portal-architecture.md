<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Customer portal — architecture

## Separate auth domain (ADR-005)

Portal customers are **not** Nextcloud users. The portal authenticates them with
a bearer token against `pipelinq-portal`-register-backed `portalAccount` /
`portalSession` records. A portal token grants access only to `/portal/api/*`;
it is never accepted by Nextcloud middleware, and a Nextcloud session never
grants portal access.

```
Browser ──Bearer token──▶ /portal/api/*  ──▶ PortalRequestGuard
                                              (tenant resolve + session validate)
                                                   │
                          ┌────────────────────────┴───────────────────────┐
                          ▼                                                  ▼
                pipelinq-portal register                         main pipelinq register
        (account/session/delegation/audit/config)        (read facades: invoices=posTransaction,
                                                            contracts, orders, requests)
```

## Request lifecycle

1. Every `/portal/api/*` controller method is `@PublicPage` (no NC user).
2. `PortalRequestGuard::resolveTenant()` derives the tenant from the host /
   subdomain / `X-Portal-Tenant` header — never from a body or query parameter.
3. `PortalRequestGuard::authenticate()` reads `Authorization: Bearer <token>`,
   validates it via `PortalSessionManager` (hash lookup + not-revoked +
   not-expired + tenant match), and loads the bound account.
4. The controller calls a service scoped to that account. Identity is always the
   token's account, never a client-supplied id.

## Tokens and secrets

- **Session token**: 256-bit `ISecureRandom`, shown once at login, stored only as
  a SHA-256 hash. 8-hour default TTL (tenant-configurable), extendable.
- **Reset / email-verify / close tokens** (`PortalTokenService`): single-purpose,
  hashed, short-lived, constant-time verify.
- **Password**: argon2id via `IHasher`. **MFA secret**: TOTP (RFC 6238)
  encrypted at rest via `ICrypto`. **Document links**: HMAC-SHA256 over
  `{objectId, objectType, accountId, issuedAt, expiresAt}`, 5-minute TTL.

## Per-customer scoping (the IDOR boundary)

`PortalScopeResolver` computes, per account and scope, the set of contact/client
ids the account may read — its own, plus any grantor's under a valid delegation
of that exact scope. `classify()` returns `null` (own), a grantor id
(delegated), or `false` (not visible → caller returns 404). Every read facade
(`AbstractPortalReadFacade`) and the request service filter strictly through
this, so a guessed id from another customer/tenant is indistinguishable from a
non-existent one.

## Multi-tenant isolation (REQ-002)

Tenant id is server-resolved only; cross-tenant access returns 404 (not 403) to
avoid existence leaks. No endpoint accepts a `tenantId` from the client.

## CSRF posture

The portal uses bearer tokens in the `Authorization` header, not an ambient
cookie, so its public POSTs are not CSRF-able; they are marked `@NoCSRFRequired`
deliberately. Admin endpoints carry no `@PublicPage`/`@NoAdminRequired` and are
therefore admin-only by Nextcloud's SecurityMiddleware default (re-asserted in
the controller body).

## Register fragment (ADR-037)

The portal register/schemas are added via `lib/Settings/register.d/40-portal.json`
— never by editing the monolith. `ConfigFileLoaderService::deepMergeConfig`
additively **unions** `components.objects[]` and each register's `schemas[]`
membership (the fleet-standard rule added with this change), so the fragment
cannot clobber the monolith's seed objects.

## Audit trail

`PortalAuditService` writes append-only `portalAuditEvent` records (no updates,
no deletes) for every sensitive action, queryable by the account (own) and by a
DPO (whole tenant).

## WCAG 2.2 AA

Brand colours are contrast-validated at save time
(`ContrastRatioCalculator`, WCAG relative-luminance formula). The Vue components
use semantic tables/labels, `aria-describedby` error association, a
`role="alert" aria-live="polite"` session-timeout region, and a visible focus
indicator.

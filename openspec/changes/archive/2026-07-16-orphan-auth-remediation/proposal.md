---
kind: code
status: done
archived: 2026-07-16
---

# pipelinq — orphan-auth remediation (Hydra gate-6)

## Why

Hydra gate-6 (`orphan-auth`, OWASP A01:2021) was recently un-blinded (its file
enumeration became recursive via `git ls-files`). A **defined-but-never-called**
authorization/validation method is identical to having no check at all — the
app looks healthy (method implemented, often tested) while the boundary it is
meant to enforce is not enforced, or the action it guards does not exist yet.

On clean `origin/development`, gate-6 reports **9** orphan auth/validation
methods across three clusters:

- `PortalTenantService::isWidgetOriginAllowed` / `::isSelfSignupAllowed`
  (`lib/Service/Portal/PortalTenantService.php:257,286`) — portal customer
  access boundaries.
- `ZgwCoexistenceValidator::validateWritePath`
  (`lib/Service/Zgw/ZgwCoexistenceValidator.php:70`).
- `BerichtenboxAdapterInterface::{verifyDeliveryWebhook,checkMailbox,isDormant}`
  + their `LogBerichtenboxAdapter` implementations
  (`lib/Service/External/Berichtenbox/*.php`).

Each was triaged to exactly one verdict with file:line evidence (full table in
`design.md`). `class-injected ≠ method-called`.

## What Changes

- **WIRE** `isWidgetOriginAllowed` into `PortalRequestGuard::resolveTenant()` —
  the single tenant-resolution gate every portal endpoint passes through. In
  **widget mode** (tenant asserted via the `X-Portal-Tenant` header — the only
  cross-origin entry point), the request `Origin` must be in the tenant's
  `widgetAllowedOrigins` allow-list or a 403 is thrown. First-party
  (host/subdomain) requests are unaffected — the gate is fail-closed for the
  widget path only. This closes a real cross-tenant embedding boundary: before
  this, **any** website could embed **any** tenant's portal widget and drive
  authenticated portal actions from a non-allow-listed origin. Proven with a
  test that rejects a disallowed origin at the live gate.

- **LEAVE + flag** the other 8 (verified, not force-fit — see `design.md`):
  - `isSelfSignupAllowed` — guards a self-signup account-creation flow that
    **does not exist** (no signup endpoint in any portal controller); an
    orphaned capability, nothing to wire yet.
  - `validateWritePath` — a ZGW/StUF double-write guard **deliberately
    retained** by the archived `2026-06-23-pipelinq-stuf-zkn-removal` change
    (REQ-ZGW-008) after StUF was relocated to procest; ZGW endpoints are
    OpenRegister-object-managed, so there is no pipelinq-owned save hook to
    wire it into. Not deleted (recent deliberate retention decision).
  - Berichtenbox adapter interface + `LogBerichtenboxAdapter` (6 methods) — a
    provider-plugin **seam**: the interface is DI-bound but injected nowhere;
    the live Logius webhook path (`BerichtenboxWebhookController`) already
    verifies signatures via `LogiusConnector::handleWebhookSignature()`, so no
    live path is unprotected. Not deleted (deliberate extension seam).

No schema, seed data, or ADR-031 notification surface is touched.

## Impact

- Affected specs: `customer-portal` (new ADDED requirement REQ-PORTAL-ORIGIN)
- Affected code: `lib/Service/Portal/PortalRequestGuard.php` (+ test)
- Risk: low — enforcement fires only in widget mode with an `X-Portal-Tenant`
  header; first-party portal traffic is untouched. Full unit suite green before
  and after (1581 → 1584).
- Follow-up (pipelinq#401): consume-or-remove the Berichtenbox adapter seam;
  wire or retire `validateWritePath`; wire `isSelfSignupAllowed` when the
  self-signup flow is built.

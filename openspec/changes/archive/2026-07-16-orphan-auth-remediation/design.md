# Design — pipelinq orphan-auth remediation

## Verdict table

Gate-6 (`orphan-auth`) findings on clean `origin/development`, each triaged to
exactly one verdict with file:line evidence. Caller search =
`grep -rnE -- '->method\(' lib/ src/`. `class-injected ≠ method-called`.

| # | Method (file:line) | Callers | Verdict | Evidence / superseder |
|---|---|---|---|---|
| 1 | `PortalTenantService::isWidgetOriginAllowed` :257 | 0 → **1** | **WIRE** | Wired into `PortalRequestGuard::resolveTenant()` (`lib/Service/Portal/PortalRequestGuard.php`), the single tenant-resolution gate all portal controllers call via `PortalApiController::requireSession()`/`requireTenant()`. Enforced in widget mode (`X-Portal-Tenant` header present) — the only cross-origin entry. Closes: any site embedding any tenant's widget could drive authenticated portal actions from a non-allow-listed origin. The `PortalPageController::index()` comment ("origin enforcement is done server-side per request") described enforcement that did not exist. |
| 2 | `PortalTenantService::isSelfSignupAllowed` :286 | 0 | **LEAVE (orphaned)** | Guards a self-signup account-creation action. No portal controller creates a `portalAccount` (verified: `grep 'portalAccount' + create/signup/enroll` → none; `PortalAuthController` only *updates* existing accounts for MFA). Reads the real `selfSignupAllowed` schema field (`register.d/40-portal.json:492`). Nothing to wire until a self-signup endpoint exists. Not deleted — plausible near-term feature on a live schema field. |
| 3 | `ZgwCoexistenceValidator::validateWritePath` :70 | 0 | **LEAVE (unsure/orphaned)** | ZGW+StUF double-write guard. The StUF subsystem (incl. `stufEndpoint`/`stufMessage` schemas) was relocated to procest by archived `2026-06-23-pipelinq-stuf-zkn-removal`, which **deliberately retained** this validator (REQ-ZGW-008): "with the StUF schema gone its `findAll` returns empty and the validator reduces to 'ZGW only' as documented." Class is not DI-registered and ZGW endpoints are OpenRegister-object-managed — no pipelinq-owned save hook to wire it into. Not deleted (contradicts a recent deliberate decision); not force-wired (no clean hook). |
| 4-6 | `BerichtenboxAdapterInterface::{verifyDeliveryWebhook,checkMailbox,isDormant}` :111,120,128 | 0 | **LEAVE (plugin seam)** | Provider-adapter interface. Bound in DI (`Application.php:261` → `LogBerichtenboxAdapter`) but injected into no service/controller (verified: only match outside the Berichtenbox dir is the DI binding). |
| 7-9 | `LogBerichtenboxAdapter::{verifyDeliveryWebhook,checkMailbox,isDormant}` :126,166,197 | 0 | **LEAVE (plugin seam)** | The null/log implementation of the above interface. The live Logius webhook path (`BerichtenboxWebhookController::readReceipt/inboundReply`) verifies signatures via `LogiusConnector::handleWebhookSignature()` directly — the adapter abstraction is unconsumed, but no live webhook path is unprotected. |

## Why the WIRE is correct, and safe

`PortalRequestGuard` (docblock: "the single authentication + tenant-resolution
gate every portal controller calls before touching customer data") resolves the
tenant from server-trusted signals: host/subdomain, or the `X-Portal-Tenant`
header (widget mode). Widget mode is the sole cross-origin entry: an embedded
widget on a tenant's site sends `X-Portal-Tenant: <tenant>` plus a browser
`Origin` header. The tenant configures `widgetAllowedOrigins` expecting only
those origins may embed/drive its widget — an expectation nothing enforced.

Wiring `isWidgetOriginAllowed` at `resolveTenant()` enforces it once for every
portal endpoint (login, data reads, request creation), fail-closed:
`isWidgetOriginAllowed()` returns false when `widgetEmbedAllowed` is off, so a
tenant that never enabled embedding rejects every widget-mode request. Crucially
the check runs **only** when `X-Portal-Tenant` is present, so first-party
host/subdomain traffic (no such header) is completely unaffected — no regression
to the direct portal. `requireTenant()`/`requireSession()` callers already run
inside `guarded()`, which maps the thrown `PortalException(403)` to a safe JSON
body.

## Why not DELETE the seams

The Berichtenbox interface is a deliberate provider-plugin extension point
(future real MijnOverheid adapter implements it); the live path is already
protected via `LogiusConnector`. `validateWritePath` was explicitly kept by a
recent change. Deleting either would contradict deliberate architecture/decision
and risks regressing a returning integration — "verify before deleting." They
are flagged on pipelinq#401 for consume-or-remove rather than force-deleted
here.

## Seed Data

None. No OpenRegister schema, register descriptor, or seed object is added or
modified.

## ADR-031 (notification dialect)

Not applicable. No object notifications are dispatched and no
`lib/Settings/*register*.json` notification block is touched.

## Test strategy

New `PortalRequestGuardTest` with mocked `PortalTenantService`/`IRequest`:
- widget mode + disallowed Origin → `PortalException` status 403 (bad-path
  rejection at the live gate — the load-bearing proof);
- widget mode + allow-listed Origin → resolves to the tenant id;
- first-party mode (no `X-Portal-Tenant`) → `isWidgetOriginAllowed` never
  called, resolves unchanged (regression guard).

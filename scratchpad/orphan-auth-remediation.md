# pipelinq — orphan-auth remediation

Change: `openspec/changes/orphan-auth-remediation`
Worktree: `/home/rubenlinde/wave2-worktrees/pipelinq-orphan-auth` (base `origin/development` @ 2ce560e)

## Baseline (clean origin/development, php:8.3-cli fresh install)
- Unit suite: **1581 tests, 4911 assertions, 0 failures** (3 warnings, 11 skipped — pre-existing)
- gate-6 orphan-auth: **9** methods (3 clusters)

## Verdict table

| # | Method (file:line) | Callers | Verdict |
|---|---|---|---|
| 1 | `PortalTenantService::isWidgetOriginAllowed` :257 | 0→1 | **WIRE** — `PortalRequestGuard::resolveTenant` widget-mode origin gate |
| 2 | `PortalTenantService::isSelfSignupAllowed` :286 | 0 | **LEAVE (orphaned)** — no self-signup endpoint exists |
| 3 | `ZgwCoexistenceValidator::validateWritePath` :70 | 0 | **LEAVE (unsure)** — deliberately retained by 2026-06-23-stuf-zkn-removal (REQ-ZGW-008); no pipelinq save hook |
| 4-6 | `BerichtenboxAdapterInterface::{verifyDeliveryWebhook,checkMailbox,isDormant}` | 0 | **LEAVE (seam)** — DI-bound, unconsumed; live webhook via LogiusConnector |
| 7-9 | `LogBerichtenboxAdapter::{verifyDeliveryWebhook,checkMailbox,isDormant}` | 0 | **LEAVE (seam)** — impl of the above |

## WIRE proof (bad-path rejected on live gate)
`PortalRequestGuardTest`:
- `testWidgetModeRejectsDisallowedOrigin` → `PortalException` status 403 (X-Portal-Tenant + disallowed Origin).
- `testWidgetModeAllowsAllowlistedOrigin` → resolves tenant.
- `testFirstPartyModeSkipsOriginCheck` → `isWidgetOriginAllowed` never called (regression guard).
3 tests, 5 assertions, 0 failures.

## After change
- Unit suite: **1584 tests, 4916 assertions, 0 failures**
- gate-6: **8** remaining (all LEAVE-verdict seams/orphaned-capabilities — flagged pipelinq#401)

## FLAG
LIVE unprotected boundary CLOSED: before this, any website could embed any
tenant's portal widget (`X-Portal-Tenant` header) and drive authenticated portal
actions from a non-allow-listed origin — `isWidgetOriginAllowed` existed but was
never called. Now enforced fail-closed at the single tenant-resolution gate.

Remaining 8 are verified NOT unprotected-live-paths (Berichtenbox live path
guarded via LogiusConnector; self-signup action absent; validateWritePath
deliberately retained but unwired). Follow-up: pipelinq#401.

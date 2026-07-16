# Tasks — pipelinq orphan-auth remediation

> Scope: gate-6 (`orphan-auth`) reports 9 orphan methods on clean
> `origin/development`. Triage each to exactly one verdict; wire the one that
> guards a reachable-but-unprotected customer boundary; verify-then-leave the
> seams/orphaned-capabilities. No schema/seed/notification surface touched.

## 1. Triage

- [x] 1.1 Establish clean baseline: full unit suite green on `origin/development` (1581 tests, 0 failures)
- [x] 1.2 Enumerate gate-6 findings with a private scan (shared `/tmp` log is clobbered by parallel sessions) — 9 methods, three clusters
- [x] 1.3 Confirm `class-injected ≠ method-called` for each via `grep -rnE -- '->method\(' lib/ src/`
- [x] 1.4 Record verdict table with file:line evidence in `design.md`

## 2. WIRE — isWidgetOriginAllowed (widget-mode cross-origin boundary)

- [x] 2.1 Identify the single choke point: `PortalRequestGuard::resolveTenant()` (called by every portal endpoint via `PortalApiController`)
- [x] 2.2 Enforce the tenant `widgetAllowedOrigins` allow-list in widget mode (`X-Portal-Tenant` present) with a fail-closed 403; leave first-party (host) mode untouched
- [x] 2.3 Declare the new throw on `resolveTenant()`; confirm `requireTenant`/`requireSession` callers run inside `guarded()` (403 → safe JSON)
- [x] 2.4 Add bad-path test: widget mode + disallowed Origin → `PortalException` 403 at the live gate
- [x] 2.5 Add allow-listed + first-party regression tests
- [x] 2.6 Re-run gate-6 — `isWidgetOriginAllowed` now has a caller (resolved)

## 3. LEAVE (verified) — the other 8

- [x] 3.1 `isSelfSignupAllowed` — confirm no self-signup account-creation endpoint exists → orphaned capability, leave + flag
- [x] 3.2 `validateWritePath` — confirm deliberate retention by `2026-06-23-pipelinq-stuf-zkn-removal` (REQ-ZGW-008) + no pipelinq save hook → leave + flag, do not delete
- [x] 3.3 Berichtenbox interface + `LogBerichtenboxAdapter` (6) — confirm interface DI-bound but unconsumed and live webhook verifies via `LogiusConnector` → plugin seam, leave + flag, do not delete

## 4. Verify

- [x] 4.1 Full unit suite green after change (1584 tests, 0 failures)
- [x] 4.2 Spec delta added: `customer-portal` ADDED REQ-PORTAL-ORIGIN (widget-origin enforcement)
- [x] 4.3 No unauthored deletions; SPDX/i18n unchanged (no new PHP file beyond the test; the Dutch 403 message is user-facing NL, error code is EN)
- [x] 4.4 File findings + follow-ups on pipelinq#401

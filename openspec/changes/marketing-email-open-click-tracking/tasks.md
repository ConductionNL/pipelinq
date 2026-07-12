# Tasks: marketing-email-open-click-tracking

## 1. Tracking token + link service

- [ ] 1.1 Create `lib/Service/TrackingLinkService.php` with sign/verify for open + click tokens (base64url payload `{d,u,iat,exp}` + `hash_hmac('sha256')`, verified with `hash_equals`, per-instance secret `blast.tracking_secret` auto-generated), mirroring `PortalController::signLink()` / `PortalTokenService`
- [ ] 1.2 Add `injectTracking(html, blastDeliveryId)` — rewrite `<a href>` to the click-redirect + append the 1×1 open pixel, leaving `{{unsubscribe_link}}` and the compliance footer untouched
- [ ] 1.3 Add `recordOpen` / `recordClick` delegating to existing semantics (`openedAt` set-once + status `opened`; `AttributionService::recordClick()` for clicks) then `BlastService::updateBlastTotals()`
  - files: `lib/Service/TrackingLinkService.php`
  - Acceptance criteria:
    - Token verification is constant-time and fail-closed; expired tokens rejected
    - No PII in the token payload (only the delivery UUID + optional target URL)

## 2. Public endpoints

- [ ] 2.1 Create `lib/Controller/BlastTrackingController.php` with `open($token)` (`#[PublicPage]`+`#[NoCSRFRequired]`, always returns a 1×1 `image/gif` with `Cache-Control: no-store`) and `click($token)` (verify then 302-redirect to the token's target, 4xx on bad token)
- [ ] 2.2 Register `GET /api/blast/track/open/{token}` and `GET /api/blast/track/click/{token}` in `appinfo/routes.php`
  - files: `lib/Controller/BlastTrackingController.php`, `appinfo/routes.php`
  - Acceptance criteria:
    - Bad/missing token → pixel still returned, nothing recorded; click → 4xx, no redirect
    - Both methods declare `#[PublicPage]` + `#[NoCSRFRequired]` (route-auth gate passes)

## 3. Render hook + feature flag

- [ ] 3.1 Add a `blast.first_party_tracking` admin setting (default off) and wire the toggle through admin settings
- [ ] 3.2 Hook `TrackingLinkService::injectTracking()` into `BlastService::sendOneDelivery()` behind the flag (flag off ⇒ render path byte-for-byte unchanged)
  - files: `lib/Service/BlastService.php`, admin settings (controller/template as existing)
  - Acceptance criteria:
    - Flag off preserves today's provider-webhook-only behaviour exactly
    - Flag on injects tracking without altering the unsubscribe link / physical-address block

## 4. Tests + docs

- [ ] 4.1 Unit-test `TrackingLinkService` (sign/verify round-trip, tampered + expired rejection, injection preserves unsubscribe link, record idempotency) and `BlastTrackingController` (pixel bytes + headers, fail-closed record, click redirect vs 4xx) using municipality/consultancy/travel-agency delivery fixtures with placeholder secret + nil-UUID delivery ids
  - files: `tests/Unit/Service/TrackingLinkServiceTest.php`, `tests/Unit/Controller/BlastTrackingControllerTest.php`
  - Acceptance criteria:
    - `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan)
    - Open/click records are idempotent (first-open/first-click only); `totals` counts each delivery once
- [ ] 4.2 Document the retention posture (per-recipient events live on `blastDelivery`, inherit its retention; no IP/user-agent stored) and the AVG note in `docs/` + the marketing docs

## Acceptance criteria (change-level)

- Open/click tracking works with any provider (base tier) via first-party pixel + redirect, feature-flagged with the provider-webhook path as fallback.
- Tokens are HMAC-signed and PII-free; public endpoints fail closed and the click endpoint is not an open redirector.
- Open/click rates populate the existing marketing-analytics surface via `blast.totals`; no new schema.

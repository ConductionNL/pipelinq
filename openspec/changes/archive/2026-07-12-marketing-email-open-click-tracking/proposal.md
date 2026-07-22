---
kind: code
depends_on: []
---

# Proposal: marketing-email-open-click-tracking

## Why

Pipelinq's marketing blast pipeline records opens and clicks **only** when an
external ESP (SendGrid/SES/Twilio) posts a webhook into `BlastWebhookController` —
so an operator on the base tier, sending through a plain per-tenant openconnector
`send-mail` source without a webhook-capable provider, gets **no open or click
telemetry at all**. First-party open/click tracking is table-stakes #5 (Dolibarr 21
ships tracking pixels; HubSpot, Pipedrive and folk treat it as standard) and it was
the single most-requested "better email on the base tier" item (4 independent
sources). This change gives Pipelinq its own tracking pixel + click-redirect so
open/click rates work regardless of provider, rolled into the existing
marketing-analytics surface.

## What Changes

- **Open pixel route** — a public, unauthenticated `GET /api/blast/track/open/{token}`
  that returns a 1×1 transparent GIF and records the open on the addressed
  `blastDelivery`.
- **Click-redirect route** — a public `GET /api/blast/track/click/{token}` that
  verifies the token, records the click, and 302-redirects to the original target URL.
- **HMAC-signed opaque tokens** — the token encodes only the `blastDelivery` UUID
  (and, for clicks, the target URL), signed with a per-instance app-config secret and
  verified with `hash_equals`, following the existing `PortalController::signLink()` /
  `PortalTokenService` precedent. **No email address or other PII appears in a
  tracking URL.**
- **Render-time injection** — a new `TrackingLinkService` rewrites `<a href>` links
  to the click-redirect and appends the open pixel when first-party tracking is
  enabled, hooked into `BlastService`'s per-delivery render. Gated by a
  `blast.first_party_tracking` admin setting; **off = today's provider-webhook-only
  behaviour** (fallback preserved).
- **Roll-up reuse** — recording an open/click reuses the existing per-delivery fields
  (`openedAt`, `firstClickAt`, `clickedUrls`, `status`) and the existing
  `BlastService::updateBlastTotals()`, so `blast.totals.opened/clicked` and the
  marketing-analytics open-rate/click-rate columns now populate first-party.
- **Compliance/AVG** — the unsubscribe footer and consent gating (marketing-compliance)
  are untouched; tracking events are aggregate + per-recipient telemetry stored on the
  existing delivery rows with a documented retention posture.

## Capabilities

### New Capabilities
- `marketing-email-tracking`: first-party open-pixel + click-redirect tracking with
  HMAC-signed tokens, feeding the existing per-blast analytics roll-up.

### Modified Capabilities
<!-- none at requirement level: marketing-blast-delivery's webhook-sourced open/click requirements still hold and remain the fallback; this adds a first-party source alongside them -->

## Impact

- **Code:** new `lib/Controller/BlastTrackingController.php` (2 public GET methods),
  new `lib/Service/TrackingLinkService.php` (sign/verify/inject/record), route
  registrations in `appinfo/routes.php`, a hook in `lib/Service/BlastService.php`
  (per-delivery render injection), and an admin setting for the feature flag.
- **Data:** writes only into existing `blastDelivery` fields; no new schema.
- **Dependencies:** none new — reuses `hash_hmac`/`hash_equals`, `IAppConfig`,
  OpenRegister `ObjectService`, and `updateBlastTotals()`.
- **Security:** two new public routes; both are `#[PublicPage] #[NoCSRFRequired]` and
  fail closed on a bad/expired token.
- **Feature tier:** V1 (base-tier email quality).

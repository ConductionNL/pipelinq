# Design: marketing-email-open-click-tracking

## Context

The blast pipeline sends through openconnector's per-tenant `send-mail` action
(`BlastService::sendOneDelivery()` → `SourceService::executeAction($sourceId,
'send-mail', $rendered)`); credentials never live in pipelinq (ADR-005). Open/click
telemetry today arrives **only** as inbound provider webhooks into
`BlastWebhookController` (`#[PublicPage]`+`#[NoCSRFRequired]`, HMAC-verified with
`hash_hmac`/`hash_equals` against `blast.webhook_secret.<provider>`), handled by
`WebhookProcessorService` which writes `blastDelivery.openedAt` / `firstClickAt` /
`clickedUrls` / `status` and bumps `blast.totals` via
`BlastService::updateBlastTotals()`. `AttributionService::recordClick()` owns the
click-record semantics (set `firstClickAt` once, dedupe `clickedUrls`, extract
`utm_campaign`). There is no pipelinq-hosted pixel or click-redirect, no event
schema, and no `x-openregister-aggregations` — `totals` is computed imperatively.

## Goals / Non-Goals

**Goals:**
- First-party open + click tracking that works with any provider (base tier).
- Reuse the existing per-delivery fields and roll-up; no new schema.
- Opaque HMAC tokens, no PII in URLs; fail-closed public endpoints.
- Feature-flagged, with today's provider-webhook path as the untouched fallback.

**Non-Goals:**
- No new event/telemetry schema, no per-open/per-click counters (first-open + first-click
  semantics match the existing fields and webhook behaviour).
- No change to openconnector, no change to compliance/consent gating.
- No user-agent / IP capture (privacy-minimising by default).

## Decisions

### Two public GET endpoints on a new `BlastTrackingController`

Mirrors `BlastWebhookController`'s public shape (`#[PublicPage]`, `#[NoCSRFRequired]`):

- `open(string $token)` → `DataDownloadResponse` (or a raw `Response` with
  `Content-Type: image/gif`, `Cache-Control: no-store`) returning the 43-byte
  transparent GIF. Records the open when the token verifies; **always** returns the
  pixel so the email renders even on a bad token (fail-closed on the *record*, not the
  response).
- `click(string $token)` → `RedirectResponse` to the token's decoded target URL after
  the signature verifies; a `4xx` `JSONResponse`/`RedirectResponse`-to-error when it
  does not. The redirect target is trusted **only** after `hash_equals` passes, so the
  endpoint is not an open redirector.

Routes added to `appinfo/routes.php`:
`GET /api/blast/track/open/{token}` → `blastTracking#open`,
`GET /api/blast/track/click/{token}` → `blastTracking#click`.

### `TrackingLinkService` (sign / verify / inject / record)

- **Token shape** (following `PortalController::signLink()`): a base64url of
  `{ d: <blastDeliveryUuid>, u: <targetUrl|null>, iat, exp }`, joined to
  `hash_hmac('sha256', <encoded>, <secret>)` as `"<payload>.<signature>"`. Secret is
  a per-instance random value in app-config key `blast.tracking_secret`
  (generated on first use, like other per-instance HMAC secrets). Verification
  recomputes the HMAC and compares with `hash_equals`, then checks `exp`.
- **`injectTracking(string $html, string $blastDeliveryId): string`** — when the flag
  is on: rewrite each `<a href="URL">` to
  `/api/blast/track/click/<clickToken(deliveryId, URL)>`, and append
  `<img src="/api/blast/track/open/<openToken(deliveryId)>" width="1" height="1" …>`
  before `</body>`. The `{{unsubscribe_link}}` token and the physical-address block
  are excluded from rewriting so marketing-compliance stays satisfied.
- **`recordOpen` / `recordClick`** — delegate to the existing semantics:
  `recordOpen` sets `openedAt` (if unset) + status `opened`; `recordClick` calls
  `AttributionService::recordClick()`. Both then call
  `BlastService::updateBlastTotals()` so the roll-up matches the webhook path exactly
  (idempotent: first-open/first-click only, `totals` counts rows by status).

### Hook point + feature flag

`BlastService::sendOneDelivery()` renders the template, then — if
`blast.first_party_tracking` (admin setting, default off) is enabled — passes the
rendered HTML + the delivery UUID through `TrackingLinkService::injectTracking()`
before handing it to openconnector. Flag off ⇒ the render path is byte-for-byte
today's behaviour.

### Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Open pixel + click redirect endpoints | **Imperative (PHP controller)** | Public HTTP endpoints returning binary/redirect responses — no `x-openregister-*` extension emits routes or a pixel. This is external-surface glue, exactly what ADR-003/ADR-031 keep in PHP. |
| Token signing/verification, link rewriting | **Imperative (service)** | Cryptographic token handling + HTML rewriting; no declarative analogue. Reuses the app's existing HMAC precedent. |
| Per-blast open/click roll-up | **Imperative (existing `updateBlastTotals`)** | The roll-up is already imperative in `BlastService`; this change **reuses** it rather than adding a parallel path. A future `x-openregister-aggregations` migration of `totals` is out of scope and noted below. |

No new schema and no new `x-openregister-*` — the change writes into existing
`blastDelivery` fields.

## Seed Data

No new schema is introduced, so no `_registers.json` schema rows are added. The
marketing register (`95-marketing-segmentation-blast.json`) already seeds two Q4
"Gemeente Outreach" A/B blasts plus `blastDelivery` rows carrying `openedAt` /
`firstClickAt` / `clickedUrls`; those existing rows exercise the analytics roll-up.
For test fixtures spanning the standard archetypes, drive `TrackingLinkService`
against representative deliveries:

- **Municipality** — a `blastDelivery` for a `gemeente` recipient; verify a valid open
  token sets `openedAt` once and a click token records + redirects.
- **Consultancy** — a delivery whose click token carries a `utm_campaign` URL; verify
  `utm_campaign` is preserved into attribution.
- **Travel agency** — a delivery hit with a **tampered** token; verify fail-closed (pixel
  returned, nothing recorded).

Example tokens/secrets in docs use obvious placeholders — secret `YOUR_TRACKING_SECRET_HERE`,
delivery id nil UUID `00000000-0000-0000-0000-000000000000`. No realistic-looking
`sg-…`/`whsec_…` values are added.

## Risks / Trade-offs

- **Open redirector risk on the click endpoint** → The redirect target is embedded in
  the signed token and used **only after** `hash_equals` verifies; an unsigned/tampered
  target is rejected with a 4xx. Covered by a scenario.
- **Pixel abuse / token replay** → Tokens carry an `exp`; opens/clicks are idempotent
  (first-open/first-click only), so replay cannot inflate `totals`. The pixel always
  returns 200 to avoid leaking token validity.
- **AVG / privacy** → No PII in URLs; only the opaque delivery id is bound. Events live
  on the existing `blastDelivery` rows and inherit their retention; no IP/user-agent is
  stored. Consent/unsubscribe gating (marketing-compliance) is unchanged — tracking is
  passive telemetry on already-consented sends.
- **Double counting vs provider webhooks** → With both first-party and a webhook-capable
  provider, `openedAt`/`firstClickAt` are set-once and `totals` counts rows by status, so
  the two sources converge rather than double-count.

## Migration Plan

Additive. New routes + service + off-by-default flag; no data migration. Enabling the
flag changes only newly-sent blasts. Rollback = disable the flag (instant) or revert
the controller/service/routes.

## Open Questions

- Should the click endpoint strip/observe `utm_*` from the target, or pass the URL
  through verbatim? (Provisional: pass verbatim; `AttributionService` already extracts
  `utm_campaign`.)
- Should `blast.totals` migrate to `x-openregister-aggregations` as a follow-up so the
  roll-up is declarative? (Provisional: out of scope; file a follow-up.)
- Token TTL default — tie to the blast's send window or a fixed long TTL (e.g. 90 days)?
  (Provisional: 90-day fixed TTL, admin-overridable.)

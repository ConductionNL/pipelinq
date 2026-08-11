---
status: done
---

# Spec: Marketing Email Open/Click Tracking

**OpenSpec changes**: [marketing-email-open-click-tracking](../../changes/archive/2026-07-12-marketing-email-open-click-tracking/) _(archived 2026-07-12)_

## Purpose

Defines first-party open and click tracking for marketing blasts, so open/click rates
work on the base tier regardless of ESP webhook support. A public open-pixel route and
a public click-redirect route (`#[PublicPage]` + `#[NoCSRFRequired]`) record telemetry
into the existing `blastDelivery` fields (`openedAt`, `firstClickAt`, `clickedUrls`,
`status`) and the existing `BlastService::updateBlastTotals()` roll-up, so the
marketing-analytics open-rate/click-rate metrics populate without a new event schema.
Tracking tokens are opaque, HMAC-signed (per-instance secret, `hash_equals` verify) and
contain no PII; render-time link/pixel injection is feature-flagged with today's
provider-webhook path as the untouched fallback. Consent/unsubscribe gating
(marketing-compliance) is unchanged.

**Standards**: AVG (data minimisation, retention); HMAC-SHA256 signed tokens
**Primary feature tier**: V1
**Related specs**: `marketing-blast`, `marketing-blast-delivery`, `marketing-analytics`, `marketing-compliance`

## Requirements

### Requirement: First-party open tracking pixel

Pipelinq SHALL expose a public, unauthenticated `GET /api/blast/track/open/{token}`
endpoint (`#[PublicPage]` + `#[NoCSRFRequired]`) that always returns a 1×1
transparent GIF (`image/gif`) with caching disabled, and — when the token is valid —
records an open on the addressed `blastDelivery`. The endpoint SHALL fail closed:
an absent, malformed, or expired token still returns the pixel (so the email renders)
but records nothing.

#### Scenario: Valid pixel hit records the open

@e2e exclude a VALID token is an HMAC signed with a per-instance secret that no browser can mint, and the assertion is about stored `blastDelivery` fields (`openedAt`, `status`) plus a `totals` roll-up that nothing renders per-delivery; asserted by tests/Unit/Controller/BlastTrackingControllerTest.php (testOpenWithValidTokenRecordsAndReturnsPixel) and tests/Unit/Service/TrackingLinkServiceTest.php (testOpenTokenRoundTrip, testRecordOpenSetsOpenedAtAndUpdatesTotals). The fail-closed half of this requirement IS asserted end to end by tests/e2e/spec-coverage/marketing.spec.ts ("a tampered open token still returns the 1x1 pixel and never 500s").
- **WHEN** a recipient's mail client loads the pixel with a valid token for a delivered `blastDelivery`
- **THEN** the endpoint returns a 1×1 GIF and sets `openedAt` (only if unset) and transitions the delivery `status` to `opened`
- **AND** the per-blast `totals.opened` is refreshed via `updateBlastTotals()`

#### Scenario: Repeated opens do not double-count the first open

@e2e exclude idempotency is a comparison of a stored timestamp BEFORE and AFTER a second valid-token hit — the token cannot be minted from a browser and neither the timestamp nor the un-incremented counter is rendered anywhere; asserted by tests/Unit/Service/TrackingLinkServiceTest.php (testRecordOpenIsIdempotent).
- **WHEN** the same pixel is loaded a second time
- **THEN** `openedAt` (first-open timestamp) is unchanged and `totals.opened` still counts the delivery once

#### Scenario: Bad token returns a pixel but records nothing
- **WHEN** the pixel is requested with a missing, tampered, or expired token
- **THEN** the endpoint returns the 1×1 GIF and writes no open (fail closed), never raising a 500

### Requirement: First-party click tracking redirect

Pipelinq SHALL expose a public `GET /api/blast/track/click/{token}` endpoint
(`#[PublicPage]` + `#[NoCSRFRequired]`) that verifies the token, records the click on
the addressed `blastDelivery`, and issues a 302 redirect to the original target URL
carried by the token. On an invalid token the endpoint SHALL NOT redirect to an
attacker-supplied location — it SHALL return a 4xx.

#### Scenario: Valid click records and redirects

@e2e exclude same reason as the valid pixel hit — the target URL is carried INSIDE an HMAC-signed token a browser cannot mint, and the recorded effect (`firstClickAt`, `clickedUrls`, `totals.clicked`, the AttributionService hand-off) is stored state rather than rendered output; asserted by tests/Unit/Controller/BlastTrackingControllerTest.php (testClickWithValidTokenRedirectsAndRecords) and tests/Unit/Service/TrackingLinkServiceTest.php (testClickTokenRoundTrip, testRecordClickDelegatesToAttributionServiceAndUpdatesTotals, testRecordClickNoOpWhenDeliveryMissing). The refusal half IS asserted end to end by tests/e2e/spec-coverage/marketing.spec.ts ("a tampered click token is refused and produces no redirect").
- **WHEN** a recipient clicks a tracked link with a valid token
- **THEN** the endpoint records the click (sets `firstClickAt` if unset, appends the URL to `clickedUrls`, bumps `status` toward `clicked`) and 302-redirects to the decoded target URL
- **AND** `totals.clicked` is refreshed and `utm_campaign` attribution is preserved via the existing `AttributionService::recordClick()` path

#### Scenario: Tampered click token is rejected
- **WHEN** the click token's signature does not verify (or it is expired)
- **THEN** the endpoint returns a 4xx and performs no redirect and no record — the target URL bound in the token is trusted only after the signature verifies

### Requirement: Tracking tokens are HMAC-signed and PII-free

@e2e exclude both scenarios are properties of the TOKEN STRING and of the comparison used to verify it: "the payload encodes no PII" and "verification is constant-time" (`hash_equals`, not `===`). Neither has any rendered form, and a timing property is specifically not observable through a browser. Asserted by tests/Unit/Service/TrackingLinkServiceTest.php (testOpenTokenRoundTrip, testClickTokenRoundTrip, testVerifyTokenRejectsTamperedToken, testVerifyTokenRejectsMalformedToken, testVerifyTokenRejectsExpiredToken).

Tracking tokens SHALL be opaque, encoding only the `blastDelivery` UUID (and, for
clicks, the target URL) plus an issue/expiry timestamp, signed with a per-instance
secret from app-config using `hash_hmac('sha256', …)` and verified with the
constant-time `hash_equals`, mirroring the existing `PortalController::signLink()` /
`PortalTokenService` precedent. A tracking URL SHALL NOT contain the recipient's
email address, name, or any other personal identifier.

#### Scenario: Token carries no PII
- **WHEN** a tracking URL is generated for a recipient
- **THEN** the URL path contains only the opaque signed token — no email, name, or contact id in cleartext

#### Scenario: Signature verification is constant-time
- **WHEN** a token signature is checked
- **THEN** verification uses `hash_equals` against the recomputed HMAC and rejects any mismatch

### Requirement: Render-time injection is feature-flagged with a provider fallback

@e2e exclude injection happens at per-delivery RENDER time, inside the mail body handed to openconnector — which the CI instance does not install (.github/workflows/code-quality.yml pins `additional-apps` to openregister only) — so the rendered email never exists in a browser run, and "unchanged from today" is a diff between two never-displayed strings. Asserted by tests/Unit/Service/TrackingLinkServiceTest.php (testInjectTrackingRewritesLinksAndAppendsPixel, testInjectTrackingLeavesUnsubscribeLinkUntouched, testInjectTrackingNoOpOnEmptyInput) and, across the flag, by tests/Unit/Service/BlastServiceTest.php (testSendOneDeliveryInjectsTrackingWhenFlagOn, testSendOneDeliveryDoesNotInjectTrackingWhenFlagOff).

A `TrackingLinkService` SHALL rewrite `<a href>` links to the click-redirect and
append the open pixel to a blast email at per-delivery render time, only when the
`blast.first_party_tracking` admin setting is enabled. When the flag is off, blast
rendering SHALL behave exactly as today (tracking deferred to the openconnector
source / provider webhooks). Injection SHALL NOT alter the unsubscribe link or the
compliance footer required by marketing-compliance.

#### Scenario: Flag on injects first-party tracking
- **WHEN** `blast.first_party_tracking` is enabled and a delivery is rendered
- **THEN** each outbound link is rewritten to a signed click-redirect and a signed open pixel is appended, while `{{unsubscribe_link}}` and the physical-address block are left intact

#### Scenario: Flag off preserves current behaviour
- **WHEN** `blast.first_party_tracking` is disabled
- **THEN** the rendered email is unchanged from today and open/click telemetry continues to arrive only via provider webhooks

### Requirement: Events roll into the existing analytics surface with a retention posture

Recorded opens and clicks SHALL use the existing `blastDelivery` fields and the
existing `BlastService::updateBlastTotals()` roll-up, so the marketing-analytics
open-rate / click-rate metrics populate without a new event schema. Per-recipient
tracking events SHALL be retained only as long as the parent blast/delivery records,
and the retention posture SHALL be documented.

#### Scenario: Analytics open/click rates populate first-party

@e2e exclude the rendered half of this — the Overview table's open-rate and click-rate columns, derived from `blast.totals` — IS asserted end to end by tests/e2e/spec-coverage/marketing.spec.ts ("the Overview tab lists blasts with delivery rates in sortable columns"). What is left, and what this scenario is actually about, is the PROVENANCE of those totals: that a first-party recorded open reaches the same `updateBlastTotals()` roll-up as a webhook-sourced one. Provenance is not visible in the rendered percentage — both sources produce an identical column — and the first-party path needs a valid HMAC token a browser cannot mint. Asserted by tests/Unit/Service/TrackingLinkServiceTest.php (testRecordOpenSetsOpenedAtAndUpdatesTotals, testRecordClickDelegatesToAttributionServiceAndUpdatesTotals) and tests/Unit/Service/BlastServiceTest.php (testUpdateBlastTotalsRecountsByStatus, testUpdateBlastTotalsZeroesAllStatusesWhenNoDeliveries).
- **WHEN** first-party tracking has recorded opens and clicks for a blast
- **THEN** the marketing-analytics Overview table's open-rate and click-rate columns reflect them, derived from `blast.totals` exactly as for webhook-sourced events

## Follow-ups

- **`blast.totals` aggregation migration** (ADR-031 declarative-vs-imperative): the
  per-blast totals roll-up (`BlastService::updateBlastTotals()`) remains imperative —
  reused as-is by this change rather than migrated to
  `x-openregister-aggregations`. A future change may migrate it; out of scope here
  per the design.md Open Questions ruling and the orchestrator's ruling for this
  build (noted explicitly as deferred, not implemented).

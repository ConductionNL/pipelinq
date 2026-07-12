---
status: in-progress
---

# Spec: Marketing Email Open/Click Tracking

**OpenSpec changes**: [marketing-email-open-click-tracking](../../changes/marketing-email-open-click-tracking/) _(in-progress)_

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

### Requirement: First-party open + click tracking endpoints

Pipelinq SHALL expose public, unauthenticated `GET /api/blast/track/open/{token}`
(returns a 1×1 GIF, records the open) and `GET /api/blast/track/click/{token}`
(records the click, 302-redirects to the token's target). Both SHALL fail closed on a
bad/expired token; the click endpoint SHALL NOT redirect to an unsigned target.

#### Scenario: Valid pixel hit records the open
- **WHEN** a recipient's mail client loads the pixel with a valid token
- **THEN** the endpoint returns a 1×1 GIF and sets `openedAt` (only if unset) and status `opened`, refreshing `totals.opened`

#### Scenario: Tampered click token is rejected
- **WHEN** the click token's signature does not verify
- **THEN** the endpoint returns a 4xx and performs no redirect and no record

### Requirement: Tracking tokens are HMAC-signed and PII-free

Tokens SHALL encode only the `blastDelivery` UUID (and, for clicks, the target URL) +
an expiry, signed with a per-instance app-config secret via `hash_hmac('sha256', …)`
and verified with `hash_equals`. A tracking URL SHALL contain no personal identifier.

#### Scenario: Token carries no PII
- **WHEN** a tracking URL is generated
- **THEN** its path contains only the opaque signed token — no email, name, or contact id

### Requirement: Feature-flagged injection with provider fallback

Render-time link/pixel injection SHALL be gated by the `blast.first_party_tracking`
admin setting; when off, blast rendering behaves exactly as today (provider webhooks).
Injection SHALL NOT alter the unsubscribe link or compliance footer.

#### Scenario: Flag off preserves current behaviour
- **WHEN** `blast.first_party_tracking` is disabled
- **THEN** the rendered email is unchanged and telemetry arrives only via provider webhooks

The full requirements and scenarios are maintained in the change delta at
[`changes/marketing-email-open-click-tracking/specs/marketing-email-tracking/spec.md`](../../changes/marketing-email-open-click-tracking/specs/marketing-email-tracking/spec.md)
and are folded into this spec on archive.

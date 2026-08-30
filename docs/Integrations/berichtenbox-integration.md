# Burgerportaal MijnOverheid Berichtenbox

The Berichtenbox bridge connects Pipelinq to the Dutch government's MijnOverheid
Berichtenbox via the Logius Berichtenbox-koppelvlak (BBK) 1.7 API. It delivers
zaak status updates to citizens with a DigiD-enabled mailbox, falls back to
email when no mailbox is available or after 5 working days of unread state, and
ingests replies as new Contactmomenten on the parent zaak.

## Compliance posture

| Requirement | How the bridge meets it |
|-------------|-------------------------|
| Wet Modernisering Elektronisch Bestuurlijk Verkeer (WMEBV 2023) | Digital-first channel for every zaak status transition. |
| Wet Digitale Overheid (WDO) | MijnOverheid is the canonical citizen channel since 2024. |
| AVG art. 32 (encryption at rest) | BSN encrypted with AES-256-GCM per tenant key; index lookups via HMAC-SHA256. |
| AVG art. 17 (right to be forgotten) | `BerichtenboxService::cryptoShred()` wipes BSN ciphertext across Message/Reply/Resolution rows while preserving audit history. |
| Archiefwet 1995 + Selectielijst 2020 | Append-only `DeliveryAuditLog` rows with `retentionUntil` per zaak class (tenant-configurable). |
| BBK 1.7 (Logius binding) | `LogiusConnector::validateOutboundPayload()` enforces UUIDv4 message id, ≤200-char subject, XHTML-strict body, ≤25 MB attachments restricted to PDF/PNG/JPG; outbound requests are RSA-SHA256 signed with the tenant PKI-overheid key. |
| PKIoverheid | Tenant key pair configured via the `pki_cert` / `pki_key` app-config keys (or the openregister key-vault). |

## Prerequisites

1. **Register with Logius** for a Berichtenbox client-credentials grant and
   note the `client_id` + `client_secret`. The Logius sandbox lets you
   exercise the BBK 1.7 endpoints without sending anything to real burgers.
2. **PKI-overheid certificate** for the tenant organisation (one cert per
   gemeente). Store the PEM-encoded private key and certificate in
   openregister's key-vault under `pipelinq:pki:<tenant>`.
3. **Logius webhook secret** — Logius generates a shared HMAC secret used to
   sign the read-receipt and inbound-reply webhooks. Store it in app config
   key `logius_webhook_secret`.

## App-config keys

| Key | Default | Purpose |
|-----|---------|---------|
| `tenant_id` | `default` | Drives per-tenant encryption/key derivation. |
| `tenant_display_name` | `Gemeente` | Substituted as `{{gemeente}}` in templates. |
| `logius_token_url` | `https://api.logius.nl/berichtenbox/v1.7/oauth/token` | OAuth token endpoint. |
| `logius_base_url` | `https://api.logius.nl/berichtenbox/v1.7` | Berichtenbox API base. |
| `logius_client_id` | (empty) | OAuth 2.0 client id. |
| `logius_client_secret` | (empty) | OAuth 2.0 client secret. |
| `logius_webhook_secret` | (empty) | HMAC-SHA256 webhook secret. |
| `pki_cert` | (empty) | PEM-encoded PKI-overheid certificate. |
| `pki_key` | (empty) | PEM-encoded PKI-overheid private key. |
| `berichtenbox_fallback_from` | (empty) | From-address for fallback emails. |
| `selectielijst.<zaaktype>` | `10` | Retention years per zaaktype for audit rows. |

## Templates

Templates live in the `berichtenboxTemplate` OR schema. Each template is keyed
by `(zaaktype, status, language)` and contains a Mustache-style subject + body.
Available variables: `{{zaakId}}`, `{{status}}`, `{{gemeente}}`, `{{deadline}}`,
`{{deepLink}}`, `{{messageId}}`. Use `{{var}}` for HTML-escaped substitution and
`{{{var}}}` for raw markup (e.g. for the deep-link anchor element). The
rendered body must parse as XHTML strict; the renderer wraps it in a `<root>`
element and validates via `DOMDocument`.

Seed templates ship in `lib/Settings/register.d/85-berichtenbox-templates.json`
and are imported on first run via the OR `ConfigurationService::importFromApp()`
repair step (see [[reference_or-register-import-via-repair-step]] for why a
post-migration repair step is required — `occ app:enable` runs migrations
before peer-app autoloaders are loaded).

## Endpoints

| Verb | Route | Auth | Purpose |
|------|-------|------|---------|
| POST | `/api/webhook/berichtenbox/read`  | PublicPage + HMAC | Logius read-receipt webhook. |
| POST | `/api/webhook/berichtenbox/reply` | PublicPage + HMAC | Logius inbound-reply webhook. |
| POST | `/api/admin/berichtenbox/message/{id}/retry` | Admin | Manual retry of a failed message. |
| GET  | `/api/admin/berichtenbox/stats` | Admin | Aggregate delivery counters. |

The webhook routes are `#[PublicPage]` because Logius cannot present a
Nextcloud session cookie; authenticity is enforced by HMAC-SHA256 over the
raw request body with constant-time compare via `hash_equals` (ADR-005). The
admin routes are admin-only by NC `SecurityMiddleware` default
([[reference_nc-security-defaults]]).

## Background jobs

| Class | Interval | Spec |
|-------|----------|------|
| `DispatchQueuedMessagesJob` | 5 min | REQ-OUTBOUND-001, REQ-RETRY-012 |
| `FallbackEmailJob` | 24 h | REQ-FALLBACK-004 |

The dispatch job processes up to 100 queued messages per run with exponential
backoff (1m, 5m, 15m, 1h, 4h over 5 retries). The fallback job uses
`DutchHolidayCalendar` to skip weekends, fixed Dutch holidays, variable
Easter-derived holidays (Pasen, Paasmaandag, Hemelvaartsdag, Pinksteren,
Pinkstermaandag, Goede Vrijdag), Koningsdag, kerst, and Bevrijdingsdag in
lustrum years.

## Monitoring

The bridge surfaces these signals through the Nextcloud logger (level INFO
for normal operations, WARNING for retryable failures, ERROR for hard
failures). Operators should ship them to a central log aggregator and build
alerts on the structured `event` field of the `DeliveryAuditLog` rows:

| Concern | Signal |
|---------|--------|
| Delivery success rate | count(`event=sent`) / (count(`event=queued`)) over 1h |
| Failure reasons | `event=failed` rows grouped by `reason` |
| Queue depth | count(BerichtenboxMessage where `deliveryStatus=queued`) |
| Fallback rate | count(`event=fallback`) / count(`event=sent`) |
| Stale-failed | count(BerichtenboxMessage where `deliveryStatus=failed` AND retryCount=5 AND updatedAt < now - 24h) |

Recommended alert rules:

- Delivery failure rate > 5 % in 1h → page on-call.
- Queue depth > 1000 → page on-call.
- Any message in `failed` state with retryCount ≥ 5 for more than 24h →
  ticket the operator for manual intervention.

Prometheus metric names that the next iteration of the bridge will emit
(reserved here so dashboards can be built ahead of time):

- `berichtenbox_messages_dispatched_total{status}` (Counter)
- `berichtenbox_messages_failed_total{reason}` (Counter)
- `berichtenbox_messages_unread_days` (Gauge)
- `berichtenbox_replies_received_total` (Counter)
- `berichtenbox_fallback_emails_sent_total` (Counter)
- `berichtenbox_dispatch_duration_seconds` (Histogram)

## Troubleshooting

**A message is stuck in `failed`** — check `failureReason`. If the cause is
transient (rate limit, network) call `POST /api/admin/berichtenbox/message/
{id}/retry` to re-queue; the retryCount is reset, so it gets the full backoff
schedule again.

**A burger says "I never got the email" but `deliveryStatus = sent`** — the
message landed in MijnOverheid; the 5-day fallback will email them
automatically if they don't open it in time. If they don't have a known email
on file, escalate to a manual contact.

**Logius returns 401** — token endpoint URL or client credentials are wrong;
verify `logius_token_url`, `logius_client_id`, `logius_client_secret` in
app config.

**Webhook returns 422 "Invalid webhook signature"** — `logius_webhook_secret`
in app config does not match the Logius-configured shared secret. Rotate
both in lockstep.

**No mailbox + no email** — the message remains in `queued` (the bridge logs
a WARNING with `messageId`). Operators must either capture the burger's
email and re-queue, or contact them via post / telephone.

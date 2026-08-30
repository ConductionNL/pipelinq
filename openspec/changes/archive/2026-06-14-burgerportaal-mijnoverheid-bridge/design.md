# Burgerportaal MijnOverheid Bridge — Design

## Overview

This change implements a two-way bridge between pipelinq Contactmomenten/Zaakstatus and the Dutch government's MijnOverheid Berichtenbox, ensuring all citizen communication complies with WMEBV 2023, Logius BBK 1.7, and archiefwet retention requirements.

The bridge operates in four layers:

1. **Dispatch layer** — Watches zaak/klacht status transitions; creates and queues `BerichtenboxMessage` objects
2. **Resolution layer** — Resolves BSN → mailbox availability with TTL caching
3. **Delivery layer** — Sends messages via Logius API with fallback to email after 5 working days
4. **Inbound layer** — Receives replies via webhook; creates new Contactmomenten and routes to ambtenaren

## Architecture

### Backend Services

#### BerichtenboxService (`lib/Service/BerichtenboxService.php`)

Core business logic for message lifecycle management.

**Methods:**

- `queueOutboundMessage(string $zaakId, string $contactmomentId, string $status, string $templateId): BerichtenboxMessage` — Creates a queued message from a zaak/contactmoment and status transition. Resolves BSN from the linked Burger record. Returns the created `BerichtenboxMessage` object.

- `resolveMailboxAvailable(string $bsn): MailboxResolution` — Checks the cache; if expired, calls `MailboxResolver::lookupViaLogius()`, stores the result with `expiresAt = now + 24h`, and returns the resolution. Handles opt-out flag.

- `dispatchQueuedMessage(BerichtenboxMessage $msg): void` — Resolves mailbox; if available, calls `LogiusConnector::sendMessage()` and sets `sentToBerichtenboxAt` and `logiusMessageId`. If not available and burger has email, calls `EmailFallbackSender::send()` and sets `deliveryStatus: "fallback-emailed"`. On any error, logs to `DeliveryAuditLog` and sets `failureReason`.

- `handleReadReceipt(string $logiusMessageId, string $readAt): void` — Finds the `BerichtenboxMessage` by `logiusMessageId`, sets `readAt`, transitions `deliveryStatus: "read"`, and logs to audit.

- `processFallbackQueue(): void` — Runs daily: finds all `BerichtenboxMessage` objects with `deliveryStatus: "sent"` and `sentToBerichtenboxAt` ≥ 5 working days ago (respecting Dutch holidays), no `readAt` set. For each, sends fallback email and updates audit log.

- `handleInboundReply(string $parentMessageId, string $bodyText, File[] $attachments): Contactmoment` — Creates a `BerichtenboxReply`, a new `Contactmoment` on the parent message's zaak with `kanaal: "berichtenbox"`, stores attachments as openregister files, routes to the original ambtenaar via `skill-routing`, and sets `processedAt`.

#### MailboxResolver (`lib/Service/MailboxResolver.php`)

Encapsulates Logius mailbox lookup.

**Methods:**

- `lookupViaLogius(string $bsn): bool` — Calls `LogiusConnector::checkMailboxExists()` and returns whether the mailbox is available. Handles opted-out status separately.

#### LogiusConnector (`lib/Service/LogiusConnector.php`)

Wraps the Logius Berichtenbox-koppelvlak API.

**Methods:**

- `authenticate(): string` — Obtains OAuth 2.0 client-credentials token from Logius's token endpoint using the tenant's registered client credentials (stored in openregister key-vault).

- `sendMessage(BerichtenboxMessage $msg): LogiusResponse` — Constructs a BBK 1.7-compliant REST JSON payload (UUIDv4 message-id, subject ≤200 chars, XHTML strict body, ≤25MB attachments, PDF/PNG/JPG MIME), signs the request with the tenant's PKI-overheid certificate, posts to Logius, and returns the response with `logiusMessageId`.

- `checkMailboxExists(string $bsn): bool` — Calls Logius's "check mailbox" endpoint with the BSN; returns true/false for mailbox availability.

#### EmailFallbackSender (`lib/Service/EmailFallbackSender.php`)

Sends emails as fallback via openconnector SMTP.

**Methods:**

- `send(BerichtenboxMessage $msg, string $toEmail): void` — Renders the same message template (with a prepended fallback notice), sends via openconnector's email source, and updates the message with `fallbackEmail` and `fallbackSentAt`.

#### DeliveryAuditLogger (`lib/Service/DeliveryAuditLogger.php`)

Append-only logging for compliance.

**Methods:**

- `logEvent(string $messageId, string $event, string $actor, string $payloadHash): void` — Creates a `DeliveryAuditLog` entry with event type, timestamp, actor (system or ambtenaar-id), payload SHA-256 hash, and `retentionUntil` calculated from the zaak's selectielijst retention class.

#### DutchHolidayCalendar (`lib/Service/DutchHolidayCalendar.php`)

Calculates 5 working days excluding official Dutch holidays.

**Methods:**

- `addWorkingDays(DateTime $from, int $days): DateTime` — Returns the date that is `$days` working days after `$from`, skipping weekends (Sat/Sun) and official Dutch holidays (Koningsdag, Bevrijdingsdag in lustrum years, kerst, etc.). Uses a maintained list of holidays.

### Controllers

#### BerichtenboxWebhookController (`lib/Controller/BerichtenboxWebhookController.php`)

Receives Logius webhooks.

**Routes:**

| Method | Route | Description |
|--------|-------|-------------|
| `readReceipt(Request $request)` | `POST /api/webhook/berichtenbox/read` | Webhook: message read in MijnOverheid |
| `inboundReply(Request $request)` | `POST /api/webhook/berichtenbox/reply` | Webhook: burger reply received |

**DI:** `BerichtenboxService`, `DeliveryAuditLogger`

### Background Jobs

#### DispatchQueuedMessagesJob (`lib/BackgroundJob/DispatchQueuedMessagesJob.php`)

Runs every 5 minutes. Finds all `BerichtenboxMessage` objects with `deliveryStatus: "queued"`, calls `BerichtenboxService::dispatchQueuedMessage()` for each, with exponential backoff and retry limits.

#### FallbackEmailJob (`lib/BackgroundJob/FallbackEmailJob.php`)

Runs daily at 06:00 UTC. Calls `BerichtenboxService::processFallbackQueue()`.

### Data Model

**New entities** (all use OpenRegister built-in fields: id, uuid, uri, version, createdAt, updatedAt, owner, organization, register, schema, relations, files, auditTrail, notes, tasks, tags, status, locked):

#### BerichtenboxMessage

Tracks outbound messages.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| bsn | string (encrypted) | Yes | Burger's BSN (AES-256-GCM encrypted at rest; keyed-hash for index lookup) |
| bsnHash | string | Yes | HMAC-SHA256 of BSN for index queries (never plaintext) |
| mailboxResolvedAt | datetime | No | When mailbox lookup was performed |
| mailboxAvailable | boolean | No | Whether burger has an active MijnOverheid mailbox |
| contactmomentId | uuid | No | UUID reference to the linked Contactmoment |
| zaakId | string | No | External zaak ID (e.g., "Z-2026-0042") |
| subject | string | Yes | Message subject (≤200 chars per BBK 1.7) |
| body | string | Yes | Message body (XHTML strict per BBK 1.7 appendix B) |
| templateId | string | Yes | Reference to the BerichtenboxTemplate used |
| attachments | array | No | Array of {filename, mime (PDF/PNG/JPG), sizeBytes, openregisterFileRef} |
| sentToBerichtenboxAt | datetime | No | When successfully delivered to Berichtenbox |
| logiusMessageId | uuid | No | Logius-assigned message ID for the delivered message |
| deliveryStatus | enum | Yes | queued \| sent \| read \| fallback-emailed \| failed \| opted-out |
| readAt | datetime | No | When burger opened message in MijnOverheid |
| fallbackTriggeredAt | datetime | No | When 5-day fallback was triggered |
| fallbackEmail | string | No | Email address to which fallback was sent |
| fallbackSentAt | datetime | No | When fallback email was sent |
| failureReason | string | No | Error message if deliveryStatus is "failed" |

#### BerichtenboxReply

Tracks inbound replies.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| parentMessageId | uuid | Yes | UUID of the BerichtenboxMessage being replied to |
| logiusReplyId | string | Yes | Logius-assigned reply ID |
| receivedAt | datetime | Yes | When the reply was received from Logius |
| bsn | string (encrypted) | Yes | Burger's BSN (same encryption as Message) |
| bsnHash | string | Yes | HMAC-SHA256 of BSN |
| bodyText | string | Yes | Plain-text reply content |
| attachments | array | No | Array of {filename, mime, sizeBytes, openregisterFileRef} |
| processedAt | datetime | No | When the reply was processed (Contactmoment created, routed) |
| createdContactmomentId | uuid | No | UUID of the created Contactmoment |
| processingError | string | No | Error message if processing failed |

#### BerichtenboxTemplate

Per-zaaktype-per-status message templates with Mustache variable support.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| zaaktype | string | Yes | CIMS code or local zaaktype identifier |
| status | enum | Yes | received \| in-behandeling \| meer-info-nodig \| afgehandeld \| afgewezen |
| language | enum | Yes | nl \| fy \| en |
| subject | string | Yes | Mustache template for subject (variables: {{zaakId}}, {{status}}, {{gemeente}}) |
| body | string | Yes | Mustache template for body in XHTML strict (variables: same as subject, plus {{deadline}}, {{deepLink}}) |
| requiresDeepLink | boolean | No | Whether to include a link back to the gemeente's burgerportaal |
| deepLinkBase | string | No | Base URL for deep-link construction (e.g., "https://gemeente.nl/mijn-zaken?zaak=") |

#### MailboxResolution

Cache of BSN → mailbox-available lookups with Logius TTL.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| bsn | string (encrypted) | Yes | Burger's BSN |
| bsnHash | string | Yes | HMAC-SHA256 of BSN |
| mailboxAvailable | boolean | Yes | Whether the mailbox exists |
| resolvedAt | datetime | Yes | When this lookup was performed |
| expiresAt | datetime | Yes | Cache TTL per Logius (typically now + 24h) |
| optedOut | boolean | No | Whether burger has opted out of Berichtenbox |

#### DeliveryAuditLog

Append-only, archiefwet-retained audit trail (never updated, never deleted by application code).

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| messageId | uuid | Yes | UUID of BerichtenboxMessage or BerichtenboxReply |
| event | enum | Yes | queued \| sent \| read \| fallback \| failed \| opted-out \| reply-received \| processing-error |
| eventAt | datetime | Yes | When the event occurred |
| actor | string | Yes | system \| ambtenaar-id (who triggered the event) |
| payloadHash | string | Yes | SHA-256 hash of the message body for integrity verification |
| retentionUntil | datetime | Yes | Archiefwet retention end date per zaak selectielijst (5/10/20 years) |

### Seed Data

Example BerichtenboxMessages, BerichtenboxTemplates, and related data for development:

```json
{
  "berichtenboxTemplates": [
    {
      "zaaktype": "paspoortaanvraag",
      "status": "afgehandeld",
      "language": "nl",
      "subject": "Uw paspoort is gereed — zaak {{zaakId}}",
      "body": "<html><body><p>Geachte burger,</p><p>Uw paspoortaanvraag (zaak {{zaakId}}) is afgehandeld. U kunt Uw paspoort ophalen bij het gemeentehuis.</p><p><a href=\"{{deepLink}}\">Bekijk de status in uw MijnOverheid Berichtenbox</a></p></body></html>",
      "requiresDeepLink": true,
      "deepLinkBase": "https://burgerportaal.gemeente.nl/zaak?id="
    },
    {
      "zaaktype": "avg-inzageverzoek",
      "status": "meer-info-nodig",
      "language": "nl",
      "subject": "Uw AVG-inzageverzoek: aanvullende informatie nodig",
      "body": "<html><body><p>Geachte burger,</p><p>Voor Uw inzageverzoek (zaak {{zaakId}}) hebben wij aanvullende informatie nodig.</p><p><strong>Wettelijke termijn:</strong> {{deadline}}</p><p><a href=\"{{deepLink}}\">Upload documenten in Uw burgerportaal</a></p><p><strong>Grondslag:</strong> AVG Art. 15</p></body></html>",
      "requiresDeepLink": true,
      "deepLinkBase": "https://burgerportaal.gemeente.nl/zaak?id="
    }
  ],
  "berichtenboxMessages": [
    {
      "bsn": "ENCRYPTED[123456789]",
      "bsnHash": "sha256:...",
      "mailboxResolvedAt": "2026-05-20T10:30:00Z",
      "mailboxAvailable": true,
      "zaakId": "Z-2026-0042",
      "contactmomentId": "uuid:...",
      "subject": "Uw paspoort is gereed",
      "body": "<html><body>...(XHTML content)...</body></html>",
      "templateId": "uuid:paspoort-afgehandeld-nl",
      "attachments": [{"filename": "besluit.pdf", "mime": "application/pdf", "sizeBytes": 245600, "openregisterFileRef": "uuid:..."}],
      "sentToBerichtenboxAt": "2026-05-20T10:35:00Z",
      "logiusMessageId": "uuid:bbk-msg-12345",
      "deliveryStatus": "sent",
      "readAt": null,
      "fallbackTriggeredAt": null
    },
    {
      "bsn": "ENCRYPTED[987654321]",
      "bsnHash": "sha256:...",
      "mailboxResolvedAt": "2026-05-20T11:00:00Z",
      "mailboxAvailable": false,
      "zaakId": "Z-2026-0043",
      "subject": "Status klacht afvalinzameling",
      "body": "<html><body>...(XHTML content)...</body></html>",
      "templateId": "uuid:klacht-in-behandeling-nl",
      "deliveryStatus": "fallback-emailed",
      "fallbackEmail": "burger@example.nl",
      "fallbackSentAt": "2026-05-20T11:05:00Z"
    }
  ]
}
```

## File Changes Summary

| File | Action | Purpose |
|------|--------|---------|
| `lib/Service/BerichtenboxService.php` | Create | Core message lifecycle logic |
| `lib/Service/MailboxResolver.php` | Create | Logius mailbox lookup with caching |
| `lib/Service/LogiusConnector.php` | Create | BBK 1.7 API wrapper with OAuth 2.0 and PKI-overheid signing |
| `lib/Service/EmailFallbackSender.php` | Create | Email fallback via openconnector |
| `lib/Service/DeliveryAuditLogger.php` | Create | Append-only audit logging |
| `lib/Service/DutchHolidayCalendar.php` | Create | 5-day working-day calculation |
| `lib/Controller/BerichtenboxWebhookController.php` | Create | Logius webhook handlers (read-receipt, inbound-reply) |
| `lib/BackgroundJob/DispatchQueuedMessagesJob.php` | Create | 5-minute dispatch job |
| `lib/BackgroundJob/FallbackEmailJob.php` | Create | Daily fallback email job |
| `lib/Settings/berichtenbox_register.json` | Create | OpenRegister schema definitions for 5 new entities |
| `lib/Migration/CreateBerichtenboxTables.php` | Create | Database schema setup |
| `appinfo/routes.php` | Edit | Add webhook routes |
| `appinfo/app.php` | Edit | Register background jobs and event listeners |
| `tests/Unit/Service/BerichtenboxServiceTest.php` | Create | Unit tests |
| `tests/Unit/Service/MailboxResolverTest.php` | Create | Unit tests |
| `tests/Unit/Service/LogiusConnectorTest.php` | Create | Unit tests |
| `tests/Unit/Controller/BerichtenboxWebhookControllerTest.php` | Create | Unit tests |
| `tests/Integration/BerichtenboxIntegrationTest.php` | Create | BBK 1.7 conformance tests |

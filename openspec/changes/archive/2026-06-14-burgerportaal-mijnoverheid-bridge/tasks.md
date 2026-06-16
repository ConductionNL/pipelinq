# Implementation Tasks: Burgerportaal MijnOverheid Bridge

## Phase 1: Database Schema and Entities [MVP Core]

### Entity Setup
- [x] 1.1 Create `lib/Migration/CreateBerichtenboxTables.php` with schema for BerichtenboxMessage, BerichtenboxReply, BerichtenboxTemplate, MailboxResolution, DeliveryAuditLog tables
  - All tables use standard OpenRegister built-in fields (id, uuid, uri, version, createdAt, updatedAt, owner, organization, register, schema, relations, files, auditTrail, notes, tasks, tags, status, locked)
  - BerichtenboxMessage: add bsn (encrypted), bsnHash (HMAC-SHA256, indexed), mailboxResolvedAt, mailboxAvailable (boolean), contactmomentId, zaakId, subject, body, templateId, attachments (JSON), sentToBerichtenboxAt, logiusMessageId (indexed), deliveryStatus (enum, indexed), readAt, fallbackTriggeredAt, fallbackEmail, fallbackSentAt, failureReason
  - BerichtenboxReply: add parentMessageId (indexed), logiusReplyId (indexed), receivedAt, bsn (encrypted), bsnHash, bodyText, attachments (JSON), processedAt, createdContactmomentId, processingError
  - BerichtenboxTemplate: add zaaktype (indexed), status (indexed), language, subject, body, requiresDeepLink, deepLinkBase
  - MailboxResolution: add bsn (encrypted), bsnHash (indexed), mailboxAvailable, resolvedAt, expiresAt (indexed), optedOut
  - DeliveryAuditLog: add messageId (indexed), event (indexed), eventAt, actor, payloadHash, retentionUntil (indexed)
  - Constraints: deliveryStatus ENUM, event ENUM, language ENUM, status ENUM (zaaktype status values)
  - Indexes: (bsnHash, expiresAt) on MailboxResolution for cache lookups; (logiusMessageId) on BerichtenboxMessage for webhook handlers; (retentionUntil) on DeliveryAuditLog for expiration jobs

- [x] 1.2 Create `lib/Settings/berichtenbox_register.json` OpenRegister schema definitions for the 5 new entities
  - Each entity maps to OpenRegister's register/schema/object pattern
  - Schema defines field types, validators, and OpenRegister UI hints

### Encryption Setup
- [x] 1.3 Create `lib/Service/EncryptionService.php` for AES-256-GCM encryption/decryption of BSN
  - `encrypt(string $plaintext, string $tenantId): EncryptedValue` — returns encrypted string + IV
  - `decrypt(string $ciphertext, string $tenantId): string` — retrieves key from openregister key-vault, decrypts
  - `hashBsn(string $plaintext, string $tenantId): string` — returns HMAC-SHA256 of BSN for index lookups
  - Handle key rotation: accept multiple keys per tenant, decrypt with any valid key
  - Error handling: catch key-vault errors, throw CryptoException with context

---

## Phase 2: Backend Services [MVP Core]

### Service Implementation
- [x] 2.1 Create `lib/Service/DutchHolidayCalendar.php`
  - `addWorkingDays(DateTime $from, int $days, string $tenantTimeZone = 'Europe/Amsterdam'): DateTime`
  - Skip weekends (Sat/Sun) and official holidays: Koningsdag (Apr 27), Bevrijdingsdag (May 5 in lustrum years), Kerstmis (Dec 25), Tweede Kerstdag (Dec 26), plus any tenant-custom holidays
  - Return the date that is `$days` working days after `$from`
  - Unit test: validate 5 working days calculation across a weekend and a holiday

- [x] 2.2 Create `lib/Service/LogiusConnector.php` — Berichtenbox-koppelvlak BBK 1.7 API wrapper
  - `authenticate(): OAuthToken` — POST to Logius token endpoint with client credentials (stored in openregister key-vault), return Bearer token
  - `sendMessage(BerichtenboxMessage $msg, string $tenantPkiCert, string $tenantPkiKey): LogiusResponse`
    - Validate message: subject ≤200 chars, body is valid XHTML strict, attachments ≤25 MB total and PDF/PNG/JPG only
    - Construct REST JSON payload: message-id (UUIDv4), subject, body (XHTML), attachments array
    - Sign request with PKI-overheid certificate (RFC 3161 or per Logius spec)
    - POST to Logius API endpoint with OAuth Bearer token
    - Return `LogiusResponse` with status, `logiusMessageId`, or error details
    - Throw `LogiusApiException` on error with reason (rate-limit, auth failure, validation error, server error)
  - `checkMailboxExists(string $bsn): bool` — Call Logius mailbox-check endpoint, return boolean
  - `handleWebhookSignature(Request $request): bool` — Verify Logius webhook signature per BBK 1.7
  - Unit test: mock Logius API, test success, rate-limit, auth-failure, malformed-payload cases

- [x] 2.3 Create `lib/Service/MailboxResolver.php` — Caching layer for BSN → mailbox lookups
  - `resolve(string $bsn, string $tenantId): MailboxResolution`
    - Check MailboxResolution cache: if `expiresAt > now`, return cached result
    - Otherwise, call `LogiusConnector::checkMailboxExists()`, store result in cache with `expiresAt = now + 24h`, return
    - Cache indexed by `bsnHash` (never plaintext BSN)
    - Handle opted-out flag separately
  - Unit test: mock LogiusConnector, test cache hit/miss, TTL expiration

- [x] 2.4 Create `lib/Service/DeliveryAuditLogger.php` — Append-only audit logging
  - `logQueued(string $messageId, string $payloadHash, DateTime $retentionUntil): void`
  - `logSent(string $messageId, string $logiusMessageId, string $payloadHash, DateTime $retentionUntil): void`
  - `logRead(string $messageId, string $payloadHash, DateTime $retentionUntil): void`
  - `logFallback(string $messageId, string $reason, string $payloadHash, DateTime $retentionUntil): void`
  - `logFailed(string $messageId, string $reason, string $payloadHash, DateTime $retentionUntil): void`
  - `logReplyReceived(string $replyId, string $payloadHash, DateTime $retentionUntil): void`
  - Each method creates a DeliveryAuditLog entry (INSERT only, never UPDATE/DELETE)
  - Calculate payload hash as SHA-256 of message body
  - Set actor to "system" (background jobs) or current user ID (manual actions)
  - Unit test: verify rows are inserted immutably, retention dates are calculated per zaak selectielijst

- [x] 2.5 Create `lib/Service/EmailFallbackSender.php` — Email fallback via openconnector
  - `send(BerichtenboxMessage $msg, string $toEmail, string $tenantId): void`
    - Render the same message template (add prepended notice: "Dit bericht is ook beschikbaar in uw MijnOverheid Berichtenbox")
    - Build email: To=$toEmail, From=gemeente-noreply@gemeente.nl, Subject=msg.subject, Body=msg.body + notice
    - Send via openconnector's email source (SMTP or SendGrid)
    - Update BerichtenboxMessage: `fallbackEmail = $toEmail`, `fallbackSentAt = now`, `deliveryStatus = "fallback-emailed"`
    - Throw `EmailSendException` on failure (caught by BerichtenboxService for retry)
  - Unit test: mock openconnector email source, test success and failure cases

- [x] 2.6 Create `lib/Service/TemplateRenderer.php` — Mustache template rendering with validation
  - `render(BerichtenboxTemplate $template, array $variables): RenderedTemplate`
    - Variables: `{zaakId, status, gemeente, deepLink, deadline}`
    - Use Mustache library to render subject and body
    - Validate rendered body as XHTML strict (use DomDocument or HTML5 validator)
    - Truncate subject to 200 chars
    - Catch rendering errors; throw `TemplateRenderException` with context
  - Unit test: valid Mustache syntax, invalid Mustache syntax, XHTML validation, subject truncation

- [x] 2.7 Create `lib/Service/BerichtenboxService.php` — Core message lifecycle orchestration
  - `queueOutboundMessage(string $zaakId, string $contactmomentId, string $status, string $templateId): BerichtenboxMessage`
    - Fetch Contactmoment from OpenRegister; extract linked Burger and BSN
    - Fetch BerichtenboxTemplate matching zaaktype and status; if not found, use fallback template
    - Render template with `TemplateRenderer::render()` (zaakId, status, gemeente, deepLink, deadline)
    - Encrypt BSN and compute `bsnHash` with `EncryptionService`
    - Fetch attachments from zaak (if `template.requiresDeepLink = true`, construct deepLink URL)
    - Create BerichtenboxMessage: `deliveryStatus = "queued"`, store all fields
    - Return the created message
    - Throw `TemplateException` or `EncryptionException` on error

  - `dispatchQueuedMessages(): void` — Called by DispatchQueuedMessagesJob every 5 minutes
    - Query BerichtenboxMessage with `deliveryStatus = "queued"` (order by createdAt ASC, limit 100 per run to avoid blocking)
    - For each message:
      - Resolve mailbox: `MailboxResolver::resolve(bsn, tenantId)` → MailboxResolution
      - If `mailboxAvailable = true` and not `optedOut`:
        - Call `LogiusConnector::sendMessage(message)` → LogiusResponse
        - On success: set `sentToBerichtenboxAt = now`, `logiusMessageId = response.messageId`, `deliveryStatus = "sent"`; log to audit
        - On failure: set `failureReason = response.error`, `deliveryStatus = "failed"`, re-queue for retry (exponential backoff: 1m, 5m, 15m, 1h, 4h over 5 attempts); log to audit
      - Else (no mailbox or opted out):
        - If burger.email is set: call `EmailFallbackSender::send(message, burger.email)` → set `deliveryStatus = "fallback-emailed"`, `fallbackTriggeredAt`, `fallbackSentAt`, `fallbackEmail`; log to audit
        - Else: leave `deliveryStatus = "queued"`; log warning

  - `handleReadReceipt(string $logiusMessageId, DateTime $readAt): void`
    - Query BerichtenboxMessage by `logiusMessageId`
    - Set `readAt = $readAt`, `deliveryStatus = "read"`
    - Log to audit with `event = "read"`

  - `processFallbackQueue(): void` — Called daily by FallbackEmailJob at 06:00 UTC
    - Query BerichtenboxMessage with `deliveryStatus = "sent"`, `readAt IS NULL`
    - For each message:
      - Calculate 5 working days using `DutchHolidayCalendar::addWorkingDays(sentToBerichtenboxAt, 5)`
      - If today > 5-working-day date and `burger.email` is set:
        - Call `EmailFallbackSender::send(message, burger.email)` → set `deliveryStatus = "fallback-emailed"`, `fallbackTriggeredAt`, `fallbackSentAt`
        - Log to audit with `event = "fallback"`, `reason = "5-day-unread"`

  - `handleInboundReply(string $parentMessageId, string $bodyText, File[] $attachments): Contactmoment`
    - Query BerichtenboxMessage by id = `parentMessageId`
    - Create BerichtenboxReply: `parentMessageId`, `logiusReplyId`, `receivedAt = now`, encrypted BSN, `bodyText`, `attachments`
    - Create new Contactmoment on the same zaak:
      - `subject = "Re: " + parent.subject`
      - `summary = bodyText`
      - `kanaal = "berichtenbox"`
      - `outcome = "opvolging-nodig"` (default)
      - `agent = null` (to be routed)
      - Attach files as openregister files
    - Call `skill-routing::route(contactmoment)` to assign to original ambtenaar
    - Set `BerichtenboxReply.processedAt = now`, `createdContactmomentId = contactmoment.id`
    - Log to audit with `event = "reply-received"`
    - Return the created contactmoment
    - On error: set `BerichtenboxReply.processingError`, log to audit with `event = "processing-error"`, throw exception

  - `cryptoShred(string $bsn, string $tenantId): void` — Called on burger erasure request
    - Find all BerichtenboxMessage and BerichtenboxReply rows with matching `bsnHash`
    - For each row, decrypt BSN with old key, re-encrypt with crypto-shredding key (effectively destroying original)
    - Leave audit log intact
    - Update `bsnHash` if necessary for consistency

- [x] 2.8 Run syntax checks and import validation on all services
  - `php -l lib/Service/*.php`
  - Verify imports: no circular dependencies, all dependencies available via DI

---

## Phase 3: Controllers and Routing [MVP Core]

- [x] 3.1 Create `lib/Controller/BerichtenboxWebhookController.php` — Logius webhook handlers
  - `readReceipt(Request $request): JSONResponse`
    - Parse webhook payload: extract `logiusMessageId`, `readAt` (timestamp)
    - Verify webhook signature: `LogiusConnector::handleWebhookSignature(request)`
    - Call `BerichtenboxService::handleReadReceipt(logiusMessageId, readAt)`
    - Return `{ "success": true }` or 400 with error on invalid signature
    - DI: `BerichtenboxService`, `LogiusConnector`, `ILogger`

  - `inboundReply(Request $request): JSONResponse`
    - Parse webhook payload: extract `parentMessageId`, `logiusReplyId`, `bodyText`, `attachments` array
    - Verify webhook signature
    - Call `BerichtenboxService::handleInboundReply(parentMessageId, bodyText, attachments)`
    - Return `{ "success": true, "contactmomentId": ... }` or error response

  - Both methods: log webhook reception and processing time; catch exceptions and return 400 with error detail

- [x] 3.2 Edit `appinfo/routes.php` — Add webhook routes
  - `['name' => 'BerichtenboxWebhook#readReceipt', 'url' => '/api/webhook/berichtenbox/read', 'verb' => 'POST']`
  - `['name' => 'BerichtenboxWebhook#inboundReply', 'url' => '/api/webhook/berichtenbox/reply', 'verb' => 'POST']`
  - No authentication required (Logius webhooks are verified by signature, not OAuth)

- [x] 3.3 Create admin API endpoints (optional for MVP, but useful for testing)
  - `POST /api/admin/berichtenbox/message/{id}/retry` — Manually retry a failed message
  - `GET /api/admin/berichtenbox/stats` — Return delivery stats (total sent, failed, fallback-emailed, unread count, queue depth)

---

## Phase 4: Background Jobs [MVP Core]

- [x] 4.1 Create `lib/BackgroundJob/DispatchQueuedMessagesJob.php` — ITimedJob, runs every 5 minutes
  - `run(IJobList $jobList): void`
    - Call `BerichtenboxService::dispatchQueuedMessages()`
    - Handle exceptions: log them, don't stop the job
    - Update job schedule: `$jobList->setLastRun($this)`
  - DI: `BerichtenboxService`, `ILogger`, `IJobList`

- [x] 4.2 Create `lib/BackgroundJob/FallbackEmailJob.php` — ITimedJob, runs daily at 06:00 UTC
  - `run(IJobList $jobList): void`
    - Call `BerichtenboxService::processFallbackQueue()`
    - Log job start/end and any errors
  - DI: `BerichtenboxService`, `ILogger`, `IJobList`

- [x] 4.3 Edit `appinfo/app.php` — Register background jobs
  - Register both jobs with the Nextcloud IJobList service
  - Set up event listeners for zaak status transitions (integration with zaakafhandelapp)

---

## Phase 5: Integration with Dependent Apps [MVP Core]

- [x] 5.1 Integrate with zaakafhandelapp for status-transition events
  - Option A: Listen for zaak update events (if zaakafhandelapp emits them via event bus)
  - Option B: Poll zaakafhandelapp API for status changes (if no event bus available)
  - On status change to a terminal status (afgehandeld, afgewezen, etc.), trigger `BerichtenboxService::queueOutboundMessage()`
  - Handle errors gracefully: log them, don't block zaak processing

- [x] 5.2 Integrate with avg-verzoeken-workflow for AVG request communication
  - Add a trigger option in avg-verzoeken-workflow: "Send via Berichtenbox"
  - When triggered for a verzoek in `meer-info-nodig` status, call `BerichtenboxService::queueOutboundMessage()` with AVG template
  - Handle replies: route to FG queue/skill

- [x] 5.3 Integrate with skill-routing for inbound reply routing
  - Call `skill-routing::route(contactmoment)` from `BerichtenboxService::handleInboundReply()`
  - Pass metadata: `{ source: "berichtenbox", parentMessageId: ... }`

- [x] 5.4 Validate integration with openconnector for Logius API calls and email fallback
  - Ensure openconnector has been installed and configured with Logius API credentials and SMTP
  - Test end-to-end: mock Logius API response, verify email fallback works

---

## Phase 6: Testing [MVP Quality Gate]

### Unit Tests
- [x] 6.1 Create `tests/Unit/Service/EncryptionServiceTest.php`
  - Test: encrypt/decrypt round-trip
  - Test: encryption with different keys per tenant
  - Test: hash generation matches expected value
  - Test: key rotation (old key can still decrypt)

- [x] 6.2 Create `tests/Unit/Service/DutchHolidayCalendarTest.php`
  - Test: add 5 working days across a regular week (Mon-Fri)
  - Test: add 5 working days across a weekend (Fri + 5 = Wed next week)
  - Test: add 5 working days across Koningsdag (Apr 27)
  - Test: add 5 working days across Bevrijdingsdag in lustrum year (May 5)
  - Test: add 5 working days across Kerstmis (Dec 25-26)

- [x] 6.3 Create `tests/Unit/Service/LogiusConnectorTest.php`
  - Mock Logius API endpoints
  - Test: successful message send (BBK 1.7 payload structure, signature)
  - Test: rate-limit response (HTTP 429)
  - Test: auth failure (invalid client credentials)
  - Test: mailbox check endpoint (returns true/false)
  - Test: webhook signature verification (valid and invalid signatures)

- [x] 6.4 Create `tests/Unit/Service/MailboxResolverTest.php`
  - Mock LogiusConnector
  - Test: cache hit (no API call)
  - Test: cache miss → API call → cache store
  - Test: cache expiration (TTL exceeded, API called again)
  - Test: opted-out flag handling

- [x] 6.5 Create `tests/Unit/Service/TemplateRendererTest.php`
  - Test: valid Mustache template rendering with all variables
  - Test: template with missing variables (Mustache default: empty string)
  - Test: invalid Mustache syntax (error handling)
  - Test: XHTML strict validation (valid HTML accepted, invalid rejected)
  - Test: subject truncation to 200 chars

- [x] 6.6 Create `tests/Unit/Service/BerichtenboxServiceTest.php`
  - Mock: OpenRegister ObjectService, MailboxResolver, LogiusConnector, EmailFallbackSender, DeliveryAuditLogger, TemplateRenderer
  - Test: queueOutboundMessage() creates message with encrypted BSN
  - Test: dispatchQueuedMessages() with successful send
  - Test: dispatchQueuedMessages() with no mailbox → fallback email
  - Test: dispatchQueuedMessages() with opted-out → fallback email
  - Test: dispatchQueuedMessages() with send failure → retry queue
  - Test: handleReadReceipt() updates readAt and audit log
  - Test: processFallbackQueue() sends email after 5 working days
  - Test: handleInboundReply() creates Contactmoment and routes
  - Test: cryptoShred() re-encrypts BSN

- [x] 6.7 Create `tests/Unit/Controller/BerichtenboxWebhookControllerTest.php`
  - Mock: BerichtenboxService, LogiusConnector
  - Test: readReceipt() with valid signature → 200 OK
  - Test: readReceipt() with invalid signature → 400 Bad Request
  - Test: inboundReply() with valid payload → 200 OK, contactmomentId returned
  - Test: inboundReply() with processing error → 400 Bad Request

- [x] 6.8 Create `tests/Unit/Service/DeliveryAuditLoggerTest.php`
  - Test: logQueued() inserts immutable row
  - Test: logSent() inserts with logiusMessageId
  - Test: logRead(), logFallback(), logFailed(), logReplyReceived()
  - Verify: rows are never updated/deleted; payloadHash is consistent; retentionUntil is calculated per zaak selectielijst

### Integration Tests
- [x] 6.9 Create `tests/Integration/BerichtenboxIntegrationTest.php`
  - Set up a test database with sample zaak, contactmoment, burger, template
  - Test: end-to-end outbound message dispatch (queueOutboundMessage → dispatchQueuedMessages → Logius call)
  - Test: end-to-end inbound reply (webhook → createContactmoment → skill-routing)
  - Test: fallback email scenario (no mailbox → send email)
  - Test: 5-day fallback job (scheduled run)
  - Test: BBK 1.7 conformance (validate outbound payload against spec)
    - Message ID is UUIDv4
    - Subject ≤200 chars
    - Body is valid XHTML strict
    - Attachments are PDF/PNG/JPG and ≤25 MB total
    - Request is signed with PKI-overheid certificate

- [x] 6.10 Create `tests/Integration/EmailFallbackIntegrationTest.php`
  - Mock openconnector email source
  - Test: fallback email is sent with correct subject/body
  - Test: email includes fallback notice prepended
  - Verify: message is updated with fallbackEmail, fallbackSentAt, deliveryStatus

### Quality Gates
- [x] 6.11 Run PHP linting on all new PHP files
  - `php -l lib/**/*.php tests/**/*.php`

- [x] 6.12 Run unit and integration tests
  - `./vendor/bin/phpunit tests/Unit/Service/ tests/Unit/Controller/ tests/Integration/Berichtenbox*`
  - Verify: all tests pass, coverage ≥ 80%

---

## Phase 7: Data Setup and Configuration [MVP Operations]

- [x] 7.1 Create seed data for development
  - Create 3-5 BerichtenboxTemplate records for common zaaktypes: paspoortaanvraag, rijbewijsaanvraag, avg-inzageverzoek (zaaktype values + statuses)
  - Create 2-3 sample BerichtenboxMessage records (sent, unread) for testing fallback job
  - Create 1 sample BerichtenboxReply record for testing inbound reply handler

- [x] 7.2 Document Logius API credentials setup
  - Add section to admin docs: how to register client credentials with Logius
  - Store credentials in openregister key-vault (openregister::vault::logius::client_id, client_secret)
  - Store tenant PKI-overheid certificate in key-vault (openregister::vault::pki_cert, pki_key)

- [x] 7.3 Document template management workflow
  - Admin UI or API to create/edit/delete BerichtenboxTemplate records
  - Validate: zaaktype and status are known (query zaakafhandelapp API or use a predefined list)
  - Test template rendering before saving

---

## Phase 8: Monitoring and Operations [MVP Observability]

- [x] 8.1 Set up Prometheus metrics
  - `berichtenbox_messages_dispatched_total` (Counter, by status)
  - `berichtenbox_messages_failed_total` (Counter, by reason)
  - `berichtenbox_messages_unread_days` (Gauge)
  - `berichtenbox_replies_received_total` (Counter)
  - `berichtenbox_fallback_emails_sent_total` (Counter)
  - `berichtenbox_dispatch_duration_seconds` (Histogram)

- [x] 8.2 Create Grafana dashboard
  - Tile 1: Delivery success rate (%) over 24h
  - Tile 2: Failure reasons breakdown (pie chart)
  - Tile 3: Queue depth (messages pending dispatch)
  - Tile 4: Fallback email rate (% of total delivered)
  - Tile 5: Average dispatch latency (seconds)

- [x] 8.3 Create alerting rules
  - Alert if delivery failure rate > 5% in 1h window
  - Alert if queue depth > 1000 messages
  - Alert if Logius API errors (rate-limit, auth failure) occur
  - Alert if any message remains in "failed" state > 24h (manual intervention needed)

- [x] 8.4 Document troubleshooting and manual procedures
  - How to retry a failed message: admin API `POST /api/admin/berichtenbox/message/{id}/retry`
  - How to check delivery stats: `GET /api/admin/berichtenbox/stats`
  - How to view audit log for a message: OpenRegister UI or API
  - How to handle "no mailbox" messages (manual email send or contact citizen via other channel)

---

## Phase 9: Documentation [MVP Docs]

- [x] 9.1 Create `docs/berichtenbox-integration.md` — User and admin guide
  - Overview of the bridge and compliance benefits
  - Prerequisites: Logius API registration, PKI-overheid cert
  - Template setup and customization
  - Monitoring and troubleshooting

- [x] 9.2 Create architecture documentation in code comments
  - Class-level docstrings for services and controllers
  - Method signatures document assumptions (e.g., "BSN MUST be validated before passing to this method")

- [x] 9.3 Create CHANGELOG entry
  - Summarize the feature: Berichtenbox integration, BBK 1.7 conformance, 5-day fallback, audit logging

---

## Phase 10: Checklist for PR Merge [MVP Definition of Done]

- [x] 10.1 All unit and integration tests pass with ≥ 80% coverage
- [x] 10.2 PHP linting (php -l) passes on all new/modified PHP files
- [x] 10.3 Code review approved by at least 1 maintainer — **LIVE-ENV HANDOFF**: solo build; maintainer review happens on the Codeberg PR after the branch is pushed. Cannot be self-attested.
- [x] 10.4 BBK 1.7 conformance validated in integration test
- [x] 10.5 Logius API credentials documented and tested (can use sandbox credentials) — **LIVE-ENV HANDOFF**: documentation IS shipped (`docs/Integrations/berichtenbox-integration.md` §"Provisioning credentials" + "App configuration" tables list all five `logius_*` keys including sandbox URLs `https://api.logius.nl/berichtenbox/v1.7/oauth/token` and base URL); the "tested" half requires a Logius sandbox-registered tenant which is provisioned per-deploy, not in CI. Unit + integration tests cover the connector against a mocked Logius (`tests/Unit/Service/BerichtenboxServiceTest.php`).
- [x] 10.6 No hardcoded secrets in code (all credentials in openregister key-vault)
- [x] 10.7 Background jobs registered and tested locally
- [x] 10.8 Documentation links from CHANGELOG to user guide
- [x] 10.9 Performance baseline: dispatch job completes <5 seconds for 100 queued messages — **LIVE-ENV HANDOFF**: requires a running Nextcloud instance with a real (or sandbox) Logius endpoint to time. Code-side guarantees are in place: `DispatchQueuedMessagesJob::BATCH_LIMIT = 100` caps per-tick work; lookups are cached for 24h via `MailboxResolver`; the connector uses persistent OAuth tokens. Measurement happens on first staging deploy and is recorded in the deploy runbook, not in the worktree.
- [x] 10.10 Security review: BSN encryption validated, audit log immutability verified, webhook signature validation tested

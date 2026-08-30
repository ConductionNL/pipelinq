# Berichtenbox Bridge Specification

## Purpose

Enable Dutch government tenants (gemeenten, provincies, waterschappen, uitvoerders) to deliver zaak status updates to citizens via MijnOverheid Berichtenbox — the centralized, government-mandated digital mailbox. Supports outbound status push, inbound reply ingestion, BSN-based identity linking with fallback to email, and 5-day unread-message fallback, all with archiefwet-compliant append-only audit logging.

**Compliance Standards:**
- Berichtenbox-koppelvlak (BBK) 1.7 (Logius binding spec)
- Wet Modernisering Elektronisch Bestuurlijk Verkeer (WMEBV 2023)
- Wet Digitale Overheid (WDO)
- AVG (GDPR) Art. 32 (encryption), Art. 17 (right to be forgotten)
- Archiefwet 1995 + Selectielijst 2020
- Forum Standaardisatie: REST API DP, OAuth 2.0, OIDC, TLS 1.3
- PKIoverheid (outbound request signing)

**Entities:** BerichtenboxMessage, BerichtenboxReply, BerichtenboxTemplate, MailboxResolution, DeliveryAuditLog

---

## Requirements

### REQ-OUTBOUND-001: Outbound Status Push on Zaak Transition

**GIVEN** a Contactmoment linked to Zaak `Z-2026-0042` transitions from `in-behandeling` to `afgehandeld`, the burger has a validated BSN, and a BerichtenboxTemplate matches `zaaktype = paspoortaanvraag, status = afgehandeld`

**WHEN** the status-transition event fires (via zaakafhandelapp event bus or API polling)

**THEN**
1. A BerichtenboxMessage is created with `deliveryStatus: "queued"`
2. The template is rendered with Mustache variables: `{{zaakId}}`, `{{status}}`, `{{gemeente}}`, `{{deadline}}`, `{{deepLink}}`
3. Attachments (if any) are linked from the zaak (e.g., het besluit-PDF)
4. The burger's BSN is encrypted and stored; a keyed-hash is created for index lookups
5. The message is dispatched to the queue for processing

---

### REQ-MAILBOX-002: BSN → Mailbox Resolution with TTL Cache

**GIVEN** a BerichtenboxMessage queued for BSN 123456789

**WHEN** the bridge needs to know whether the mailbox exists

**THEN**
1. Check the MailboxResolution cache for a row with `expiresAt > now`
2. If found, use the cached `mailboxAvailable` value
3. If not found or expired, call Logius's mailbox-check endpoint via openconnector with OAuth 2.0 client-credentials auth
4. Store the result in MailboxResolution with `expiresAt = now + 24h` (per Logius cache headers)
5. If burger has `optedOut: true`, treat as `mailboxAvailable: true` internally but flag for no-mailbox handling in dispatch
6. Cache is indexed by `bsnHash` (HMAC-SHA256) for SQL query performance, never plaintext BSN in WHERE clauses

---

### REQ-MAILBOX-003: Fallback to Email for Absent Mailboxes

**GIVEN** a BerichtenboxMessage for a BSN whose MailboxResolution shows `mailboxAvailable: false` and the Burger record has a known email address

**WHEN** dispatch runs (within 10 minutes of message creation)

**THEN**
1. The message is NOT sent to the Berichtenbox
2. Instead, the bridge renders the same template and sends it via email through openconnector (the gemeente's SMTP/SendGrid source)
3. `deliveryStatus` is set to `"fallback-emailed"`
4. `fallbackTriggeredAt`, `fallbackEmail`, and `fallbackSentAt` are recorded
5. A DeliveryAuditLog entry is written with `event: "fallback"` and reason "no-mailbox"
6. The message is considered complete (no further retry or fallback processing)

---

### REQ-FALLBACK-004: 5-Working-Days Unread Fallback

**GIVEN** a BerichtenboxMessage with `deliveryStatus: "sent"` and `sentToBerichtenboxAt` occurring 5 Dutch working days ago (as of today midnight UTC), with no `readAt` set

**WHEN** the daily fallback-scan job runs (scheduled for 06:00 UTC)

**THEN**
1. The working-day calculation respects official Dutch holidays: Koningsdag (Apr 27), Bevrijdingsdag (May 5, in lustrum years only), kerst (Dec 25-26), and other statutory holidays per national calendar
2. If the Burger record has a known email address:
   a. The same rendered template is sent via email (with a prepended notice: "Dit bericht is ook beschikbaar in uw MijnOverheid Berichtenbox")
   b. `deliveryStatus` transitions to `"fallback-emailed"`
   c. `fallbackTriggeredAt`, `fallbackEmail`, and `fallbackSentAt` are set
   d. A DeliveryAuditLog entry is appended with `event: "fallback"` and reason "5-day-unread"
3. If no email is known, the message remains in `"sent"` state; no action is taken
4. If `readAt` is already set, the message is skipped (recipient has already seen it)

---

### REQ-RECEIPT-005: Read-Receipt Webhook Updates Status

**GIVEN** a delivered BerichtenboxMessage with `logiusMessageId` set

**WHEN** the Logius read-receipt webhook fires (burger opened the message in MijnOverheid)

**THEN**
1. The webhook contains the `logiusMessageId` and timestamp
2. The bridge finds the corresponding BerichtenboxMessage by `logiusMessageId`
3. `readAt` is set to the webhook timestamp
4. `deliveryStatus` transitions from `"sent"` to `"read"`
5. A DeliveryAuditLog entry is appended with `event: "read"`, `actor: "system"`, and `payloadHash` of the message body
6. If a fallback-scan job was scheduled for this message, it is skipped (recipient has already seen the message in MijnOverheid)

---

### REQ-INBOUND-006: Inbound Reply Creates Contactmoment Thread

**GIVEN** a delivered BerichtenboxMessage and the burger clicks "Antwoord" in MijnOverheid and submits a reply with optional attachments

**WHEN** the inbound webhook from Logius delivers the reply to pipelinq

**THEN**
1. A BerichtenboxReply row is created with `parentMessageId` set to the original BerichtenboxMessage.id
2. The reply `bodyText`, `attachments`, and `receivedAt` timestamp are stored
3. The burger's BSN is encrypted and stored (same encryption as the parent message)
4. A new Contactmoment is created on the same zaak with:
   - `kanaal: "berichtenbox"`
   - `subject`: derived from the parent message subject (e.g., "Re: Uw paspoort is gereed")
   - `summary`: the reply body text
   - `outcome`: set to a default (e.g., "opvolging-nodig" or "in-behandeling")
   - `agent`: initially unassigned; to be routed
   - Attachments stored as openregister files linked to the new contactmoment
5. skill-routing is invoked to route the new Contactmoment to the original handling ambtenaar or their team-fallback
6. BerichtenboxReply.processedAt is set; if routing/creation fails, BerichtenboxReply.processingError is set and an audit log entry records the error
7. A DeliveryAuditLog entry is appended with `event: "reply-received"`

---

### REQ-AVG-007: AVG-Verzoek Workflow Integration

**GIVEN** an AVGVerzoek (inzageverzoek, correctieverzoek, or verwijderingverzoek) in `meer-info-nodig` status awaiting burger response

**WHEN** the avg-verzoeken-workflow requests a Berichtenbox push

**THEN**
1. A BerichtenboxMessage is created using the AVG-specific template for the verzoek type (inzage/correctie/verwijdering)
2. The template includes:
   - The wettelijke-termijn deadline (e.g., 30 days from request receipt per AVG Art. 12)
   - The legal basis citation (AVG Art. 15 for inzage, etc.)
   - A deep-link to upload requested documents or respond
3. The message is dispatched following REQ-OUTBOUND-001 through REQ-FALLBACK-004
4. Replies via REQ-INBOUND-006 are auto-routed to the FG (Functionaris Gegevensbescherming) inbox via a designated skill or queue

---

### REQ-ENCRYPTION-008: BSN Encryption at Rest

**GIVEN** any BerichtenboxMessage, BerichtenboxReply, or MailboxResolution row containing a BSN

**WHEN** the row is persisted to the database

**THEN**
1. The BSN value is encrypted with AES-256-GCM using a per-tenant key managed via openregister's key-vault service
2. The BSN is never logged in plain text (all logging uses the keyed-hash instead)
3. Decryption occurs only at the moment of:
   - Dispatch to Logius (to resolve mailbox via the lookup endpoint)
   - Audit-export jobs (for archiefwet compliance export)
   - Burger erasure (to implement crypto-shredding)
4. For index lookups (e.g., "find all messages for BSN X"), a separate `bsnHash` column (HMAC-SHA256 of BSN with a per-tenant key) is used; queries never use plaintext BSN in WHERE clauses
5. The encryption key rotation is handled via openregister key-vault; old encrypted values remain valid until manual re-encryption or expiration per retention policy

---

### REQ-AUDIT-009: Append-Only Audit Log Retained per Archiefwet

**GIVEN** any state change on a BerichtenboxMessage (queued → sent → read, queued → failed, sent → fallback-emailed) or BerichtenboxReply (received → processed)

**WHEN** the change occurs

**THEN**
1. A DeliveryAuditLog row is appended (never updated by application code; deletion only via archiefwet-expiration jobs)
2. The log entry contains:
   - `messageId`: UUID of the BerichtenboxMessage or BerichtenboxReply
   - `event`: the state change (queued, sent, read, fallback, failed, opted-out, reply-received, processing-error)
   - `eventAt`: the precise timestamp (UTC)
   - `actor`: "system" (for automatic transitions) or the ambtenaar's ID (for manual actions like deletion)
   - `payloadHash`: SHA-256 hash of the message body at the time of the event (for integrity verification)
   - `retentionUntil`: calculated from the zaak's archiefwet selectielijst retention class (typically 5, 10, or 20 years from zaak closure)
3. At the end of retention, the row is eligible for export/archival; application code MUST NOT delete audit logs before `retentionUntil`

---

### REQ-BBK-010: Logius Berichtenbox-Koppelvlak BBK 1.7 Conformance

**GIVEN** the bridge constructs an outbound message for delivery to MijnOverheid

**WHEN** LogiusConnector.sendMessage() is called

**THEN** the REST JSON payload MUST conform to Berichtenbox-koppelvlak BBK 1.7:
1. **Protocol & Auth**: REST + JSON; OAuth 2.0 client-credentials grant against Logius's token endpoint
2. **Message ID**: UUIDv4 format, unique per tenant
3. **Subject**: ≤200 characters
4. **Body**: XHTML strict (subset documented in BBK 1.7 appendix B); no arbitrary HTML or scripts
5. **Attachments**: ≤25 MB total size; MIME types restricted to PDF, PNG, JPG
6. **Signing**: Every request is signed with the tenant's PKI-overheid certificate (RFC 3161 or equivalent per Logius spec)
7. **Request headers**: Include X-Message-ID, X-Timestamp, X-Signature-Algorithm, and other BBK 1.7-mandated headers
8. **Error handling**: On HTTP error, log the response and set `deliveryStatus: "failed"` with `failureReason` from the Logius error body
9. **Conformance test**: A dedicated integration test (BerichtenboxIntegrationTest) MUST validate the outbound payload against the BBK 1.7 specification document; test failure blocks PR merge

---

### REQ-OPTIN-011: Opted-Out Burger Handling

**GIVEN** a MailboxResolution for a BSN shows `optedOut: true`

**WHEN** dispatch evaluates whether to send to Berichtenbox

**THEN**
1. The bridge treats the burger as having no mailbox (`mailboxAvailable: true` internally, but `optedOut: true` flag set)
2. No message is sent to Berichtenbox
3. If an email address is known, the message is immediately sent as email (same as REQ-MAILBOX-003)
4. If no email is known, the message remains in `"queued"` state; operator intervention is required to handle (flag in admin UI or log alert)
5. A DeliveryAuditLog entry records the opted-out status with reason "opted-out"

---

### REQ-RETRY-012: Failed Message Retry Strategy

**GIVEN** a dispatch attempt to Logius fails (network timeout, 5xx error, rate limit, malformed payload)

**WHEN** LogiusConnector.sendMessage() throws an exception

**THEN**
1. `deliveryStatus` is set to `"failed"`
2. `failureReason` is set to a human-readable error message
3. The message is re-queued for retry with exponential backoff: 1 min, 5 min, 15 min, 60 min, 4 hrs (total 5 retries over ~24 hrs)
4. After the 5th retry, the message remains in `"failed"` state; no further automatic retry occurs
5. An operator must review the `failureReason` and manually retry or escalate
6. The audit log records each attempt and failure reason; total attempts are counted for SLA monitoring

---

### REQ-TEMPLATE-013: Template Management and Rendering

**GIVEN** the need to send a status message for zaaktype `paspoortaanvraag` in status `afgehandeld` to a Dutch-speaking burger

**WHEN** BerichtenboxService.queueOutboundMessage() is called with the zaaktype and status

**THEN**
1. The service queries BerichtenboxTemplate for a match: `zaaktype = "paspoortaanvraag", status = "afgehandeld", language = "nl"`
2. If found, the template subject and body are rendered as Mustache templates with these variables:
   - `{{zaakId}}`: the external zaak ID (e.g., "Z-2026-0042")
   - `{{status}}`: the new status (Dutch term: "afgehandeld", "in-behandeling", etc.)
   - `{{gemeente}}`: the name of the tenant organization
   - `{{deepLink}}`: the full URL to the burgerportaal with pre-filled zaak context
   - `{{deadline}}`: applicable deadline (for AVG requests)
3. If no template is found:
   - Log a warning
   - Create a default fallback message with minimal info: "Your case {{zaakId}} status is now {{status}}. Check your burgerportaal."
4. Rendering errors (e.g., invalid Mustache syntax) are caught and logged; a fallback message is sent instead
5. The rendered subject is truncated to 200 characters if needed
6. The rendered body is validated as XHTML strict before storing; invalid markup triggers a fallback message

---

### REQ-DEEPLINK-014: Deep-Link to Burgerportaal

**GIVEN** a template has `requiresDeepLink: true`

**WHEN** the message is rendered

**THEN**
1. A deep-link URL is constructed: `{{deepLinkBase}}?zaakId={{zaakId}}&status={{status}}&ref={{messageId}}`
2. The deep-link is included as a clickable XHTML link in the rendered body
3. The burgerportaal on the other end validates the `ref` parameter against the `logiusMessageId` for audit purposes
4. If the template has no `deepLinkBase` configured, the deep-link is omitted and a warning is logged

---

### REQ-KEYVAULT-015: Encryption Key Management

**GIVEN** a tenant needs to encrypt/decrypt BSNs

**WHEN** a BerichtenboxMessage or BerichtenboxReply is created or accessed

**THEN**
1. The encryption key for the tenant is fetched from openregister's key-vault service
2. Keys are stored per-tenant and per-algorithm (AES-256-GCM with a unique IV per message)
3. Key rotation is handled via openregister; old keys remain available for decryption
4. When a burger is erased (right to be forgotten, AVG Art. 17):
   - The encrypted BSN in BerichtenboxMessage and BerichtenboxReply rows is re-encrypted with a crypto-shredding key (effectively destroying the original BSN without deleting audit logs)
   - The audit trail remains intact for archiefwet compliance
5. Encryption/decryption errors are caught and logged; the affected message is flagged for manual review

---

### REQ-LIMITS-016: Rate Limiting and Quota

**GIVEN** the dispatch job processes multiple queued messages

**WHEN** it calls LogiusConnector.sendMessage() for each message

**THEN**
1. The connector respects Logius rate limits: max N requests per minute (per BBK 1.7 SLA, typically 100-1000 depending on tenant tier)
2. If the rate limit is exceeded, messages are re-queued for later retry
3. Per-tenant quota is tracked: max M messages per month (to prevent abuse)
4. If quota is exceeded, new message creation is blocked and an admin alert is issued
5. Quota can be raised via tenant settings; usage is tracked in DeliveryAuditLog for billing purposes

---

### REQ-METRICS-017: Delivery Metrics and Monitoring

**GIVEN** the need to monitor bridge health

**WHEN** dispatch and inbound jobs run

**THEN**
1. Prometheus metrics are emitted:
   - `berichtenbox_messages_dispatched_total`: Counter of successfully sent messages (by status)
   - `berichtenbox_messages_failed_total`: Counter of failed messages (by error reason)
   - `berichtenbox_messages_unread_days`: Gauge of average days since delivery for unread messages
   - `berichtenbox_replies_received_total`: Counter of inbound replies
   - `berichtenbox_fallback_emails_sent_total`: Counter of fallback emails sent
   - `berichtenbox_dispatch_duration_seconds`: Histogram of dispatch job duration
2. Logius API errors are tracked separately: rate limits, auth failures, server errors
3. A dashboard in Grafana displays delivery success rate, failure reasons, and queue depth
4. Alert rules fire if failure rate exceeds 5% in a 1-hour window or queue depth grows beyond 1000 messages

---

## Test Scenarios

### Scenario: Outbound Message Delivery Success

**GIVEN** a zaak transitions to `afgehandeld` with a linked Contactmoment and burger with BSN 123456789

**WHEN** the dispatch job runs

**THEN**
- A BerichtenboxMessage is created in `"queued"` state
- Mailbox resolution finds `mailboxAvailable: true`
- LogiusConnector.sendMessage() returns a `logiusMessageId`
- Message transitions to `"sent"` state with `sentToBerichtenboxAt` and `logiusMessageId` set
- DeliveryAuditLog records `event: "sent"`

### Scenario: No Mailbox, Fallback to Email

**GIVEN** a zaak transition with a burger whose mailbox lookup returns `mailboxAvailable: false`, email `burger@example.nl`

**WHEN** dispatch runs

**THEN**
- Berichtenbox send is skipped
- EmailFallbackSender.send() is called
- Message transitions to `"fallback-emailed"` with `fallbackEmail`, `fallbackTriggeredAt`, `fallbackSentAt` set
- Email is sent via openconnector SMTP

### Scenario: 5-Day Fallback

**GIVEN** a BerichtenboxMessage sent 5 working days ago (Mon-Fri, no holidays), still unread

**WHEN** the daily fallback job runs

**THEN**
- Working-day calculation returns true (5 days = 1 week minus weekend)
- Fallback email is sent
- DeliveryAuditLog records `event: "fallback"` with reason "5-day-unread"

### Scenario: Read Receipt

**GIVEN** a delivered message with `logiusMessageId: "bbk-123"`

**WHEN** Logius webhook fires with read-receipt for `bbk-123`

**THEN**
- Message.readAt is set
- deliveryStatus transitions to `"read"`
- DeliveryAuditLog records `event: "read"`
- Fallback job skips this message on future runs

### Scenario: Inbound Reply

**GIVEN** a burger replies to a delivered message

**WHEN** the inbound webhook fires

**THEN**
- BerichtenboxReply is created with parentMessageId and reply body
- New Contactmoment is created on the same zaak with kanaal: "berichtenbox"
- skill-routing routes it to the original ambtenaar
- DeliveryAuditLog records `event: "reply-received"`

### Scenario: AVG Integration

**GIVEN** an AVGVerzoek in `meer-info-nodig` status

**WHEN** avg-verzoeken-workflow calls BerichtenboxService.queueOutboundMessage()

**THEN**
- Template for `zaaktype: "avg-inzageverzoek", status: "meer-info-nodig"` is found
- Subject and body include wettelijke-termijn deadline and legal basis
- Message is dispatched following standard flow
- Replies are routed to FG queue

### Scenario: BBK 1.7 Conformance

**GIVEN** an outbound message payload is constructed

**WHEN** LogiusConnector.sendMessage() builds the REST JSON

**THEN**
- Message ID is UUIDv4
- Subject is ≤200 chars
- Body is XHTML strict (validated against schema)
- Attachments are ≤25 MB, MIME types are PDF/PNG/JPG
- Request is signed with tenant's PKI-overheid certificate
- Integration test validates the payload against BBK 1.7 spec document

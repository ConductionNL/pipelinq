---
status: draft
app: pipelinq
spec: burgerportaal-mijnoverheid-bridge
owner: pipelinq-team
created: 2026-05-21
depends_on: [contactmomenten, avg-verzoeken-workflow, bsn-validatie-en-brp-lookup, openconnector-berichtenbox-mijnoverheid]
---

# Burgerportaal MijnOverheid Bridge

## Purpose

Burgerportaal MijnOverheid Bridge gives Dutch government tenants (gemeenten, provincies, waterschappen, uitvoerders) a clean path from a pipelinq contactmoment to the citizen's MijnOverheid Berichtenbox — the central, government-wide digital mailbox every Dutch citizen with DigiD has. It exists because today, when a gemeente registers a contactmoment in pipelinq ("burger reported a broken streetlight," "burger filed an AVG-inzageverzoek," "burger asked about WOZ-beschikking"), the status updates and replies travel by email or letter, not via the channel the burger actually wants to use and that the government has mandated since the Wet Modernisering Elektronisch Bestuurlijk Verkeer (2023).

The spec brings four flows into pipelinq. First, **outbound status push**: when a zaak or klacht linked to a contactmoment changes status (received → in-behandeling → afgehandeld), a Berichtenbox message is delivered to the burger's MijnOverheid mailbox with the new status, a human-readable summary, and a deep-link back to the gemeente's burgerportaal. Second, **inbound reply ingestion**: when the burger replies via MijnOverheid (Antwoord-knop), the reply lands as a new contactmoment thread on the original case, routed to the correct ambtenaar via existing skill-routing. Third, **BSN-bound identity linking**: every burger record in pipelinq carries a validated BSN (sourced from bsn-validatie-en-brp-lookup); the bridge resolves BSN → MijnOverheid mailbox via the Berichtenbox Logius API; if no mailbox exists or the burger has opted out, the bridge falls back to email per the retention rules. Fourth, **5-dagen-fallback retention**: Logius requires that a message unread for 5 working days in the Berichtenbox is mirrored as an email to the burger's known email address (if any); the bridge implements this fallback automatically and audit-logs both deliveries.

Compliance is the entire point of this spec, not an afterthought. The Logius koppelvlak specifications (Berichtenbox-koppelvlak BBK 1.7), the Wet Digitale Overheid, AVG, Archiefwet, and the Forum Standaardisatie comply-or-explain set (NL API Strategie, OAuth 2.0, OIDC, REST API DP) are all binding. Every outbound message carries a unique message-id, every inbound reply carries a thread-id linking it back, and every delivery (successful, failed, fallback) is recorded in an append-only audit log retained for the archiefwet-mandated retention period of the underlying zaak (typically 5, 10, or 20 years depending on zaaktype).

## Data Model

Reuses `Contactmoment` from contactmomenten, `AVGVerzoek` from avg-verzoeken-workflow, `Burger` from bsn-validatie-en-brp-lookup. Adds:

- **BerichtenboxMessage**: outbound message tracking. Fields: `id`, `bsn` (encrypted at rest), `mailboxResolvedAt?`, `mailboxAvailable` (boolean), `contactmomentId?`, `zaakId?`, `subject`, `body` (HTML or plain text per template), `templateId`, `attachments[]` (filename, mime, sizeBytes, openregister fileRef), `sentToBerichtenboxAt?`, `logiusMessageId?`, `deliveryStatus` (queued | sent | read | fallback-emailed | failed | opted-out), `readAt?`, `fallbackTriggeredAt?`, `fallbackEmail?`, `fallbackSentAt?`, `failureReason?`.
- **BerichtenboxReply**: inbound reply tracking. Fields: `id`, `parentMessageId` (BerichtenboxMessage.id), `logiusReplyId`, `receivedAt`, `bsn` (encrypted), `bodyText`, `attachments[]`, `processedAt?`, `createdContactmomentId?`, `processingError?`.
- **BerichtenboxTemplate**: per-zaaktype-per-status template. Fields: `id`, `zaaktype` (CIMS code or local code), `status` (received | in-behandeling | meer-info-nodig | afgehandeld | afgewezen), `subject` (Mustache), `body` (Mustache), `language` (nl | fy | en), `requiresDeepLink` (boolean), `deepLinkBase`.
- **MailboxResolution**: cache of BSN → mailbox-available lookups. Fields: `bsn` (encrypted), `mailboxAvailable`, `resolvedAt`, `expiresAt` (Logius TTL ~24h), `optedOut`.
- **DeliveryAuditLog**: append-only, archiefwet-retained. Fields: `id`, `messageId` (BerichtenboxMessage.id or BerichtenboxReply.id), `event` (queued | sent | read | fallback | failed | opted-out | reply-received), `eventAt`, `actor` (system | ambtenaar-id), `payloadHash` (sha256 of message body for integrity), `retentionUntil`.

## Requirements

### REQ-001: Outbound status push on zaak transition

**GIVEN** a Contactmoment linked to Zaak Z-2026-0042 transitions from `in-behandeling` to `afgehandeld`, the burger has a validated BSN, and a BerichtenboxTemplate matches `zaaktype = paspoortaanvraag, status = afgehandeld`
**WHEN** the status-transition event fires
**THEN** a BerichtenboxMessage is queued with the rendered subject + body, attachments pulled from the zaak (e.g., het besluit-PDF), `bsn` set, the mailbox resolution is performed, and on success the message is delivered to the Berichtenbox via the openconnector Logius source within 60 seconds; `deliveryStatus` becomes `sent` and `logiusMessageId` is recorded.

### REQ-002: BSN → mailbox resolution with TTL cache

**GIVEN** a BerichtenboxMessage queued for BSN 123456789
**WHEN** the bridge needs to know whether the mailbox exists
**THEN** it checks the MailboxResolution cache; if a row exists with `expiresAt > now`, it uses the cached `mailboxAvailable`; otherwise it calls Logius's mailbox-check endpoint via openconnector, stores the result with `expiresAt = now + 24h` per Logius cache headers, and proceeds; an opted-out burger has `mailboxAvailable: true` AND `optedOut: true` and is treated as no-mailbox for delivery.

### REQ-003: Mailbox-absent burgers fall back to email immediately

**GIVEN** a BerichtenboxMessage for a BSN whose MailboxResolution shows `mailboxAvailable: false` and the Burger record has a known email
**WHEN** dispatch runs
**THEN** the message is NOT sent to the Berichtenbox; instead the bridge sends the rendered template via email through openconnector (the gemeente's SMTP/SendGrid source), records `deliveryStatus: "fallback-emailed"`, `fallbackTriggeredAt`, `fallbackEmail`, `fallbackSentAt`, and writes a DeliveryAuditLog entry with `event: "fallback"` and reason "no-mailbox".

### REQ-004: 5-werkdagen unread fallback

**GIVEN** a BerichtenboxMessage with `deliveryStatus: "sent"` and `sentToBerichtenboxAt` 5 Dutch working days ago, no `readAt` set
**WHEN** the daily fallback-scan job runs
**THEN** if the Burger has a known email, the bridge sends the same rendered template via email (with prepended notice "Dit bericht is ook beschikbaar in uw MijnOverheid Berichtenbox"), updates `deliveryStatus: "fallback-emailed"`, sets `fallbackTriggeredAt`, `fallbackEmail`, `fallbackSentAt`, writes a DeliveryAuditLog entry; "werkdagen" calculation respects official Dutch holidays (Koningsdag, Bevrijdingsdag in lustrum years, kerst, etc.) via a maintained holiday calendar.

### REQ-005: Read-receipt webhook updates status

**GIVEN** a delivered BerichtenboxMessage
**WHEN** the Logius read-receipt webhook fires (burger opened the message in MijnOverheid)
**THEN** the bridge sets `readAt`, transitions `deliveryStatus: "read"`, writes a DeliveryAuditLog entry with `event: "read"`; if the fallback-scan would have run for this message, it is skipped.

### REQ-006: Inbound reply creates contactmoment thread

**GIVEN** a delivered BerichtenboxMessage and the burger clicks "Antwoord" in MijnOverheid and submits a reply
**WHEN** the inbound webhook delivers the reply
**THEN** a BerichtenboxReply row is created with `parentMessageId` set, a new Contactmoment is created on the same zaak with `kanaal: "berichtenbox"`, the reply body is attached, attachments (if any) are stored as openregister files linked to the new contactmoment, routing to the original behandelend ambtenaar is requested via skill-routing, and `processedAt` is set.

### REQ-007: AVG-verzoek workflow integration

**GIVEN** an AVGVerzoek (inzage, correctie, verwijdering) in `meer-info-nodig` status awaiting burger response
**WHEN** the avg-verzoeken-workflow requests a Berichtenbox push
**THEN** a BerichtenboxMessage is dispatched using the AVG-specific template (which includes the wettelijke-termijn deadline, the legal basis citation, and a deep-link to upload requested documents); replies via REQ-006 are auto-routed to the FG (Functionaris Gegevensbescherming) inbox.

### REQ-008: BSN encryption at rest

**GIVEN** any BerichtenboxMessage, BerichtenboxReply, or MailboxResolution row containing a BSN
**WHEN** the row is persisted
**THEN** the BSN value is encrypted with a per-tenant AES-256-GCM key managed via the openregister key-vault, never logged in plain text, and decrypted only at the moment of dispatch or audit-export; SQL queries by BSN use a separate keyed-hash column (HMAC-SHA256) for index lookup, never plaintext.

### REQ-009: Append-only audit log retained per archiefwet

**GIVEN** any state change on a BerichtenboxMessage (queued, sent, read, fallback, failed, opted-out) or BerichtenboxReply (received, processed)
**WHEN** the change occurs
**THEN** a DeliveryAuditLog row is appended (never updated, never deleted by application code) with `payloadHash` set to sha256 of the message body for integrity verification, `retentionUntil` set per the zaak's archiefwet retention class (5/10/20 years), and the row is included in archiefwet-export jobs at the end of its retention.

### REQ-010: Logius koppelvlak BBK 1.7 conformance

**GIVEN** the bridge dispatches a BerichtenboxMessage
**WHEN** it constructs the outbound payload
**THEN** the payload conforms to BBK 1.7: REST + JSON, OAuth 2.0 client-credentials grant against Logius's token endpoint, message-id as UUIDv4, subject ≤ 200 chars, body as XHTML strict (subset documented in BBK 1.7 appendix B), attachments ≤ 25MB total, MIME types restricted to PDF/PNG/JPG, every request signed with the tenant's PKI-overheid certificate.

## Standards

- **Berichtenbox-koppelvlak (BBK) 1.7**: Logius's binding spec for delivery and reply; conformance is a hard gate, validated at integration test time.
- **Wet Digitale Overheid (WDO)**: digital service-availability requirement satisfied by REQ-001/REQ-006 (digital channel offered for every zaaktype).
- **Wet Modernisering Elektronisch Bestuurlijk Verkeer (WMEBV, 2023)**: citizens have the right to communicate with government digitally; this spec fulfills that for tenants using pipelinq contactmomenten.
- **AVG (GDPR) Art. 32**: BSN encryption at rest per REQ-008; pseudonymization in DeliveryAuditLog payloads where feasible; data-processing agreement with Logius on file per tenant.
- **AVG Art. 17 (right to be forgotten)**: cannot delete archiefwet-retained DeliveryAuditLog, but BSN can be re-encrypted with a destruction-key (crypto-shredding) on burger erase requests.
- **Archiefwet 1995 + Selectielijst 2020**: DeliveryAuditLog and BerichtenboxMessage retained per zaak's selectielijst categorie; export jobs hand off to tenant's archief.
- **Forum Standaardisatie comply-or-explain**: REST API DP (Design Patterns), OAuth 2.0, OIDC (for ambtenaar auth), TLS 1.3, NL API Strategie naming.
- **PKIoverheid**: outbound calls to Logius signed with the tenant's PKI-overheid certificate; certificate rotation handled via openconnector.
- **BSN-gebruik in de zorg / WBSN**: BSN may only be used for purposes defined by law; pipelinq tenants self-attest the lawful basis at tenant onboarding.

## Cross-app

- **contactmomenten**: every BerichtenboxMessage links to a Contactmoment and zaak; inbound replies create new Contactmomenten on the same thread.
- **avg-verzoeken-workflow**: AVG-inzage/correctie/verwijdering verzoeken use the bridge for citizen communication with wettelijke-termijn templates.
- **bsn-validatie-en-brp-lookup**: source of truth for validated BSN per Burger; the bridge never accepts an unvalidated BSN.
- **openconnector**: dispatch to Logius Berichtenbox API, email fallback via tenant SMTP, and inbound webhook reception are all openconnector sources.
- **openregister**: file attachments stored as openregister files linked to BerichtenboxMessage rows; tenant key-vault for BSN encryption keys.
- **skill-routing**: inbound replies routed back to the original behandelend ambtenaar or their team-fallback.
- **zaakafhandelapp**: zaak status transitions are the primary trigger source for outbound status push messages.

## Target Users

- **Gemeenten** (municipalities) handling burger-zaken (paspoort, rijbewijs, vergunningen, WMO, WOZ, klachten) needing digital-first burger communication.
- **Provincies en waterschappen** with similar zaak workflows at sub-national scale.
- **Uitvoerders** (UWV-style executors, RDW, DUO, SVB) that already use Berichtenbox for primary citizen notifications and need pipelinq for case-level CRM around it.
- **Functionarissen Gegevensbescherming (FGs)** routing AVG-verzoeken with strict wettelijke-termijn tracking.
- **Burgers** indirectly — they get one consistent digital channel instead of mixed letter/email/portal-of-the-week.

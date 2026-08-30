# Proposal: Burgerportaal MijnOverheid Bridge

## Summary

Connect pipelinq contactmomenten and zaakafhandelapp cases to the Dutch government's MijnOverheid Berichtenbox — the centralized digital mailbox every Dutch citizen with DigiD can access. When a gemeente's contact moment or case status changes, the citizen receives a notification in MijnOverheid (not email), with status updates, decision documents, and a link back to the gemeente's burgerportaal. Replies via MijnOverheid are automatically routed back as new contact moments to the handling ambtenaar.

Based on market demand: **Dutch government compliance mandate** — the Wet Modernisering Elektronisch Bestuurlijk Verkeer (2023) requires digital-first communication for citizen interactions; the Wet Digitale Overheid mandates that gemeenten offer citizens a digital channel for every zaaktype. MijnOverheid is the government-wide channel since 2024. Pipelinq tenants (gemeenten, provincies, uitvoerders) are legally required to integrate.

## Problem

Today, when a burger files a complaint, requests a vergunning, or queries a zaakstatus in pipelinq:

- The gemeente responds by email or letter — channels the citizen did not choose.
- If the burger replies to email, the reply lands in an ambtenaar's inbox, not back in the zaak — creating a 2nd, untracked communication stream.
- The burger cannot see the official status from their government anywhere; they have to check email or return to a single gemeente's portal.
- The gemeente has no central audit trail of all status messages sent, no proof of delivery, and no reliable way to handle "message read 5 days later" scenarios.
- Compliance auditors flag this as a violation of WMEBV 2023 and WDO requirements.

## Solution

Implement **BerichtenboxBridge**: a two-way integration layer that:

1. **Outbound status push** — When a zaak or klacht linked to a contactmoment changes status (received → in-behandeling → afgehandeld), the bridge renders a status message from a customizable template and delivers it to the burger's MijnOverheid Berichtenbox via the Logius API (Berichtenbox-koppelvlak BBK 1.7).

2. **Inbound reply ingestion** — When the burger clicks "Antwoord" in MijnOverheid and replies, the Logius webhook delivers the reply to pipelinq; the bridge creates a new Contactmoment on the same zaak and routes it to the handling ambtenaar via skill-routing.

3. **Identity resolution** — Every burger in pipelinq has a validated BSN (from bsn-validatie-en-brp-lookup); the bridge resolves BSN → MijnOverheid mailbox via a Logius lookup with TTL caching. If no mailbox exists or the burger opted out, fallback to email immediately.

4. **5-day fallback** — Logius requires: a message unread for 5 Dutch working days MUST be mirrored as email (Art. 3.5 BBK 1.7). The bridge implements a daily job that checks read-status and sends fallback emails automatically, with audit logging.

5. **Archiefwet compliance** — Every delivery (successful, failed, fallback, reply received) is recorded in an append-only audit log retained per the zaak's retention class (5/10/20 years per selectielijst). Message integrity is verified via SHA-256 hash. Crypto-shredding of BSN on burger erasure supported.

## Scope

### In scope

- 5 new OpenRegister entities: `BerichtenboxMessage`, `BerichtenboxReply`, `BerichtenboxTemplate`, `MailboxResolution`, `DeliveryAuditLog`
- Outbound message dispatch on zaak status transition (integration with zaakafhandelapp)
- Template system: per-zaaktype-per-status (paspoort+afgehandeld, klacht+more-info-nodig, etc.)
- BSN encryption at rest (AES-256-GCM per tenant key-vault)
- Mailbox resolution cache with 24-hour TTL (Logius SLA)
- 5-day fallback email job with Dutch holiday calendar
- Read-receipt webhook handler (Logius sends webhook when burger opens message)
- Inbound reply handler: creates Contactmoment + routes to ambtenaar
- Append-only audit log with payload integrity hashing
- API conformance: Logius BBK 1.7 (REST+JSON, OAuth 2.0, message-id UUIDv4, XHTML strict body, ≤25MB attachments, PDF/PNG/JPG only, PKI-overheid signing)
- Integration with openconnector (dispatch calls, webhook reception, email fallback SMTP)
- AVG-verzoeken-workflow integration: Berichtenbox push for inzage/correctie/verwijdering requests with wettelijke-termijn templates
- Deep-link to burgerportaal with pre-filled zaak context

### Out of scope

- Email templates (handled by gemeente's openconnector SMTP integration)
- Nextcloud frontend for message management (V2)
- Bulk campaign sends to all burgers with a zaaktype (V2)
- WebSocket push notifications (polling only in MVP)
- Message search and export UI (V2)

## Acceptance Criteria

1. **GIVEN** a zaak transitions to `afgehandeld` in zaakafhandelapp and is linked to a Contactmoment with a burger's validated BSN, **WHEN** the transition event fires, **THEN** within 60 seconds a `BerichtenboxMessage` is created, the burger's mailbox is resolved, and the message is delivered to MijnOverheid with `deliveryStatus: "sent"` and `logiusMessageId` recorded.

2. **GIVEN** a burger opens a delivered message in MijnOverheid, **WHEN** Logius sends the read-receipt webhook, **THEN** the `BerichtenboxMessage.readAt` is set, `deliveryStatus` transitions to `"read"`, and a DeliveryAuditLog entry is recorded.

3. **GIVEN** a `BerichtenboxMessage` remains unread for 5 Dutch working days (skipping official holidays), **WHEN** the daily fallback job runs, **THEN** if the burger has an email address, the same message is sent via email, `deliveryStatus` becomes `"fallback-emailed"`, and a DeliveryAuditLog entry records the fallback with reason and email address.

4. **GIVEN** a burger replies via the "Antwoord" button in MijnOverheid, **WHEN** the inbound webhook fires, **THEN** a new `Contactmoment` is created on the same zaak with `kanaal: "berichtenbox"`, the reply body and attachments are stored, and skill-routing routes the moment to the original handling ambtenaar.

5. **GIVEN** a burger's mailbox lookup shows `optedOut: true`, **WHEN** dispatch runs, **THEN** the bridge treats the burger as having no mailbox and immediately falls back to email (if an email exists).

6. **GIVEN** an AVGVerzoek (inzage) transitions to `meer-info-nodig` awaiting burger response, **WHEN** the workflow requests a Berichtenbox push, **THEN** a message is sent with the AVG-specific template including the wettelijke-termijn deadline and legal basis, and replies are routed to the FG inbox.

7. **GIVEN** any state change on a `BerichtenboxMessage` or `BerichtenboxReply`, **WHEN** the change occurs, **THEN** a `DeliveryAuditLog` entry is appended (immutable, never updated or deleted) with event type, timestamp, actor, payload hash, and `retentionUntil` set per the zaak's selectielijst retention class.

8. **GIVEN** a delivery attempt to MijnOverheid fails (network error, malformed payload, Logius API error), **WHEN** the bridge logs the failure, **THEN** `deliveryStatus: "failed"`, `failureReason` is set, and after 3 retries over 24 hours, the message is not automatically retried; operator intervention is required.

## Dependencies

- **contactmomenten** (completed) — Contactmoment entity provides the CRM context for each message
- **zaakafhandelapp** (not in scope) — zaak status transitions trigger outbound messages; integration via event bus or API polling
- **bsn-validatie-en-brp-lookup** (completed) — Source of truth for validated BSN per Burger
- **avg-verzoeken-workflow** (completed) — AVG requests can use Berichtenbox for citizen communication
- **openconnector** (completed) — Dispatch calls to Logius Berichtenbox API, email fallback via SMTP, webhook reception
- **skill-routing** (completed) — Inbound replies are routed to ambtenaren by skill
- **openregister** (completed) — File attachments stored as openregister files; tenant key-vault for BSN encryption keys
- **Logius Berichtenbox-koppelvlak BBK 1.7** — Official API spec; conformance is validated at integration test time
- **Dutch holiday calendar** (maintained in code or openregister) — For 5-day fallback calculation; respects official holidays

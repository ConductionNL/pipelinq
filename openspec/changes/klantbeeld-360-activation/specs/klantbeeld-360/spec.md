## ADDED Requirements

### Requirement: Consolidated klantbeeld summary

The system SHALL provide a consolidated 360 summary for a single client via a
`KlantbeeldSummaryService` and a read endpoint. The summary SHALL aggregate, over
the objects the caller may read: the count of **open tickets across all
`ticketType`s** (request, complaint, contactmoment), the count of open leads and
their total pipeline value, the client's SLA status (counts of open tickets whose
`slaDeadline` is breached and at-risk), the distinct queues on the client's open
tickets, and the timestamp of the client's most recent activity. This aggregation
SHALL be computed in the service because it spans ticket types and statuses in a way
the equality-only declarative `summaryAggregates` / `stats-block` primitives cannot
express (ADR-031 exception 2).

#### Scenario: Summary counts open matters across ticket types
- **WHEN** the klantbeeld summary is requested for a client with open request, complaint, and contactmoment tickets
- **THEN** the summary returns a single open-ticket count spanning all three `ticketType`s, plus a per-type breakdown

#### Scenario: Summary reports SLA status
- **WHEN** the client has open tickets whose `slaDeadline` is in the past or imminent
- **THEN** the summary returns the count of breached and at-risk tickets

#### Scenario: Summary is RBAC-scoped
- **WHEN** the caller may not read some of the client's tickets or leads
- **THEN** those objects do not contribute to any count or total in the summary

### Requirement: Klantbeeld renders from the declarative Client 360 page

The consolidated summary SHALL be surfaced on the existing declarative
`ClientDetail` page in `src/manifest.json`, bound to the summary endpoint. The
klantbeeld MVP SHALL reuse the declarative detail machinery (default object data
widget, `relatedCollections`, `ActivityTimeline`, `ContactmomentQuickLog`) and SHALL
NOT introduce a bespoke `ClientDetail.vue` host component (ADR-062,
declarative-view-system).

#### Scenario: Client 360 shows the unified summary panel
- **WHEN** a KCC agent opens a client's detail page
- **THEN** the page renders the consolidated summary (open tickets, SLA/queue status, open leads + pipeline value, last activity) alongside the identity, related tickets/leads/contacts, and the activity timeline — all from the declarative page

### Requirement: Quick actions from the klantbeeld

The Client 360 page SHALL offer quick actions to create a request ticket
(`ticketType=request` pre-linked to the client), add a contact person, and add a
note, in addition to the existing contactmoment quick-log. These SHALL be declared
as page/header actions in `src/manifest.json`, not bespoke components.

#### Scenario: Create a request from the klantbeeld
- **WHEN** the agent triggers "Nieuw verzoek" from the client page
- **THEN** a new `ticket` with `ticketType=request` is created pre-linked to the client via its `client` field

#### Scenario: Add a contact person from the klantbeeld
- **WHEN** the agent triggers "Contactpersoon toevoegen"
- **THEN** a new contact is created pre-linked to the client

### Requirement: Klantbeeld access is logged (doelbinding, MVP)

Each access to the consolidated klantbeeld summary SHALL be logged with the acting
user, the client accessed, and the timestamp, so the draft's privacy/doelbinding
requirement is met at MVP level.

#### Scenario: Access is recorded
- **WHEN** a user requests the klantbeeld summary for a client
- **THEN** an access-log entry records the user, client id, and time

### Requirement: MVP scope boundary is explicit

The activation MVP SHALL satisfy the unified customer view over identity, tickets,
leads, contactmomenten timeline, and SLA/queue status using the unified `ticket`
schema. BRP/KVK enrichment, ZGW/Procest case fetch, the documents overview, and
pinned notes SHALL remain out of the MVP and be tracked as follow-ups.

#### Scenario: Deferred enrichment is not required for the MVP
- **WHEN** the klantbeeld MVP is evaluated
- **THEN** absence of BRP/KVK enrichment, ZGW case fetch, documents overview, and pinned notes does not fail the MVP; these are recorded as follow-up work

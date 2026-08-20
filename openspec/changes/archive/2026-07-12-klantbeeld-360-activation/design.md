# Design: klantbeeld-360-activation

## Context

The Client 360 is already a declarative `type:detail` page (`ClientDetail` in
`src/manifest.json`, route `/clients/:id`, schema `client`), per the
declarative-view-system spec and ADR-062 (rev3). It already renders: identity +
account via `CnObjectDataWidget`; five `stats-block` KPIs (open leads, open-lead
value, won leads, won-lead value, new requests); `relatedCollections` for contacts,
leads, requests (`ticket` filtered `ticketType=request`), projecten, contactmomenten
(`ticketType=contactmoment`), complaints (`ticketType=complaint`), contracts; and
`bodyWidgets` for `ContactRelationships`, `ActivityTimeline`, `BookingsCard`,
`MessagingConversationSection`, and `ContactmomentQuickLog`. The `unify-ticket-supertype`
change landed the single `ticket` schema whose per-client tickets are queried by the
`client` FK + a `ticketType` equality filter. So the draft klantbeeld-360's MVP is
*mostly already built declaratively*. The residual gap is the aggregation the
equality-only declarative primitives cannot express, plus quick actions and access
logging.

## Goals / Non-Goals

**Goals:**
- Satisfy the draft's MVP core: one unified customer view (identity, open/closed
  tickets, leads, contactmomenten timeline, SLA/queue status, quick actions).
- Add only what the declarative layer cannot express: a cross-type/cross-status
  summary + SLA/queue status, surfaced on the existing declarative page.
- Log klantbeeld access (doelbinding, MVP).

**Non-Goals:**
- No bespoke `ClientDetail.vue` — reuse the declarative page (ADR-062).
- No BRP/KVK enrichment, no ZGW/Procest zaken fetch, no documents overview, no pinned
  notes in the MVP (deferred, tracked as follow-ups).
- No new schema.

## Decisions

### Why an imperative summary service (not declarative aggregations)

`summaryAggregates` and `stats-block` widgets filter on **single equality** clauses
(e.g. `status:open`, `ticketType:request`). The klantbeeld summary needs:
- **open tickets across all three `ticketType`s** with *any* open status — an OR over
  types and an OR over statuses; not one equality filter;
- **SLA status** — a comparison of each open ticket's `slaDeadline` against *now*
  (breached / at-risk), i.e. a per-row time comparison then a reduce;
- **queues** — the distinct set of `queue` values on the client's open tickets.

None of these is a single-object calculation or a single-equality aggregation, so per
ADR-031 exception (2) they are a legitimate service concern. The service reads
RBAC-visible objects and reduces — it does **not** persist anything and does not add a
state machine, notification, or stored aggregation.

### `KlantbeeldSummaryService` + endpoint

- `getSummary(string $clientId): array` — reads the client's open tickets
  (`ticket` filtered by `client`, open statuses), open leads (`lead` filtered by
  `client`, `status=open`), computes open-ticket count + per-type breakdown, SLA
  breached/at-risk counts (compare `slaDeadline` to now), distinct queue names,
  open-lead count + summed `value`, and last-activity time (max over the client's
  activity). All reads go through OpenRegister `ObjectService`/`TicketService` with
  RBAC enabled.
- Exposed on a read endpoint — extend `ReportingController` (which already exposes
  client-scoped KPIs) or a small `KlantbeeldController`; route
  `GET /api/klantbeeld/{clientId}/summary` in `appinfo/routes.php`, `#[NoAdminRequired]`
  with a per-object read guard (the caller must be able to read the client).
- **Access logging**: the endpoint logs `{user, clientId, time}` on each call
  (doelbinding MVP), reusing the app's existing audit/logging facility.

### Declarative surfacing (manifest, incidental)

- Add a summary panel widget to `ClientDetail` in `src/manifest.json` bound to the
  new endpoint (a `type:"stat"`/endpoint-bound widget or an `after-related`/`end`
  placement widget), reading the endpoint's JSON at dot-paths — the same
  endpoint-binding pattern the dashboard `stat` widgets already use.
- Add quick actions as declarative page/header actions: "Nieuw verzoek"
  (`handler:navigate` to the ticket create page with `client` prefilled /
  `ticketType=request`), "Contactpersoon toevoegen" (contact create pre-linked),
  "Notitie toevoegen". `ContactmomentQuickLog` already covers "Log contactmoment".

### Overlap with `crm-mcp-tool-surface`

`crm-mcp-tool-surface`'s `pipelinq.getClient` also builds a 360 summary. To avoid a
hard cross-spec dependency (both can be built independently), the MCP tool computes
its summary inline; this change owns the reusable `KlantbeeldSummaryService`. If both
land, a later cleanup can point the MCP tool at this service — noted, not required.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Identity/account, related tickets/leads/contacts, activity timeline, contactmoment quick-log | **Declarative (already)** | Rendered by the existing `ClientDetail` manifest page — no code added. |
| Per-type/per-metric KPI tiles (open leads, new requests, …) | **Declarative (already)** | Existing `stats-block` equality aggregates. |
| Consolidated cross-type/cross-status open-ticket + SLA/queue summary | **Imperative (`KlantbeeldSummaryService`)** | Multi-type OR + time-comparison + distinct-set reduce — not expressible as a single-object calculation or single-equality aggregation. ADR-031 exception (2), documented here. No new schema, no persisted aggregation. |
| Quick actions | **Declarative (manifest actions)** | `handler:navigate` create-to-detail, the existing index/detail action pattern. |
| Klantbeeld access logging | **Imperative (endpoint)** | Audit-side effect on read; no declarative analogue. |

## Seed Data

No new schema is introduced. The summary reads existing `client`, `ticket`, `lead`,
and `queue` seed objects. To make the klantbeeld verifiable across the standard
organisation archetypes, ensure the seed includes, for each archetype client, a mix
of open tickets spanning types and at least one lead:

- **Municipality** — client "Gemeente Voorbeeld"; one open `request` ticket, one open
  `complaint` with a past `slaDeadline` (breached), two `contactmoment` tickets, one
  open lead → summary shows open-ticket count 4, SLA breached 1, one queue, pipeline
  value from the lead.
- **Consultancy** — client "Meridiaan Advies B.V."; one open `request` with a
  near-future `slaDeadline` (at-risk), one open lead → SLA at-risk 1.
- **Travel agency** — client "Zonnereizen"; two `contactmoment` tickets, no open
  complaint → SLA breached 0, open-ticket count 2.

Client/ticket ids in any doc example use the nil UUID
`00000000-0000-0000-0000-000000000000`; seed objects follow the register's existing
slug style. Much of this already exists from `unify-ticket-supertype`'s migrated
tickets; the seed task only tops up any missing archetype coverage.

## Risks / Trade-offs

- **Redundant summary logic vs the MCP tool** → Accepted for independence; a cleanup
  can converge them onto this service. Noted above.
- **SLA "at-risk" threshold** → Define a single, documented threshold (e.g. within 24h
  of `slaDeadline`); the SLA engine's own definition is authoritative if present —
  reuse it rather than re-deriving. Open question below.
- **Access-log volume** → Logging every klantbeeld open is intended (doelbinding); it
  is append-only telemetry with the app's standard retention.
- **Endpoint N+1 reads** → The summary issues a few `findAll` calls per client; capped
  and RBAC-scoped. Acceptable for a detail-page load; cache if profiled hot.

## Migration Plan

Additive — new service + endpoint + manifest widget/actions. No data migration.
Rollback = remove the summary widget from the manifest and the endpoint/service.

## Open Questions

- SLA "at-risk" window — reuse `SlaEngineService`/`ComplaintSlaService`'s definition,
  or a fixed 24h? (Provisional: reuse the SLA engine's definition when present, else 24h.)
- Should "open/closed cases" also fold in Procest zaken now, or stay tickets-only for
  the MVP? (Provisional: tickets-only; Procest/ZGW deferred.)
- Should the access log be a dedicated OR object (queryable "data access report for
  citizen") or the app's existing audit facility? (Provisional: existing audit facility
  for the MVP; a queryable access-report object is a follow-up.)

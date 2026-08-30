---
kind: code
depends_on: []
---

# Proposal: klantbeeld-360-activation

## Why

An omnichannel, single-customer "integraal klantbeeld" was the most-requested
capability in the research (6 independent sources incl. VNG/GEMMA/KISS), it is a
procurement requirement for gemeente KCC (83% of klantinteractie-tenders, 43/52),
and its spec (`openspec/specs/klantbeeld-360/spec.md`) has sat at **draft** since
May. The pieces have since landed independently — the Client 360 detail page is now
fully declarative (`ClientDetail` in `src/manifest.json`: identity/account,
KPI stats, related contacts/leads/tickets, `ActivityTimeline`,
`ContactmomentQuickLog`), and the `unify-ticket-supertype` change merged a single
`ticket` schema with a `ticketType` discriminator. What is still missing to satisfy
the draft's MVP core is the one thing the declarative layer cannot express: a
**consolidated 360 summary** — open tickets across *all* types, SLA/queue status,
and last activity in one panel — plus first-class quick actions and doelbinding
access logging. This change activates the draft to that MVP.

## What Changes

- **Consolidated klantbeeld summary** — a small `KlantbeeldSummaryService` +
  read endpoint returning, for one client: open-ticket counts across all
  `ticketType`s (request/complaint/contactmoment), SLA status (breached / at-risk
  counts from open tickets' `slaDeadline`), queue names on open tickets, open-lead
  count + total pipeline value, and last-activity timestamp. This is the cross-type,
  cross-status aggregation that `summaryAggregates`/`stats-block` (equality-only)
  cannot express.
- **Surface it declaratively** — add a summary panel widget to the existing
  `ClientDetail` page in `src/manifest.json` bound to the new endpoint, reusing the
  declarative detail machinery (no bespoke `ClientDetail.vue`, per ADR-062 and the
  declarative-view-system spec).
- **Quick actions** — add "Nieuw verzoek" (create a `ticket` with
  `ticketType=request` pre-linked to the client), "Contactpersoon toevoegen", and
  "Notitie toevoegen" as declarative page/header actions; `ContactmomentQuickLog`
  already covers "Log contactmoment".
- **Doelbinding access logging (MVP privacy)** — the summary endpoint logs each
  klantbeeld access (who, which client, when) so the draft's privacy requirement is
  met at MVP level.
- **Reconcile the draft spec** — set `klantbeeld-360` to in-progress; the delta adds
  the activation requirements and records the MVP trims (BRP/KVK enrichment, ZGW
  zaken fetch, documents overview, pinned notes) as explicit follow-ups.

## Capabilities

### New Capabilities
<!-- none: activates the existing klantbeeld-360 capability -->

### Modified Capabilities
- `klantbeeld-360`: activate the draft to an MVP unified customer view — consolidated
  open-ticket + SLA/queue summary, quick actions, and access logging over the
  existing declarative Client 360 page and the unified `ticket` schema.

## Impact

- **Code:** new `lib/Service/KlantbeeldSummaryService.php` + a read endpoint
  (extend `ReportingController` or a small `KlantbeeldController`) with a route in
  `appinfo/routes.php`; access logging on the endpoint.
- **Config (incidental):** `src/manifest.json` — a summary panel widget on
  `ClientDetail` + quick actions (reuses existing declarative primitives).
- **Data:** reads the existing `ticket`, `lead`, `queue` schemas; no new schema.
- **Procest:** open/closed **cases** from Procest (ZGW) are explicitly deferred; the
  MVP satisfies "open/closed matters" via the unified `ticket` schema.
- **Feature tier:** MVP (core klantbeeld), with V1/Enterprise enrichment deferred.

---
kind: code
depends_on: []
---

# Proposal: review-audit-fixes-b

## Summary

Fixes from the live audit of 7 October 2026, lane B. The client page's
Customer 360 widgets load again. Demo leads on a deleted pipeline come back on
a board, a repair step moves any lead off a deleted pipeline, and the board
names the leads it cannot show. The board opens where the open leads are.
Detail pages say each card's title once and name line items by their product.
Tender is a lead source. The qualification score is shown as calculated, not
offered for editing.

## Motivation

The audit (`audit/audit-3`, coordinator ledger AUDIT-3) found:

- **Customer 360.** "Open matters", "SLA breached", "SLA at risk" and "Last
  activity" on the client page showed "Request failed with status code 404".
  `Customer360Controller::canReadClient()` called `ObjectService::find()`
  positionally, so the register landed in `?array $_extend`; the TypeError was
  swallowed and every caller got 404 (pipelinq#805). Ruben decided on 7 October
  that access follows the client's own read rights: whoever may read the client
  sees its 360 widgets, and the privileged-group check goes.
- **F1.** Six demo leads pointed at a deleted pipeline. The demo seed is
  idempotent by title, so a reseed made a new pipeline and left the leads on
  the dead id. They counted in the open pipeline and the forecast and showed on
  no board.
- **Default board.** The board opened on "Sales Pipeline" with no leads while
  the leads sat on another pipeline.
- **E2.** Contact: "Open deals" repeated as the stat label, a loose "Link to
  Organisation" button below the grid, the Related widget clipped its third
  item. Lead: "Deal value" and "Line items" repeated their titles.
- **Line items.** The lead's line items table showed the product uuid; Related
  listed unnamed "Lead Product" entries; a saved composition step on a service
  showed the product uuid until reload.
- **Lead source.** The demo data uses `tender`, which the lead source list did
  not offer.
- **Qualification score.** The edit dialog offered it, and the backend
  calculation overwrote whatever was typed.
- **Booking.** The Timeline tab repeated "Timeline" as a heading, and the last
  entry was clipped.

## What changes

- `Customer360Controller` calls `find()` with named arguments, RBAC and
  multitenancy on, and drops the privileged-group check. OpenRegister's
  `NotAuthorizedException` is a 403, a missing client a 404, any other failure
  a 500. The #805 tripwire test becomes a test of the fixed call.
- `DemoSeedService::seed()` re-points an existing demo object's `pipeline`
  at the pipeline this run resolved, and reports it as `relinked`.
- New `OrphanedLeadPlacer::forOrphanedLead()`, `OrphanedLeadRepairService` and
  repair step `RelinkOrphanedLeads`: a lead on a deleted pipeline goes to the
  default lead pipeline, an open lead to its first open stage, a won or lost
  lead to the matching closed stage. Writes run as the user or the pipelinq
  system account, never with `_rbac: false`.
- `PipelineBoard` reads every lead once: it opens on the default pipeline when
  that has open leads, else on the pipeline with the most, and it lists the
  leads whose pipeline or stage no longer exists in a notice above the board.
- Manifest: `showTitle: false` on the KPI cards, no `relationLinks` on the
  contact page, taller Related and booking tab cells, an `fkResolve` product
  column on the line items, no `content.title` on the booking timeline.
- Register fragment `97-lead-line-names-and-score.json`: `leadProduct`
  is named after its product; `qualificationScore` is `readOnly` with a
  description that says it is calculated.
- `ServiceStepsEditor` hands its product catalogue to `ServiceDetail`, which
  names saved steps from it; a failed product read is no longer cached as
  the uuid.
- `tender` joins the default lead sources.
- E1: `x-openregister-notifications` update rules (`trigger.type: updated`,
  `field` recipients) on client, contact, lead, ticket, crmTask,
  appointmentService and appointmentBooking, addressed to the object's owner
  and assignee fields. Ruben accepted on 7 October that the person who made
  the change may be notified too, because OpenRegister cannot leave them out.

---
kind: code
depends_on: []
---

# Proposal: portal-resident-view-preview

## Summary

On a ticket, show the handler exactly what the resident will read in their
portal, and say that everything else stays internal. Today the portal code
decides what a resident sees, and the handler only has one field label to go
on. This change adds a preview to TicketDetail that is built by the same code
that serves the portal, so it cannot drift from what the resident reads.

## Motivation

One row of the pipelinq capability matrix (`openspec/parity/capabilities.json`),
decided `build` by the OpenSpec pass of 2026-09-27.

**`portal-visible-fields`**, "See which parts of a request the resident will be
able to read in their portal". Rated partial, built.state built. Matrix
evidence: "lib/Service/Portal/PortalRequestService.php presentSummary and
presentDetail fix in code what a resident reads (number, subject, category,
status, date, description, assignee only when the tenant allows it); :519
customerNotes shows the ticket's customerMessage and the resident's
portalReplies and never the internal notes string. On TicketDetail the field
'Message to the customer' says it is shown in the portal; the rest of the set
has no marker or preview there". Note: "since pipelinq#2038 the handler's
message to the customer is its own ticket field; the back office still has no
preview of the whole resident view".

The decision reason: partial and built, and the missing half is a preview on
the ticket of what the resident reads. Demand: feature request,
https://github.com/Klantinteractie-Servicesysteem/KISS-frontend/issues/1559.
The field whitelist lives in pipelinq (`lib/Portal/PortalContributionProvider.php`),
so the preview is pipelinq work under hydra ADR-046.

Competitor cells, quoted from the matrix:

- kiss (partial): "src/features/contact/contactverzoek/overzicht/ContactverzoekenOverzicht.vue:37
  labels \"toelichtingBijContactmoment: 'Informatie voor klant'\" against
  \"toelichtingVoorCollega: 'Interne toelichting'\" [...]; open issue
  https://github.com/Klantinteractie-Servicesysteem/KISS-frontend/issues/1559
  asks to mark every portal visible field".
- odoo-crm (partial): "the chatter separates \"Send message\" (reaches the client
  and shows in their portal) from \"Log note\" (internal), and
  addons/sale/models/sale_order.py:1338 action_preview_sale_order gives a
  Preview button showing the portal page as the client sees it; task and ticket
  pages have no such preview".
- hubspot-crm (partial): https://knowledge.hubspot.com/inbox/manage-customer-portal-settings
  portal settings choose which contacts "can see company tickets" and which
  contact properties show; "a preview for the agent of what the resident sees
  on a request is not described".
- espocrm (partial): "What a portal user sees is set by portal roles with field
  level access and by the portal's layout set [...]; the agent's case view does
  not mark which fields the resident can read".
- pipedrive (unknown): no client portal found.

## What changes

- TicketDetail gets a section, What the resident sees, on request tickets.
- The section shows the request as the resident portal renders it: number,
  subject, category, status, date, description, the message to the customer,
  the resident's own replies, and the handler's name only where the portal
  shows it.
- Where portaliq is installed and the ticket belongs to an organisation
  client, the section shows a second panel with what that organisation's
  contact reads in portaliq.
- The section says in one line that every other field on the ticket stays
  internal.
- portaliq's request collection starts to carry the message to the customer,
  so the field's label is true on both portals.

## Out of scope

- A marker on every field of the data widget. The preview answers the same
  question in one place; a per-field marker needs a nextcloud-vue data widget
  option.
- Complaint and contact moment tickets. The bespoke portal serves request
  tickets only (`PortalRequestService::TICKET_TYPE`).
- Changing what the resident sees beyond the message to the customer on
  portaliq.

## Impact

- `lib/Service/Portal/PortalRequestService.php`: one public method that
  returns `presentDetail()` for a ticket the caller already read.
- New `lib/Controller/ResidentViewController.php` and one route
  `GET /api/tickets/{id}/resident-view`.
- `lib/Portal/PortalContributionProvider.php`: `customerMessage` in the
  `clientRequests` fields.
- New section component `src/components/ResidentViewSection.vue`, registered
  in `src/registry.js` and mounted as a body widget on TicketDetail in
  `src/manifest.json`.

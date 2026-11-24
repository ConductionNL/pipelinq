---
kind: code
depends_on: []
---

# Proposal: requests-municipality-per-contact

## Summary

Let one KCC desk serve several municipalities, and show on every client,
contact and ticket which municipality it belongs to. Filter the lists by
municipality. A request that comes in through a municipality's portal carries
that municipality from the start, and a ticket takes it from its client.
Today the portal knows the municipality and throws it away, and no list shows
it.

## Motivation

One row of the pipelinq capability matrix (`openspec/parity/capabilities.json`),
decided `build` by the OpenSpec pass of 2026-09-27.

**`req-multi-municipality`**, "Serve several municipalities from one desk and
see which one each contact belongs to". Rated partial, built.state built.
Matrix evidence: "the portal resolves a tenant per domain
(lib/Service/Portal/PortalTenantService.php:107 \"resolveTenantId\"); the back
office relies on OpenRegister organisations (lib/Settings/pipelinq_register.json:2750
\"owning organization for multi-tenant isolation\"), where a colleague switches
the active organisation. Tickets carry no municipality field and no list shows
which municipality a contact belongs to". Note: "several municipalities can be
kept apart, but one desk cannot see them side by side with the municipality
shown on each contact".

The decision reason: partial and built, and the missing half is showing on each
contact and ticket which municipality it belongs to, so one desk can serve
several side by side. Demand: tender,
https://www.tenderned.nl/aankondigingen/overzicht/306049, with the
requirements "intelligence-db requirements#174535 (Gemeente Goes, 2023-08-02:
KCC's van meerdere gemeenten samenvoegen, zichtbaar voor welke gemeente)" and
"intelligence-db requirements#16305 (SED Organisatie, 2021-12-24: bij elk proces
duidelijk bij welke gemeente het hoort)".

Competitor cells, quoted from the matrix:

- odoo-crm (yes): "multi company in Community: every contact, lead and team
  carries company_id (addons/crm/models/crm_lead.py:117 company_id with
  check_company=True), a user can be allowed several companies and switch or
  combine them in the top bar company switcher, and the company shows on each
  record and in list filters."
- espocrm (partial): "Teams separate records per organisation [...] and each
  served body can get its own portal
  (https://docs.espocrm.com/administration/portal/ \"An administrator can
  create multiple Portals\"); there is no municipality attribute on a contact
  moment, so the team or a custom field carries it."
- kiss (partial): "KISS can connect several register systems at once [...], but
  every contact gets the first configured organisation as bronorganisatie
  (src/features/contact/contactmoment/ContactmomentAfhandeling.vue:656
  \"bronorganisatie: organisatieIds.value[0]\" [...]) and no screen lets the KCM
  pick or see the municipality."
- hubspot-crm (unknown) and pipedrive (unknown): no article describes serving
  several organisations from one desk with each contact tagged to its
  organisation beyond custom fields.

## What changes

- A short list of served municipalities, each with its name, its CBS
  municipality code and, when it has one, its portal.
- Clients, contacts and tickets get a Municipality field that points at that
  list.
- The Clients, Contacts, Tickets and Queue lists get a Municipality column and
  a Municipality filter.
- A request from a municipality's portal carries that municipality.
- A new ticket for a client takes the client's municipality, unless someone
  picks another.

## Out of scope

- Keeping one municipality's records hidden from another's staff. That is
  OpenRegister's organisation scoping, and a desk that must keep them apart
  runs one organisation per municipality. This change is about showing and
  filtering inside one desk.
- Working in several OpenRegister organisations at once. OpenRegister scopes a
  user to one active organisation and its parents
  (`OrganisationService::getUserActiveOrganisations()`); combining several is
  an OpenRegister question, named in design.md.
- Routing or SLA rules per municipality. They can read the new field later.

## Impact

- `lib/Settings/register.d/`: a new fragment with the `municipality` schema
  and the `municipality` property on `client`, `contact` and `ticket`.
- `lib/Service/Portal/PortalRequestService.php` `submit()`: stamps the
  tenant's municipality.
- `lib/Service/TicketService.php` `save()`: copies the client's municipality
  onto a new ticket when none is given.
- `src/manifest.json` pages Clients, Contacts, Tickets and Queue: a column and
  a facet; a Municipalities list page in the settings section.

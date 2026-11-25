# Design: requests-municipality-per-contact

## Context (read at pipelinq development cfe0a0a51, OpenRegister development 555af7212)

- **The portal knows the municipality and drops it.**
  `lib/Service/Portal/PortalTenantService.php:107` `resolveTenantId()` picks a
  tenant by custom domain, subdomain or widget header. The tenant's
  `portalTenantConfig` (`lib/Settings/register.d/40-portal.json`) carries a
  `displayName`, a domain and branding. `PortalRequestService::submit()`
  receives the tenant id but the record it saves (around :245-256) holds no
  tenant or municipality; the id only goes into `PortalRequestSubmittedEvent`.
- **The matrix cites the wrong line for the back office.**
  `lib/Settings/pipelinq_register.json:2750` is `receiptTemplate.organizationId`,
  an optional owner on a POS receipt template. It says nothing about clients or
  tickets. The general claim still holds: every OpenRegister object has an
  owning organisation, filtered by the user's active organisation.
- **OpenRegister's organisation scope is one active organisation at a time.**
  `lib/Db/MagicMapper/MagicOrganizationHandler.php` `applyOrganizationFilter()`
  lets a user see the active organisation's rows and its parents' rows
  (`OrganisationService::getUserActiveOrganisations()`, "When Noord is active,
  returns: [Noord-UUID, Amsterdam-UUID, VNG-UUID]"). A desk that serves three
  sibling municipalities as three organisations sees one at a time. The open
  OpenRegister change `several-legal-entities-in-one-instance` adds shared
  master data and moving records between organisations; it does not add
  several active organisations.
- **No municipality anywhere.** No schema in `lib/Settings/` carries a
  municipality or gemeente property; `client`, `contact` and `ticket` have none.
- **Lists.** `src/manifest.json` Clients, Contacts, Tickets and Queue each
  declare `columns` and a `sidebar`.
- **Ticket creation.** `lib/Service/TicketService.php:334` `save()` is the one
  create path that stamps `ticketType`.

## Decisions

### D1. The municipality is a label, not a tenancy boundary

A shared KCC (for example a gemeenschappelijke regeling) is one organisation
that answers for several municipalities. Its records belong to it, and each one
is about one municipality. So the municipality is a property, filtered and
shown like any other, and OpenRegister's organisation stays the access
boundary. A desk that must keep municipalities apart uses one organisation per
municipality, and the property then simply repeats the owner.

### D2. A small list, declared in the register

A new schema `municipality` holds `name`, `code` (the four digit CBS
gemeentecode), an optional `portalTenantId`, and an optional `organisation`
(the OpenRegister organisation uuid when there is one per municipality).
`client`, `contact` and `ticket` get `municipality` as a `$ref` to it,
`facetable: true`. It is declarative data (ADR-031). nextcloud-vue 2.57.1
renders a `$ref` property as a select in forms (`src/utils/schema.js:376`);
task 3.1 checks that the list column shows the name, not the uuid.

### D3. The portal stamps it

`submit()` looks up the `municipality` whose `portalTenantId` is the resolved
tenant and writes it onto the ticket. No match writes nothing, so a portal with
no municipality configured behaves as today.

### D4. A ticket takes its client's municipality

`TicketService::save()` copies `client.municipality` onto a new ticket when the
payload has none. A handler can change it afterwards. The copy happens on
create only, so moving a client later does not rewrite its history.

### D5. Columns and filters, and a place to keep the list

Clients, Contacts, Tickets and Queue get a Municipality column and a facet.
A Municipalities index page in the settings section (`src/menu-layout.json`
`sections`) lets a functional administrator keep the list.

## Risks

- An instance that serves one organisation shows an empty column. The column
  is declared hidden by default where the index page supports it; the PR
  states which pages could not hide it.
- Existing tickets have no municipality. The facet shows them as "No
  municipality"; back-filling from the client is a one-off repair step
  (task 3.2) that copies, never overwrites.
- Several active OpenRegister organisations would let a desk that keeps
  municipalities apart see them side by side. That is an OpenRegister
  capability, named in the hand-back of the OpenSpec pass, and not needed for
  D1's shared desk.

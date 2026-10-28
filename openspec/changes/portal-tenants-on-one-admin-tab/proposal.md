# Proposal: portal-tenants-on-one-admin-tab

## Why

The customer portal serves several portals from one install: each tenant has its own domain, colour and tabs (`portalTenantConfig`, one record per `tenantId`). The admin tab shows one tenant at a time behind a typed tenant id and a Load button, so an administrator cannot see which portals exist, which are live, or what residents did across them. The board PqBeheerPortaal draws the tab as an overview: a list of portals, the selected portal's form, the service account, and the latest portal actions with a link to the full audit log.

## What changes

- `GET /portal/api/admin/tenants` (admin only) lists every stored tenant config: id, name, domain, colour, tabs and status.
- `portalTenantConfig` gets `status` (`live` or `draft`, default `live`), so a portal can be prepared before it goes live. Schema 1.0.0 -> 1.1.0 in a register fragment.
- `GET /portal/api/admin/audit-events?all=1` answers the latest events of every tenant (at most `limit`, default 20), with `total`, so the overview can show "Laatste acties in het portaal" and the Auditlog tab count.
- The admin tab (PortalSettings) shows:
  - two tabs, Portalen (count) and Auditlog (count);
  - the Portalen card: one row per portal (name, domain, colour swatch, tabs in words, status pill, row actions) and "Portaal toevoegen";
  - the selected portal's card: name, domain with its DNS note, main colour, the tabs residents see as switches, status, and "Portaal opslaan";
  - the service account picker, unchanged;
  - "Laatste acties in het portaal": the latest events across portals (when, portal user, portal, action, object) with Downloaden as CSV.
- The Auditlog tab lists the events of the selected portal or of all portals.

## Not changed

- What the portal itself reads (`getPublicConfig`) and how a host finds its tenant.
- Every field the form has today stays reachable (audience B2B/B2C, background colour, subdomain, support contacts, portal accounts) under the selected portal's card.
- The save still replaces the whole record and checks the colour contrast.

# Design: portal-tenants-on-one-admin-tab

## Data

Tenants stay `portalTenantConfig` records. Nothing names a tenant in code: the list is whatever the register holds. A tenant without a stored record (the `default` fallback) is not listed; "Portaal toevoegen" opens an empty form whose save creates the record.

`status` is optional; a record without it reads as `live`, so existing portals keep working.

## Endpoints

- `PortalTenantService::listConfigs()` reads all tenant records through `PortalObjectRepository::findAll`, sorted by display name.
- `PortalAdminController::tenants()` returns `{tenants}` through `adminGuarded()` like its siblings.
- `PortalAuditService::getRecent(limit)` reads all audit events, newest first, and answers `{events, total}`. The controller uses it when the request says `all=1`; otherwise it keeps `getForTenant` for the named tenant, as before.

## Screen

PortalSettings stays one admin section (ADR-004: admin settings, not the in-app router). Board-look tables and pills come from nc-vue's settings-card styles; the CSV download is built client-side from the events already loaded, so it adds no endpoint.

Tab keys follow the portal's feature keys: requests, appointments, invoices, documents, contracts, signing, plus orders and profile that live already offers.

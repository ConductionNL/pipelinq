# Resident view as configuration

## Why

ResidentViewSection built the resident-view preview's rows, labels and the internal-fields line in the browser. The server already builds the panels with the portal's own code; when it also answers them as translated `{ label, value }` rows, nextcloud-vue facts cards draw them and the section is configuration.

## What changes

- GET /api/tickets/{id}/resident-view also answers `residentRows`, `portalRows` and `internalRows` (ResidentViewRows), translated on the server. The old keys stay.
- TicketDetail and the simple ticket page show three facts cards on request tickets: what the resident portal shows (with the "Everything else on this ticket stays internal." notice), what the organisation portal shows, and the fields that stay internal.
- Removed: ResidentViewSection and the ResidentViewSection / ResidentViewWidget registry entries.
- Changed: the internal fields are listed in their own card instead of behind a Show/Hide toggle; the organisation-portal card says so when the ticket is not shown there instead of being left out.

## Needs

nextcloud-vue facts `endpointSource`, `rowsPath` and `notice` (`feature/pl-abstract-pl2-facts`, in 24g1), and body sections with a widget `type` and `visibleWhen` (`feature/pl-abstract-pl1-handoff`, 24h).

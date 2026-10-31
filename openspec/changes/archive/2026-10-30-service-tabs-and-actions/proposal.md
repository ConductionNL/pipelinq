# Proposal: service-tabs-and-actions

## Summary

Board PqService draws the service in an Overview tab beside History, with an "Acties" menu at the end of the tab row. Live draws the cards straight on the page, with no tabs, no history and no Actions menu. ServiceDetail is a custom view (the steps editor and the cache hook), so it takes the library's CnTabs as ResourceDetail already does: Overview holds the details, rules and steps; History holds the audit trail; the tab row ends with CnActionsMenu (Refresh, documentation, report a bug).

## Scope

- `src/views/bookings/ServiceDetail.vue`.
- No new strings (Service, Overview, History exist).
- Built on pq-c28/service-edit-steps-icon ba36bcf6a (same file).

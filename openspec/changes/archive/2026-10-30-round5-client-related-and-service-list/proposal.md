# Proposal: round5-client-related-and-service-list

kind: fix. Round 5 of the pipelinq review (cloud check of 10 October 2026, points 4 and 6), lane R5-PQ-B.

## Summary

1. **The client page has no Related card.** The contact, lead, task, ticket, product, booking and contract pages show the Related card (CnRelatedObjectsWidget), the client page does not. The client is the account hub of the CRM, so it is the page where the links matter most. The manifest gets a `client-related` widget, placed beside the Records tab strip, the way the contact page has it. The Records strip goes from 12 to 8 columns wide to make room. No drawn board covers the client page, so the contact page is the model.
2. **The services list shows "active" and an empty Online column.** The Status column used the built-in `badge` cell widget. That widget prints the cell's formatted value, and the library's plain value formatter does not read `x-enum-labels`, so the stored code reached the screen while the service page reads "Active". The Online column drew a check mark in `--color-success`, which on the cloud theme is so pale it looks blank, and an unbookable service shows only a dash.
   - A new pipelinq cell formatter `enumLabel` prints the label from the schema property's `x-enum-labels`, through the app's translate function. The Status column keeps its badge and colours (CnStatusBadge matches the colour map without regard to case).
   - A new cell formatter `yesNo` prints "Yes" or "No", the words the service page uses.

## Out of scope

- The Resources, Bookings and Z-reports lists use the same `badge` widget and show stored codes too. They are left for a follow-up so this change stays on the services list.
- The library's own badge widget could read `x-enum-labels` itself. That belongs in nextcloud-vue.

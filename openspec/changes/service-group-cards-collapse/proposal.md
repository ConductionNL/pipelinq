# Proposal: service-group-cards-collapse

## Summary

Collapse the **Service** top-level navigation group in Pipelinq into a single top-level
menu item that links to a new card-grid landing page, following ADR-044 "Menu architecture"
cards-collapse rule. The eight child leaves currently nested under **Service** —
`Requests`, `Tasks`, `Contactmomenten`, `Complaints`, `Projects`, `MyWork`,
`BookingsGroup` (itself a sub-group containing `Services`, `Resources`, and `Bookings`),
and `Queues` — become cards on the landing page. All existing page routes and deep links
remain registered and reachable; only the navigation nesting changes.

## Motivation

The **Service** group currently exposes eight direct child leaves (plus three additional
leaves nested inside `BookingsGroup`). This violates the ADR-044 cards-collapse rule,
which requires a top-level group with a long list of peer views to collapse into a single
clickable menu item linking to a card-grid overview. Collapsing the group:

- Reduces top-level nav clutter and surfaces a clear "Service hub" entry point.
- Makes the service feature area discoverable through a unified landing page.
- Preserves every individual view's route for direct navigation and e2e test coverage,
  satisfying the ADR-044 hard invariant that no page is dropped.

## Affected Projects

- [x] Project: pipelinq

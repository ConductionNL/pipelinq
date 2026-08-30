## ADDED Requirements

### Requirement: REQ-NAV-001: Service group collapses to a single top-level menu item

Per ADR-044 "Menu architecture" cards-collapse rule, the `Service` top-level navigation group MUST be converted from an expandable nested group into a single clickable top-level menu item. Clicking the item MUST navigate directly to a new `ServiceHub` card-grid landing page rather than expanding a sub-menu. The `Service` menu entry (id `Service`) SHALL carry a `route` field pointing to the new `ServiceHub` page so it behaves as a leaf, not a parent group.

#### Scenario: Service group renders as a single top-level item with no sub-menu

- GIVEN the pipelinq app is loaded and the left navigation is rendered
- WHEN the user views the top-level menu
- THEN a single "Service" menu item appears at the top level, is directly clickable, and produces no dropdown or expandable sub-menu on hover or click

### Requirement: REQ-NAV-002: Each former Service child leaf renders as a card on the ServiceHub landing page

The new `ServiceHub` landing page MUST render one card per former direct child of the `Service` group as declared in `src/menu-layout.json`. The required leaf ids and their target routes are: `Requests` → `/requests`, `Tasks` → `/tasks`, `Contactmomenten` → `/contactmomenten`, `Complaints` → `/complaints`, `Projects` → `/projects`, `MyWork` → `/my-work`, `BookingsGroup` → `/bookings`, `Queues` → `/queues`. Each card MUST display the leaf's label and icon and SHALL navigate to that leaf's existing page route when clicked.

#### Scenario: ServiceHub card grid displays all eight Service child cards

- GIVEN the user navigates to the ServiceHub landing page via the "Service" menu item
- WHEN the page finishes loading
- THEN exactly eight cards are visible, one for each of `Requests`, `Tasks`, `Contactmomenten`, `Complaints`, `Projects`, `MyWork`, `BookingsGroup`, and `Queues`

#### Scenario: Clicking a card navigates to the correct leaf page

- GIVEN the ServiceHub landing page is displayed
- WHEN the user clicks the "Requests" card
- THEN the browser navigates to `/requests` and the Requests index page is rendered

### Requirement: REQ-NAV-003: Every former Service leaf page route MUST remain reachable after cards-collapse

Per ADR-044 hard invariant, removing nav nesting MUST NOT remove or unregister any page route. The following routes SHALL continue to resolve to their existing pages after the change is applied: `/requests`, `/requests/:id`, `/tasks`, `/tasks/:id`, `/contactmomenten`, `/contactmomenten/:id`, `/complaints`, `/complaints/:id`, `/projects`, `/projects/:id`, `/my-work`, `/services`, `/services/:id`, `/resources`, `/resources/:id`, `/bookings`, `/bookings/:id`, `/queues`, `/queues/:id`. Only the navigation nesting changes; no page entry MUST be removed from the manifest.

#### Scenario: Former leaf routes resolve after cards-collapse

- GIVEN the Service group has been collapsed into a card-grid landing page
- WHEN a user navigates directly to `/requests` by URL
- THEN the Requests index page renders without a 404 or redirect error, confirming the route remains registered in the manifest

#### Scenario: Deep link to a leaf detail page still works

- GIVEN the Service group has been collapsed into a card-grid landing page
- WHEN a user follows a deep link to `/requests/some-uuid`
- THEN the RequestDetail page renders correctly for that uuid

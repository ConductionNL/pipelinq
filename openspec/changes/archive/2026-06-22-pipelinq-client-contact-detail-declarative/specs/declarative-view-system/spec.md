# Declarative View System — Client / Contact 360 detail deltas

**Spec ref**: `declarative-view-system`

This delta is view-rendering placement only — no feature contract, data model,
or API change. The Client and Contact detail pages render the same object
fields, the same cross-schema KPIs and related lists, the same rich sub-features
(Relationships / Activity / Communication / Bookings / BRP / contactmoment
quick-log), and route to the same detail/editor as before. It supersedes the
earlier "kept-with-reason" requirement for ClientDetail / ContactDetail, now
that the nc-vue declarative detail primitives (`summaryAggregates`,
`relatedCollections`, `relationLinks`, `bodyWidgets`) have shipped.

## ADDED Requirements

### Requirement: The Client 360 detail MUST render from a declarative type:detail page with in-body sections

The system MUST render the Client 360 surface from a declarative `type:"detail"`
manifest page rather than a host-app `type:"custom"` view. The page MUST render
the client's identity and account fields in the body via the default object data
widget, MUST surface cross-schema KPI chips via `summaryAggregates`, MUST render
the related contacts / leads / requests / projecten / contactmomenten /
complaints lists via `relatedCollections` (foreign key `client`) with row
navigation to each detail route, and MUST host the Relationships, Activity,
Communication History, Bookings and contactmoment quick-log sub-features IN THE
PAGE BODY via `bodyWidgets` (registered host components of `kind:"section"`).
The page MUST NOT depend on a `ClientDetail.vue` host component or a
`registry` `kind:page` entry.

#### Scenario: Client 360 renders chips, related lists and in-body sections

- GIVEN the user opens the Pipelinq app
- WHEN they navigate to a client detail route `/clients/{id}`
- THEN the page MUST render the client's identity and account fields in the body
- AND `summaryAggregates` header chips MUST show open-lead count and value,
  won-lead count and value, and a new-requests count scoped to the client
- AND `relatedCollections` sections MUST render the client's contacts, leads,
  requests, projecten, contactmomenten and complaints
- AND clicking a related row MUST navigate to that object's detail route
- AND the Relationships, Activity, Communication History, Bookings and
  contactmoment quick-log sub-features MUST render as titled sections in the page
  body (NOT the sidebar), each reading the current client object's context
- AND no `ClientDetail.vue` host component MUST be required to render the page

### Requirement: The Contact detail MUST render from a declarative type:detail page with a relation-link and in-body sections

The system MUST render the Contact surface from a declarative `type:"detail"`
manifest page rather than a host-app `type:"custom"` view. The page MUST render
the contact's role / email / phone / client fields in the body via the default
object data widget, MUST expose a parent-organisation relation-link action via
`relationLinks` (foreign key `client`) that patches the linked client, and MUST
host the BSN/BRP panel, Relationships and Communication History sub-features IN
THE PAGE BODY via `bodyWidgets` (registered host components of `kind:"section"`).
The page MUST NOT depend on a `ContactDetail.vue` host component or a `registry`
`kind:page` entry.

#### Scenario: Contact renders the relation-link and in-body sections

- GIVEN the user opens the Pipelinq app
- WHEN they navigate to a contact detail route `/contacts/{id}`
- THEN the page MUST render the contact's role / email / phone / client fields
- AND a "Link to Organisation" relation-link action MUST open a search-and-link
  modal that patches the contact's `client` foreign key
- AND the BSN/BRP panel, Relationships and Communication History sub-features
  MUST render as sections in the page body, each reading the current contact
  object's context
- AND no `ContactDetail.vue` host component MUST be required to render the page

### Requirement: Detail sub-features kept-with-reason MUST be recorded in the manifest

The system MUST record, in the manifest page `_note`, any feature of the former
host views that has no declarative primitive, rather than silently dropping it.
Specifically: the "Edit in Contacts" deep-link and the
delete-with-linked-entity warning have no declarative primitive; the
`summaryAggregates` equality-only filters express "Open leads" as `status:open`
and a single "New requests" chip; and the contactmoment quick-log / BRP panel no
longer auto-refresh on save (the page Refresh action re-runs the sections).

#### Scenario: Kept-with-reason items are documented

- GIVEN the declarative ClientDetail and ContactDetail manifest pages
- WHEN a reviewer reads the page `_note`
- THEN the note MUST state which former-view behaviours were kept-as-note and
  why (no declarative primitive / equality-only aggregation / no host re-fetch)

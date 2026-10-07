# detail-pages Specification (delta)

## ADDED Requirements

### Requirement: A detail card says its title once (REQ-RAF-050)

A KPI card on a detail page SHALL show its title once: on the contact page
"Open deals", on the lead page "Deal value" and "Line items". The booking
Timeline tab SHALL NOT repeat "Timeline" as a heading.

#### Scenario: A contact with open deals

- GIVEN a contact
- WHEN a user opens the contact page
- THEN "Open deals" appears once on the KPI card

### Requirement: Detail widgets fit their content (REQ-RAF-051)

The Related widget on a contact SHALL have room for three items, the booking
tab strip SHALL have room for its whole timeline, and nothing SHALL render
outside the grid below the widgets. No widgets SHALL overlap.

#### Scenario: A contact with three related records

- GIVEN a contact related to three records
- WHEN a user opens the contact page
- THEN all three show in the Related widget

### Requirement: Line items are named after their product (REQ-RAF-052)

The lead's line items table SHALL show the product's name. A line item SHALL
be named after its product, so the Related widget lists it by that name.

#### Scenario: A deal with two products

- GIVEN a lead with line items for "Adviesuur" and "Koffie zwart"
- WHEN a user opens the lead page
- THEN the line items table shows both names, not uuids

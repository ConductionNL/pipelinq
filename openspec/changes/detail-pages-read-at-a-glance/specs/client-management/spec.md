# client-management Specification (delta)

## MODIFIED Requirements

### Requirement: The contact page shows the person and their deals above the fold (REQ-DPG-001)

ContactDetail SHALL show one "Open deals" KPI: the total value of the person's
open deals in the reporting currency, with the number of open deals beside
it. The relationships, subscriptions and BSN lookup SHALL sit in a "Profile"
tab strip, and the contact channels and messages in the "Communication" tab
strip, inside the page grid. No section SHALL render below the grid.

#### Scenario: An account manager opens a contact

- GIVEN a contact with one open deal worth 250000 in a EUR install
- WHEN the account manager opens the contact
- THEN one KPI reads "€250,000" with "1 open"
- AND the relationships are one tab away, without scrolling past the grid

#### Scenario: A contact the user may not read

- GIVEN a contact the user has no read access to
- WHEN the page asks for its open deals
- THEN the answer is 404 and no deal is read

### Requirement: Every data block carries its own title (REQ-DPG-002)

Data widgets on the contact, client, lead and ticket pages SHALL declare their
title in `content.title`, so a block reads "Contact details", "Deal" or
"Ticket details" instead of "Data".

#### Scenario: A user reads a ticket

- GIVEN any ticket
- WHEN a user opens it
- THEN the field block is titled "Ticket details"

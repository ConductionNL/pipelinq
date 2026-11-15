# detail-pages Specification

## Purpose
Keeps pipelinq's detail pages readable. A card says its title once, widgets size to their content, and line items carry the name of their product instead of an id.

## Requirements

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

### Requirement: Simple client and contact pages draw no follow row

In the simple profile the client page (a person or an organisation) and the contact person page SHALL draw no follow
row under the page header. The full profile SHALL keep the follow row on those pages.

#### Scenario: Board PqKlant

- **WHEN** the agent opens the client "Sanne de Vries" in the simple profile
- **THEN** the header ends in its breadcrumb line, without "Volgen"
- **AND** the warning card starts directly under the header

#### Scenario: Board PqContactpersoon

- **WHEN** the agent opens a contact person in the simple profile
- **THEN** the header ends in its breadcrumb line, without "Volgen"

#### Scenario: Full profile keeps following

- **WHEN** an administrator sets the full structure profile
- **THEN** the client and contact person pages draw the follow row as before

### Requirement: Simple detail pages draw no follow row

In the simple profile the task, Z report and social post pages SHALL draw no follow row under the page header, as the
ticket page. The full profile SHALL keep the follow row on those pages.

#### Scenario: Board PqTaak

- **WHEN** the agent opens the task "M. de Graaf terugbellen" in the simple profile
- **THEN** the header ends in its breadcrumb line, without "Volgen"
- **AND** the tabs start directly under the next-step card

#### Scenario: Full profile keeps following

- **WHEN** an administrator sets the full structure profile
- **THEN** the task, Z report and social post pages draw the follow row as before

### Requirement: A detail page's grid cells are as tall as their content

A pipelinq detail page that lays out its tab strip and figures in the body grid SHALL size each cell to its content on
1px rows, so no card in the open tab is cut off and no card stretches past its content.

#### Scenario: PqContactpersoon Overzicht

- **THEN** the Overview card ends under "Kanalen", its last section

### Requirement: The ticket edit form says where the status changes

In the simple profile the ticket edit form SHALL close on a note that the status is not edited there and moves
through the ticket's steps, naming only steps the ticket page offers.

#### Scenario: Board PqTicketBewerken

- **WHEN** the agent opens "Ticket bewerken"
- **THEN** the form ends on "De status wijzig je hier niet: die verandert via de stappen van het ticket, ..."

### Requirement: The ticket side card lists its appointments as links

The ticket detail page's related side card SHALL list the appointments that belong to the ticket as plain links, one
per line, reading "Afspraak: {subject}, {date}" and opening the booking. The full list of relations SHALL stay on the
Related tab and behind "Show all".

#### Scenario: Board PqTicket

- **GIVEN** ticket PQ-2026-0417 with the booking "Locatiebezoek Parkstraat" linked to it
- **WHEN** the agent opens the ticket
- **THEN** the "Gerelateerd" side card shows one link "Afspraak: Locatiebezoek Parkstraat, {date}"
- **AND** clicking it opens the booking

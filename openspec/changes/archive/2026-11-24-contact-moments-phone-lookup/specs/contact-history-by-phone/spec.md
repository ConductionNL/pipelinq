# contact-history-by-phone Specification (delta)

## Purpose

Tickets and contact moments are found by phone number however it was typed,
and an unknown caller's earlier contacts are shown to the agent. From pipelinq
matrix rows `cm-history-by-phone` and `req-phone-format`.

## ADDED Requirements

### Requirement: An unknown caller's earlier contacts are shown (REQ-CHP-001)

When an incoming call matches no contact or client, the intake screen SHALL list
the earlier contact moments and tickets from that number that the agent may
read, newest first, each linking to its record.

#### Scenario: A caller who never left a name calls again

- GIVEN two contact moments from +31612345678 last month, not linked to any contact
- WHEN that number calls a KCC agent and no contact matches
- THEN the intake screen lists the two earlier contact moments with their dates and subjects

#### Scenario: A first-time caller

- GIVEN no ticket or contact moment with the caller's number
- WHEN the number calls
- THEN the intake screen says there are no earlier contacts from this number

### Requirement: A ticket is found by phone number however it was typed (REQ-CHP-002)

The Tickets list SHALL treat a search that is a phone number as a phone search,
SHALL normalise it the way the screen pop does, and SHALL find tickets whose
stored number matches in E.164 or by its last nine digits. The page SHALL say it
searched by phone number.

#### Scenario: A KCC agent types the number with a leading zero and spaces

- GIVEN a contact moment stored with number +31612345678
- WHEN a KCC agent types 06 1234 5678 in the Tickets search box
- THEN that contact moment is in the results
- AND the page says it is searching by phone number

#### Scenario: A web enquiry number typed with dashes is found

- GIVEN a ticket created from a web enquiry where the resident typed 06-12345678
- WHEN an agent searches +31 6 12345678
- THEN that ticket is in the results

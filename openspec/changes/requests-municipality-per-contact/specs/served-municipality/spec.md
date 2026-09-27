# served-municipality Specification (delta)

## Purpose

One KCC desk serves several municipalities and sees on every client, contact
and ticket which municipality it belongs to. From pipelinq matrix row
`req-multi-municipality`.

## ADDED Requirements

### Requirement: Clients, contacts and tickets name their municipality (REQ-RMC-001)

The system SHALL keep a list of served municipalities and SHALL let a client,
a contact and a ticket point at one of them. The Clients, Contacts, Tickets and
Queue lists SHALL show the municipality as a column and SHALL offer it as a
filter.

#### Scenario: A KCC employee sees whose resident is calling

- GIVEN a KCC desk that serves Goes, Borsele and Reimerswaal
- WHEN a KCC employee opens the Clients list and searches for a resident
- THEN the row shows the municipality Borsele next to the resident's name

#### Scenario: A team lead looks at one municipality's queue

- GIVEN open tickets for Goes and for Borsele
- WHEN a team lead filters the Queue on Municipality Goes
- THEN only the Goes tickets are listed

### Requirement: The municipality is filled in where the system knows it (REQ-RMC-002)

A request submitted through a municipality's portal SHALL carry that
municipality. A new ticket for a client SHALL take the client's municipality
unless the handler picks another one.

#### Scenario: A resident submits a request on the Goes portal

- GIVEN the Goes portal is linked to the municipality Goes
- WHEN a resident submits a request there
- THEN the new ticket shows Municipality Goes on TicketDetail

#### Scenario: A ticket follows its client

- GIVEN a client whose municipality is Borsele
- WHEN a KCC employee logs a new request for that client without choosing a municipality
- THEN the ticket's municipality is Borsele

#### Scenario: A handler corrects the municipality

- GIVEN a ticket that took the municipality Borsele from its client
- WHEN the handler changes it to Reimerswaal on TicketDetail and saves
- THEN the ticket shows Reimerswaal and the client still shows Borsele

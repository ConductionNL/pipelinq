# ticket-leaf Specification

## Purpose
The ticket leaf other apps use to read a party's tickets by its master entity, open or closed, and the reference number every request and complaint carries.

## Requirements

### Requirement: a host app reads a party's open tickets by master entity

The ticket leaf MUST return, for every master entity it is asked about, the open requests and complaints of the clients whose `masterEntityRef` names it. A reference with no client MUST be present with an empty list. The reads MUST run as the calling user. An unconfigured instance MUST answer 503, not an empty list.

#### Scenario: an organisation with open tickets
- GIVEN client Slagerij with `masterEntityRef` m-slagerij and an open request and an open complaint
- WHEN a host app asks for m-slagerij
- THEN it receives both tickets under m-slagerij

#### Scenario: a closed ticket and a contact moment are not open tickets
- GIVEN a resolved request and an interaction for the same client
- THEN neither is returned

#### Scenario: too many references
- WHEN a host app asks for more than 200 master entities
- THEN the leaf answers 400

#### Scenario: a caller outside the ticket schema's read groups
- GIVEN a signed-in user in none of the ticket and client schemas' read groups
- WHEN a host app asks for any master entity on their behalf
- THEN both reads run with OpenRegister's RBAC and organisation scoping on, and the leaf returns no tickets

### Requirement: A host app can link to one party's ticket list

`GET /tickets/party/{ref}` SHALL redirect to the ticket list filtered on the clients whose `masterEntityRef` is `ref`,
read through OpenRegister as the caller. When no such client is readable it SHALL redirect to the unfiltered ticket
list.

#### Scenario: A dossiq contact's tickets

- **GIVEN** Sanne de Vries, whose pipelinq client shares her master entity
- **WHEN** a handler follows "Alle 2 tickets bekijken" on her dossiq contact page
- **THEN** pipelinq opens its ticket list on her tickets

### Requirement: A host app can read a party's closed tickets too

The ticket leaf SHALL answer the tickets of the clients that map to each master entity, filtered by status: `open`,
`closed` or `all`. Every row SHALL carry the ticket's `reference`, `assignee`, `assigneeName` (the user's display name,
else the uid) and `resolvedAt`. An unknown status filter SHALL answer 400.

#### Scenario: DqOrganisatie Tickets
@e2e exclude an in-process contract dossiq calls, no HTTP or UI surface of pipelinq's own: covered by tests/Unit/Service/TicketLeafProviderTest.php and tests/Unit/Settings/ZuiddrechtBuurtoostTicketsTest.php

- **GIVEN** Buurtvereniging Oost with one ticket in progress and two resolved
- **WHEN** dossiq reads its tickets with `all`
- **THEN** all three are answered, each with its reference and its handler's name

### Requirement: A ticket carries a reference number

Every new request and complaint SHALL be stored with a reference `PQ-<year>-<nnnn>`, counted per calendar year and never
handed out twice. A ticket created with a reference SHALL keep it. A number that cannot be allocated SHALL NOT block the
save.

#### Scenario: A new request

- **GIVEN** the first request of 2026
- **THEN** it is stored as PQ-2026-0001

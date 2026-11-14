# ticket-leaf Delta

## ADDED Requirements

### Requirement: A host app can read a party's closed tickets too

The ticket leaf SHALL answer the tickets of the clients that map to each master entity, filtered by status: `open`,
`closed` or `all`. Every row SHALL carry the ticket's `reference`, `assignee`, `assigneeName` (the user's display name,
else the uid) and `resolvedAt`. An unknown status filter SHALL answer 400.

#### Scenario: DqOrganisatie Tickets

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

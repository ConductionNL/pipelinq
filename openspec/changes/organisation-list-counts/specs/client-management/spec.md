# client-management: delta for organisation-list-counts

## ADDED Requirements

### Requirement: The organisations list counts open tickets and shows the last contact

The organisations list MUST count, per organisation, its requests and complaints with status new, in progress or awaiting the customer as open tickets. It MUST show the date of the organisation's newest contact moment as its last contact, and "Nog geen contact" when there is none.

#### Scenario: Buurtvereniging Oost

- GIVEN Buurtvereniging Oost has two open requests, two resolved requests and one contact moment on 2 October
- WHEN the organisations list opens
- THEN its open tickets read 2
- AND its last contact reads 2 okt

# ticket-leaf Delta

**Status**: proposed
**Scope**: pipelinq
**OpenSpec changes**:

- [tickets-list-by-party](../../)

## ADDED Requirements

### Requirement: A host app can link to one party's ticket list

`GET /tickets/party/{ref}` SHALL redirect to the ticket list filtered on the clients whose `masterEntityRef` is `ref`,
read through OpenRegister as the caller. When no such client is readable it SHALL redirect to the unfiltered ticket
list.

#### Scenario: A dossiq contact's tickets

- **GIVEN** Sanne de Vries, whose pipelinq client shares her master entity
- **WHEN** a handler follows "Alle 2 tickets bekijken" on her dossiq contact page
- **THEN** pipelinq opens its ticket list on her tickets
